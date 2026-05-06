<?php
declare(strict_types=1);

final class PasswordResetService
{
    private const TOKEN_TTL_MINUTES = 60;
    private const REQUEST_COOLDOWN_MINUTES = 5;
    private const MAX_REQUESTS_PER_HOUR = 3;

    public static function ensureSchema(): void
    {
        Database::exec(
            "CREATE TABLE IF NOT EXISTS password_resets (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash VARCHAR(255) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_password_resets_user_time (user_id, created_at),
                INDEX idx_password_resets_token (token_hash),
                CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function request(string $email): array
    {
        self::ensureSchema();

        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'errors' => ['email' => 'Ingresa un correo valido.']];
        }

        $generic = [
            'ok' => true,
            'message' => 'Si el correo esta registrado, te enviaremos un enlace para restablecer tu contrasena.',
        ];

        $user = Database::one(
            'SELECT id, name, email, active FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        if (!$user || (int) ($user['active'] ?? 0) !== 1) {
            Auth::audit('password_reset_requested_unknown', 'user', null, ['email_hash' => hash('sha256', $email)]);
            return $generic;
        }

        $rate = self::rateLimit((int) $user['id']);
        if (!$rate['ok']) {
            Auth::audit('password_reset_rate_limited', 'user', (int) $user['id']);
            return $generic;
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_MINUTES * 60);

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            Database::exec(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [(int) $user['id']]
            );
            Database::exec(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
                [(int) $user['id'], $hash, $expiresAt]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[password-reset-request] ' . $e->getMessage());
            return $generic;
        }

        $sent = self::sendMail($user, $token, $expiresAt);
        Auth::audit('password_reset_requested', 'user', (int) $user['id'], ['sent' => $sent]);

        return $generic;
    }

    public static function validateToken(string $token): array
    {
        self::ensureSchema();

        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return ['ok' => false, 'message' => 'El enlace no es valido o ya fue utilizado.'];
        }

        $row = self::tokenRow($token);
        if (!$row) {
            return ['ok' => false, 'message' => 'El enlace no es valido o ya fue utilizado.'];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            self::markUsed((int) $row['id']);
            return ['ok' => false, 'expired' => true, 'message' => 'Tu enlace ha expirado. Solicita uno nuevo para continuar.'];
        }

        return ['ok' => true, 'reset' => $row];
    }

    public static function resetPassword(string $token, string $password, string $confirm): array
    {
        $valid = self::validateToken($token);
        if (!$valid['ok']) {
            return ['ok' => false, 'errors' => ['_' => $valid['message']]];
        }

        $errors = self::passwordErrors($password, $confirm);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $reset = $valid['reset'];
        $pdo = Database::pdo();

        try {
            $pdo->beginTransaction();

            $locked = Database::one(
                'SELECT pr.id, pr.user_id, pr.used_at, pr.expires_at
                 FROM password_resets pr
                 JOIN users u ON u.id = pr.user_id
                 WHERE pr.id = ? AND u.active = 1
                 FOR UPDATE',
                [(int) $reset['id']]
            );

            if (!$locked || $locked['used_at'] !== null || strtotime((string) $locked['expires_at']) < time()) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'errors' => ['_' => 'El enlace no es valido o ya fue utilizado.']];
            }

            Database::exec(
                'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $locked['user_id']]
            );
            Database::exec(
                'UPDATE password_resets SET used_at = NOW() WHERE id = ?',
                [(int) $locked['id']]
            );
            Database::exec(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [(int) $locked['user_id']]
            );

            $pdo->commit();
            Auth::audit('password_reset_completed', 'user', (int) $locked['user_id']);
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[password-reset-complete] ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['_' => 'No pudimos actualizar tu contrasena en este momento. Intenta nuevamente.']];
        }
    }

    private static function tokenRow(string $token): ?array
    {
        return Database::one(
            'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.name, u.email
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND u.active = 1
             LIMIT 1',
            [hash('sha256', strtolower($token))]
        );
    }

    private static function markUsed(int $id): void
    {
        Database::exec('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$id]);
    }

    private static function rateLimit(int $userId): array
    {
        $last = Database::one(
            'SELECT created_at FROM password_resets WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
            [$userId]
        );
        if ($last && strtotime((string) $last['created_at']) > time() - self::REQUEST_COOLDOWN_MINUTES * 60) {
            return ['ok' => false];
        }

        $hour = Database::one(
            'SELECT COUNT(*) AS n FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [$userId]
        );
        if ((int) ($hour['n'] ?? 0) >= self::MAX_REQUESTS_PER_HOUR) {
            return ['ok' => false];
        }

        return ['ok' => true];
    }

    private static function passwordErrors(string $password, string $confirm): array
    {
        $errors = [];
        if (strlen($password) < 8) {
            $errors['password'] = 'La contrasena debe tener minimo 8 caracteres.';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'Incluye al menos 1 mayuscula y 1 numero.';
        }
        if ($password !== $confirm) {
            $errors['password_confirm'] = 'Las contrasenas no coinciden.';
        }
        return $errors;
    }

    private static function sendMail(array $user, string $token, string $expiresAt): bool
    {
        global $CONFIG;

        $resetUrl = url('restablecer-password.php?token=' . urlencode($token));
        $supportEmail = $CONFIG['app']['support_email'] ?? 'contacto@bellanickclinic.com';
        $fromName = $CONFIG['app']['name'] ?? 'BellaNick Clinic';
        $subject = 'Restablece tu contrasena - BellaNick Clinic';
        $safeName = e($user['name'] ?? 'BellaNick');
        $safeUrl = e($resetUrl);
        $safeExpires = e(fmt_dt($expiresAt));

        $html = '<!doctype html><html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#f4f1ee;font-family:Segoe UI,Arial,sans-serif;color:#2b1d1d">'
            . '<div style="padding:28px 12px;background:#f4f1ee">'
            . '<div style="max-width:620px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 10px 28px rgba(42,16,41,.08)">'
            . '<div style="background:linear-gradient(135deg,#d63b93,#a4276c);color:#fff;padding:28px">'
            . '<div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:.9">BellaNick Clinic</div>'
            . '<div style="font-size:24px;line-height:1.25;font-weight:800;margin-top:8px">Restablece tu contrasena</div>'
            . '</div>'
            . '<div style="padding:28px;font-size:15px;line-height:1.7">'
            . '<p style="margin:0 0 14px">Hola <strong>' . $safeName . '</strong>,</p>'
            . '<p style="margin:0 0 20px">Recibimos una solicitud para cambiar la contrasena de tu cuenta. Si fuiste tu, usa el siguiente boton.</p>'
            . '<div style="text-align:center;margin:28px 0"><a href="' . $safeUrl . '" style="display:inline-block;background:#d63b93;color:#fff;padding:12px 24px;border-radius:999px;text-decoration:none;font-weight:800">Crear nueva contrasena</a></div>'
            . '<p style="font-size:13px;color:#6b4860;margin:0 0 10px">Este enlace vence el ' . $safeExpires . '.</p>'
            . '<p style="font-size:13px;color:#6b4860;margin:0">Si no solicitaste este cambio, puedes ignorar este correo con tranquilidad.</p>'
            . '</div>'
            . '<div style="padding:16px 28px;font-size:11.5px;color:#9b6f86;text-align:center;border-top:1px solid #f1e5ec">Por seguridad, este enlace solo puede usarse una vez.</div>'
            . '</div></div></body></html>';

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $supportEmail . '>',
            'Reply-To: ' . $supportEmail,
            'X-Mailer: BellaNickAgenda',
        ];

        try {
            return @mail((string) $user['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log('[password-reset-mail] ' . $e->getMessage());
            return false;
        }
    }
}

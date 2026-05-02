<?php
declare(strict_types=1);

final class EmailVerification
{
    public const TOKEN_TTL_HOURS = 24;
    public const RESEND_COOLDOWN_MINUTES = 5;

    public static function ensureSchema(): void
    {
        if (!self::columnExists('users', 'email_verify_token_hash')) {
            Database::exec('ALTER TABLE users ADD COLUMN email_verify_token_hash CHAR(64) NULL AFTER email_verified');
        }
        if (!self::columnExists('users', 'email_verify_expires_at')) {
            Database::exec('ALTER TABLE users ADD COLUMN email_verify_expires_at DATETIME NULL AFTER email_verify_token_hash');
        }
        if (!self::columnExists('users', 'email_verify_sent_at')) {
            Database::exec('ALTER TABLE users ADD COLUMN email_verify_sent_at DATETIME NULL AFTER email_verify_expires_at');
        }
    }

    public static function issueAndSend(int $userId, bool $force = false): array
    {
        self::ensureSchema();

        $user = Database::one(
            'SELECT id, name, email, email_verified, email_verify_sent_at
             FROM users WHERE id = ? AND active = 1 LIMIT 1',
            [$userId]
        );
        if (!$user) {
            return ['ok' => false, 'message' => 'No fue posible enviar la confirmación.'];
        }
        if ((int) $user['email_verified'] === 1) {
            return ['ok' => true, 'message' => 'Tu correo ya fue confirmado correctamente.'];
        }

        if (!$force && $user['email_verify_sent_at']) {
            $lastSent = strtotime($user['email_verify_sent_at']);
            if ($lastSent && $lastSent > time() - self::RESEND_COOLDOWN_MINUTES * 60) {
                return [
                    'ok' => false,
                    'cooldown' => true,
                    'message' => 'Hace unos minutos enviamos un correo. Revisa tu bandeja antes de solicitar otro.',
                ];
            }
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_HOURS * 3600);

        Database::exec(
            'UPDATE users
             SET email_verify_token_hash = ?, email_verify_expires_at = ?, email_verify_sent_at = NOW()
             WHERE id = ?',
            [$hash, $expiresAt, $userId]
        );

        $sent = self::sendMail($user, $token, $expiresAt);
        Auth::audit('email_verification_send', 'user', $userId, ['sent' => $sent]);

        return [
            'ok' => true,
            'mail_sent' => $sent,
            'message' => $sent
                ? 'Te enviamos un correo para confirmar tu cuenta.'
                : 'Generamos tu enlace de confirmación, pero el correo no pudo enviarse. Contacta a la clínica si vuelve a ocurrir.',
        ];
    }

    public static function confirm(string $token): array
    {
        self::ensureSchema();

        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return ['ok' => false, 'message' => 'El enlace de confirmación no es válido.'];
        }

        $hash = hash('sha256', strtolower($token));
        $user = Database::one(
            'SELECT id, email_verified, email_verify_expires_at
             FROM users
             WHERE email_verify_token_hash = ? AND active = 1
             LIMIT 1',
            [$hash]
        );

        if (!$user) {
            return ['ok' => false, 'message' => 'El enlace de confirmación no es válido o ya fue utilizado.'];
        }
        if ((int) $user['email_verified'] === 1) {
            self::clearToken((int) $user['id']);
            return ['ok' => true, 'message' => 'Tu correo ya fue confirmado correctamente.'];
        }
        if (!$user['email_verify_expires_at'] || strtotime($user['email_verify_expires_at']) < time()) {
            return ['ok' => false, 'expired' => true, 'message' => 'Tu enlace ha expirado, solicita uno nuevo.'];
        }

        Database::exec(
            'UPDATE users
             SET email_verified = 1,
                 email_verify_token_hash = NULL,
                 email_verify_expires_at = NULL,
                 email_verify_sent_at = NULL
             WHERE id = ?',
            [(int) $user['id']]
        );
        if (Auth::check() && (int) ($_SESSION['uid'] ?? 0) === (int) $user['id']) {
            unset($_SESSION['user_cache']);
        }
        Auth::audit('email_verified', 'user', (int) $user['id']);

        return ['ok' => true, 'message' => 'Tu correo fue confirmado correctamente. Ya puedes usar tu cuenta.'];
    }

    public static function clearToken(int $userId): void
    {
        Database::exec(
            'UPDATE users SET email_verify_token_hash = NULL, email_verify_expires_at = NULL, email_verify_sent_at = NULL WHERE id = ?',
            [$userId]
        );
    }

    private static function sendMail(array $user, string $token, string $expiresAt): bool
    {
        global $CONFIG;

        $confirmUrl = url('confirmar-email.php?token=' . urlencode($token));
        $supportEmail = $CONFIG['app']['support_email'] ?? 'contacto@bellanickclinic.com';
        $appName = $CONFIG['app']['name'] ?? 'BellaNick Clinic';
        $subject = 'Confirma tu cuenta BellaNick';

        $body = "Hola {$user['name']},\n\n"
            . "Gracias por crear tu cuenta en BellaNick Clinic.\n\n"
            . "Confirma tu correo dando clic en este enlace:\n{$confirmUrl}\n\n"
            . "Este enlace vence el " . fmt_dt($expiresAt) . ".\n\n"
            . "Si no creaste esta cuenta, puedes ignorar este mensaje.\n\n"
            . "BellaNick Clinic";

        $headers = [
            'From: ' . $appName . ' <' . $supportEmail . '>',
            'Reply-To: ' . $supportEmail,
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        try {
            return mail($user['email'], $subject, $body, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log('[email verification] ' . $e->getMessage());
            return false;
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            return (bool) Database::one(
                'SELECT 1
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1',
                [$table, $column]
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}

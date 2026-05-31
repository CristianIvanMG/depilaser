<?php
declare(strict_types=1);

/**
 * EmailNotificationService
 *
 * Notificaciones HTML profesionales para citas y recordatorios automaticos.
 * Mantiene deduplicacion por cita/tipo y bitacora de intentos para cron.
 */
final class EmailNotificationService
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_AFTER_MINUTES = 15;
    private const SENDING_LOCK_MINUTES = 10;

    private const TYPES = [
        'appointment_created',
        'appointment_confirmed',
        'appointment_status_changed',
        'appointment_cancelled',
        'appointment_no_show',
        'appointment_attended',
        'appointment_reminder_24h',
    ];

    public static function ensureSchema(): void
    {
        self::ensureAppointmentColumn('email_reminder_sent', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::ensureAppointmentColumn('email_reminder_sent_at', 'DATETIME NULL');
        self::ensureAppointmentColumn('email_reminder_attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
        self::ensureAppointmentColumn('email_reminder_last_error', 'VARCHAR(255) NULL');

        Database::exec(
            "CREATE TABLE IF NOT EXISTS appointment_email_logs (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                appointment_id INT UNSIGNED NOT NULL,
                type VARCHAR(60) NOT NULL,
                recipient_email VARCHAR(190) NOT NULL,
                recipient_name VARCHAR(190) NULL,
                subject VARCHAR(190) NOT NULL,
                status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
                attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_attempt_at DATETIME NULL,
                sent_at DATETIME NULL,
                error_message VARCHAR(255) NULL,
                dedupe_key VARCHAR(120) NOT NULL,
                payload JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_email_dedupe (dedupe_key),
                INDEX idx_email_appt_type (appointment_id, type),
                INDEX idx_email_status (status, last_attempt_at),
                CONSTRAINT fk_email_log_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function sendForAppointment(int $appointmentId, string $type, bool $force = false): array
    {
        self::ensureSchema();

        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'skipped' => true, 'error' => 'Tipo de correo no valido.'];
        }

        $d = self::hydrate($appointmentId);
        if (!$d) {
            return ['ok' => false, 'skipped' => true, 'error' => 'Cita no encontrada.'];
        }
        $validation = self::validateSend($d, $type);
        if (!$validation['ok']) {
            self::logSkipped($d, $type, $validation['error']);
            return ['ok' => false, 'skipped' => true, 'error' => $validation['error']];
        }

        $subject = self::subject($d, $type);
        $log = self::reserveSend($d, $type, $subject, $force);
        if (!$log['ok']) {
            return $log;
        }

        $html = self::render($d, $type);
        $sent = self::sendHtml((string) $d['client_email'], (string) $d['client_name'], $subject, $html);

        if ($sent) {
            Database::exec(
                "UPDATE appointment_email_logs
                 SET status = 'sent', sent_at = NOW(), error_message = NULL
                 WHERE id = ?",
                [(int) $log['log_id']]
            );
            if ($type === 'appointment_reminder_24h') {
                Database::exec(
                    "UPDATE appointments
                     SET email_reminder_sent = 1,
                         email_reminder_sent_at = NOW(),
                         email_reminder_last_error = NULL
                     WHERE id = ?",
                    [$appointmentId]
                );
            }
            Auth::audit('appointment_email_sent', 'appointment', $appointmentId, [
                'type' => $type,
                'email' => $d['client_email'],
            ]);
            return ['ok' => true, 'sent' => true, 'type' => $type];
        }

        $error = MailService::lastError() ?: 'No fue posible enviar el correo en este momento.';
        Database::exec(
            "UPDATE appointment_email_logs
             SET status = 'failed', error_message = ?
             WHERE id = ?",
            [$error, (int) $log['log_id']]
        );
        if ($type === 'appointment_reminder_24h') {
            Database::exec(
                "UPDATE appointments
                 SET email_reminder_attempts = email_reminder_attempts + 1,
                     email_reminder_last_error = ?
                 WHERE id = ?",
                [$error, $appointmentId]
            );
        }
        error_log('[appointment-email] appointment=' . $appointmentId . ' type=' . $type . ' ' . $error);
        return ['ok' => false, 'error' => $error];
    }

    /**
     * Envia recordatorios 24h antes.
     * Ventana por defecto: 23h50m a 24h10m, tolerante al minuto exacto de cron.
     */
    public static function runReminderCron(int $windowMinutes = 10, int $limit = 100): array
    {
        self::ensureSchema();

        $windowMinutes = max(1, min(60, $windowMinutes));
        $limit = max(1, min(500, $limit));
        $from = date('Y-m-d H:i:s', time() + (24 * 3600) - ($windowMinutes * 60));
        $to = date('Y-m-d H:i:s', time() + (24 * 3600) + ($windowMinutes * 60));

        $rows = Database::all(
            "SELECT a.id
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN users u ON u.id = a.user_id
             WHERE st.slug IN ('programada','confirmada')
               AND a.email_reminder_sent = 0
               AND a.start_at BETWEEN ? AND ?
               AND u.active = 1
               AND COALESCE(u.email_verified, 0) = 1
             ORDER BY a.start_at ASC
             LIMIT {$limit}",
            [$from, $to]
        );

        $summary = [
            'ok' => true,
            'checked' => count($rows),
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'items' => [],
        ];

        foreach ($rows as $row) {
            try {
                $result = self::sendForAppointment((int) $row['id'], 'appointment_reminder_24h');
                if (!empty($result['sent'])) {
                    $summary['sent']++;
                } elseif (!empty($result['skipped']) || !empty($result['duplicate']) || !empty($result['locked'])) {
                    $summary['skipped']++;
                } else {
                    $summary['failed']++;
                }
                $summary['items'][] = ['appointment_id' => (int) $row['id'], 'result' => $result];
            } catch (Throwable $e) {
                $summary['failed']++;
                $summary['items'][] = ['appointment_id' => (int) $row['id'], 'result' => ['ok' => false, 'error' => $e->getMessage()]];
                error_log('[appointment-reminder-cron] appointment=' . (int) $row['id'] . ' ' . $e->getMessage());
            }
        }

        return $summary;
    }

    public static function hydrate(int $appointmentId): ?array
    {
        $row = Database::one(
            "SELECT a.id, a.code, a.start_at, a.end_at, a.cancel_reason,
                    a.email_reminder_sent, a.email_reminder_sent_at,
                    a.payment_required, a.payment_status, a.payment_amount_mxn,
                    u.name AS client_name, u.email AS client_email, u.email_verified, u.active AS client_active,
                    s.name AS service_name, s.duration_min,
                    b.name AS branch_name, b.address AS branch_address, b.city AS branch_city,
                    b.state AS branch_state, b.phone AS branch_phone, b.email AS branch_email,
                    b.gmaps_url,
                    pr.name AS professional_name,
                    st.slug AS status_slug, st.name AS status_name
             FROM appointments a
             JOIN users u ON u.id = a.user_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             JOIN appointment_statuses st ON st.id = a.status_id
             LEFT JOIN users pr ON pr.id = a.professional_id
             WHERE a.id = ? LIMIT 1",
            [$appointmentId]
        );

        return $row ?: null;
    }

    private static function validateSend(array $d, string $type): array
    {
        if (empty($d['client_email'])) {
            return ['ok' => false, 'error' => 'El cliente no tiene correo registrado.'];
        }
        if (!filter_var((string) $d['client_email'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'El correo del cliente no tiene un formato valido.'];
        }
        if ((int) ($d['client_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'La cuenta del cliente no esta activa.'];
        }
        if (empty($d['start_at']) || strtotime((string) $d['start_at']) === false) {
            return ['ok' => false, 'error' => 'La cita no tiene fecha valida.'];
        }

        $status = (string) ($d['status_slug'] ?? '');
        if ($type === 'appointment_reminder_24h') {
            if (!in_array($status, ['programada', 'confirmada'], true)) {
                return ['ok' => false, 'error' => 'El estado de la cita no permite recordatorio.'];
            }
            if ((int) ($d['email_reminder_sent'] ?? 0) === 1) {
                return ['ok' => false, 'error' => 'El recordatorio ya fue enviado.'];
            }
        }
        if ($type === 'appointment_cancelled' && $status !== 'cancelada') {
            return ['ok' => false, 'error' => 'La cita no esta cancelada.'];
        }
        if ($type === 'appointment_no_show' && $status !== 'no_asistio') {
            return ['ok' => false, 'error' => 'La cita no esta marcada como no asistida.'];
        }
        if ($type === 'appointment_attended' && $status !== 'atendida') {
            return ['ok' => false, 'error' => 'La cita no esta atendida.'];
        }
        if ($type === 'appointment_confirmed' && $status !== 'confirmada') {
            return ['ok' => false, 'error' => 'La cita no esta confirmada.'];
        }

        return ['ok' => true];
    }

    private static function reserveSend(array $d, string $type, string $subject, bool $force): array
    {
        $dedupeKey = 'appointment:' . (int) $d['id'] . ':' . $type;
        $pdo = Database::pdo();

        try {
            $pdo->beginTransaction();
            Database::exec(
                "INSERT INTO appointment_email_logs
                    (appointment_id, type, recipient_email, recipient_name, subject, status, dedupe_key, payload)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
                [
                    (int) $d['id'],
                    $type,
                    (string) $d['client_email'],
                    (string) $d['client_name'],
                    $subject,
                    $dedupeKey,
                    json_encode(['status' => $d['status_slug'], 'code' => $d['code']], JSON_UNESCAPED_UNICODE),
                ]
            );
            $logId = Database::lastId();

            $log = Database::one('SELECT * FROM appointment_email_logs WHERE id = ? FOR UPDATE', [$logId]);
            if (!$log) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'No fue posible preparar el envio.'];
            }

            if ($log['status'] === 'sent' && !$force) {
                $pdo->commit();
                return ['ok' => false, 'duplicate' => true, 'error' => 'Este correo ya fue enviado.'];
            }

            $lastAttempt = $log['last_attempt_at'] ? strtotime((string) $log['last_attempt_at']) : null;
            if ($log['status'] === 'sending' && $lastAttempt && $lastAttempt > time() - self::SENDING_LOCK_MINUTES * 60 && !$force) {
                $pdo->commit();
                return ['ok' => false, 'locked' => true, 'error' => 'Ya hay un envio en proceso.'];
            }
            if ($log['status'] === 'failed' && !$force) {
                if ((int) $log['attempt_count'] >= self::MAX_ATTEMPTS) {
                    $pdo->commit();
                    return ['ok' => false, 'error' => 'Se alcanzo el limite de reintentos.'];
                }
                if ($lastAttempt && $lastAttempt > time() - self::RETRY_AFTER_MINUTES * 60) {
                    $pdo->commit();
                    return ['ok' => false, 'locked' => true, 'error' => 'Reintento diferido para evitar duplicados.'];
                }
            }

            Database::exec(
                "UPDATE appointment_email_logs
                 SET status = 'sending',
                     attempt_count = attempt_count + 1,
                     last_attempt_at = NOW(),
                     error_message = NULL,
                     recipient_email = ?,
                     recipient_name = ?,
                     subject = ?
                 WHERE id = ?",
                [(string) $d['client_email'], (string) $d['client_name'], $subject, $logId]
            );
            $pdo->commit();

            return ['ok' => true, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[appointment-email-reserve] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No fue posible preparar el envio.'];
        }
    }

    private static function logSkipped(array $d, string $type, string $reason): void
    {
        try {
            Database::exec(
                "INSERT INTO appointment_email_logs
                    (appointment_id, type, recipient_email, recipient_name, subject, status, error_message, dedupe_key, payload)
                 VALUES (?, ?, ?, ?, ?, 'skipped', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    status = IF(status = 'sent', status, 'skipped'),
                    error_message = IF(status = 'sent', error_message, VALUES(error_message)),
                    updated_at = NOW()",
                [
                    (int) ($d['id'] ?? 0),
                    $type,
                    (string) ($d['client_email'] ?? ''),
                    (string) ($d['client_name'] ?? ''),
                    self::subject($d, $type),
                    $reason,
                    'appointment:' . (int) ($d['id'] ?? 0) . ':' . $type,
                    json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (Throwable $e) {
            error_log('[appointment-email-skip] ' . $e->getMessage());
        }
    }

    private static function subject(array $d, string $type): string
    {
        $service = (string) ($d['service_name'] ?? 'tu cita');
        return match ($type) {
            'appointment_created' => 'Confirmacion de cita - BellaNick Clinic',
            'appointment_confirmed' => 'Tu cita fue confirmada - BellaNick Clinic',
            'appointment_cancelled' => 'Tu cita fue cancelada - BellaNick Clinic',
            'appointment_no_show' => 'Te esperamos en BellaNick Clinic',
            'appointment_attended' => 'Gracias por tu visita - BellaNick Clinic',
            'appointment_reminder_24h' => 'Recordatorio: tu cita es manana - BellaNick Clinic',
            default => 'Actualizacion de tu cita - ' . $service,
        };
    }

    private static function render(array $d, string $type): string
    {
        $title = self::title($type);
        $intro = self::intro($d, $type);
        $tone = in_array($type, ['appointment_cancelled', 'appointment_no_show'], true) ? 'quiet' : 'positive';
        $includeReviews = in_array($type, ['appointment_attended'], true);
        $cta = self::cta($d, $type);
        $H = fn($value) => e($value);

        $professional = $d['professional_name'] ?: 'Equipo BellaNick';
        $branchAddr = trim(($d['branch_address'] ?? '') . ', ' . ($d['branch_city'] ?? '') . ', ' . ($d['branch_state'] ?? ''), ', ');
        $when = fmt_dt((string) $d['start_at']);
        $maps = (string) ($d['gmaps_url'] ?? '');
        $reviewsUrl = self::googleReviewsUrl($d);

        $headerGradient = $tone === 'quiet'
            ? 'linear-gradient(135deg,#7a4a6a,#3d2434)'
            : 'linear-gradient(135deg,#d63b93,#a4276c)';

        $html = '<!DOCTYPE html><html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>' . $H($title) . '</title></head>';
        $html .= '<body style="margin:0;background:#f4f1ee;font-family:Segoe UI,Arial,sans-serif;color:#2b1d1d">';
        $html .= '<div style="display:none;max-height:0;overflow:hidden;color:transparent">' . $H($intro) . '</div>';
        $html .= '<div style="padding:28px 12px;background:#f4f1ee">';
        $html .= '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 10px 28px rgba(42,16,41,.08)">';
        $html .= '<div style="background:' . $headerGradient . ';color:#fff;padding:28px">';
        $html .= '<div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:.9">BellaNick Clinic</div>';
        $html .= '<div style="font-size:24px;line-height:1.25;font-weight:800;margin-top:8px">' . $H($title) . '</div>';
        $html .= '</div>';
        $html .= '<div style="padding:28px;font-size:15px;line-height:1.7">';
        $html .= '<p style="margin:0 0 14px">Hola <strong>' . $H($d['client_name']) . '</strong>,</p>';
        $html .= '<p style="margin:0 0 20px">' . $H($intro) . '</p>';
        $html .= '<table role="presentation" style="width:100%;border-collapse:collapse;background:#fff8fb;border:1px solid #f1d8e7;border-radius:12px;overflow:hidden">';
        $html .= self::row('Servicio', (string) $d['service_name']);
        $html .= self::row('Fecha y hora', $when);
        $html .= self::row('Sucursal', (string) $d['branch_name']);
        $html .= self::row('Profesional', $professional);
        $html .= self::row('Codigo de cita', (string) $d['code']);
        $html .= '</table>';
        if ($branchAddr !== '') {
            $html .= '<p style="font-size:12.5px;color:#6b4860;margin:14px 0 0">' . $H($branchAddr) . '</p>';
        }
        if ($maps !== '' && !in_array($type, ['appointment_cancelled', 'appointment_no_show'], true)) {
            $html .= '<p style="margin:18px 0 0"><a href="' . $H($maps) . '" style="color:#a4276c;font-weight:700;text-decoration:none">Ver sucursal en Google Maps</a></p>';
        }
        if (!empty($d['payment_required']) && ($d['payment_status'] ?? '') === 'pending') {
            $html .= '<div style="background:#fff8fb;border:1px solid #f1d8e7;border-radius:12px;padding:14px;margin:18px 0 0">';
            $html .= '<div style="font-weight:800;color:#a4276c">Pago anticipado pendiente</div>';
            $html .= '<div style="font-size:13px;color:#6b4860">Para confirmar tu cita, completa tu pago seguro en Mercado Pago.</div>';
            $html .= '</div>';
        }
        if ($cta) {
            $html .= '<div style="text-align:center;margin:26px 0 6px"><a href="' . $H($cta['url']) . '" style="display:inline-block;background:#d63b93;color:#fff;padding:12px 24px;border-radius:999px;text-decoration:none;font-weight:800">' . $H($cta['label']) . '</a></div>';
        }
        $html .= '<p style="margin:22px 0 0">Con cariño,<br><strong>Equipo BellaNick Clinic</strong></p>';
        $html .= '</div>';
        if ($includeReviews) {
            $html .= '<div style="background:linear-gradient(135deg,#fff5f9,#fde6f0);padding:24px 28px;text-align:center">';
            $html .= '<div style="font-size:15px;font-weight:800;color:#a4276c;margin-bottom:6px">¿Nos regalas una reseña?</div>';
            $html .= '<div style="font-size:13px;color:#6b4860;margin-bottom:14px">Tu opinión inspira a otras personas a confiar en nosotros.</div>';
            $html .= '<a href="' . $H($reviewsUrl) . '" style="display:inline-block;background:#d63b93;color:#fff;padding:10px 22px;border-radius:999px;text-decoration:none;font-weight:700">Dejar reseña en Google</a>';
            $html .= '</div>';
        }
        $html .= '<div style="padding:16px 28px;font-size:11.5px;color:#9b6f86;text-align:center;border-top:1px solid #f1e5ec">Recibiste este correo porque tienes una cita registrada en BellaNick Clinic.</div>';
        $html .= '</div></div></body></html>';

        return $html;
    }

    private static function title(string $type): string
    {
        return match ($type) {
            'appointment_created' => 'Confirmacion de tu cita',
            'appointment_confirmed' => 'Tu cita esta confirmada',
            'appointment_cancelled' => 'Tu cita fue cancelada',
            'appointment_no_show' => 'Te esperamos para reagendar',
            'appointment_attended' => 'Gracias por visitarnos',
            'appointment_reminder_24h' => 'Tu cita es manana',
            default => 'Actualizacion de tu cita',
        };
    }

    private static function intro(array $d, string $type): string
    {
        $service = (string) ($d['service_name'] ?? 'tu servicio');
        return match ($type) {
            'appointment_created' => 'Tu cita para ' . $service . ' fue registrada correctamente. Te compartimos los detalles para que los tengas a la mano.',
            'appointment_confirmed' => 'Tu cita para ' . $service . ' ya quedo confirmada. Te esperamos con todo listo para atenderte.',
            'appointment_cancelled' => 'Registramos la cancelacion de tu cita. Si deseas retomarla, con gusto te ayudamos a encontrar un nuevo horario.',
            'appointment_no_show' => 'Notamos que no pudiste asistir a tu cita. Sabemos que pueden surgir imprevistos y podemos ayudarte a reagendar.',
            'appointment_attended' => 'Gracias por confiar en BellaNick Clinic. Dejamos registrado el servicio realizado.',
            'appointment_reminder_24h' => 'Te recordamos que tu cita esta programada para manana. Si necesitas hacer algun ajuste, contactanos con anticipacion.',
            default => 'El estado de tu cita fue actualizado.',
        };
    }

    private static function cta(array $d, string $type): ?array
    {
        if (!empty($d['payment_required']) && ($d['payment_status'] ?? '') === 'pending') {
            return ['label' => 'Pagar mi cita', 'url' => url('pago-cita.php?appointment_id=' . (int) $d['id'])];
        }
        if (in_array($type, ['appointment_cancelled', 'appointment_no_show'], true)) {
            return ['label' => 'Reagendar cita', 'url' => url('agendar.php')];
        }
        if ($type === 'appointment_reminder_24h') {
            return ['label' => 'Ver mis citas', 'url' => url('mis-citas.php')];
        }
        return null;
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><td style="padding:11px 14px;color:#9b6f86;text-transform:uppercase;letter-spacing:1px;font-size:11px;border-bottom:1px solid #f1d8e7">' . e($label) . '</td><td style="padding:11px 14px;text-align:right;font-weight:700;border-bottom:1px solid #f1d8e7">' . e($value) . '</td></tr>';
    }

    private static function googleReviewsUrl(array $d): string
    {
        if (!empty($d['gmaps_url'])) return (string) $d['gmaps_url'];
        return 'https://www.google.com/search?q=' . urlencode(($d['branch_name'] ?? 'BellaNick Clinic') . ' reseñas');
    }

    private static function sendHtml(string $to, string $toName, string $subject, string $html): bool
    {
        return MailService::sendHtml($to, $toName, $subject, $html);
    }

    private static function ensureAppointmentColumn(string $name, string $definition): void
    {
        if (!self::columnExists('appointments', $name)) {
            Database::exec("ALTER TABLE appointments ADD COLUMN {$name} {$definition}");
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

<?php
declare(strict_types=1);

final class SmsService
{
    private const PROVIDER = 'smsmasivos';
    private const REMINDER_LEAD_MINUTES = 180;
    private const REMINDER_WINDOW_MINUTES = 15;

    public static function ensureSchema(): void
    {
        self::addAppointmentColumn('sms_reminder_sent', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::addAppointmentColumn('sms_reminder_sent_at', 'DATETIME NULL');
        self::addAppointmentColumn('sms_reminder_attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
        self::addAppointmentColumn('sms_reminder_last_error', 'VARCHAR(255) NULL');
        self::addAppointmentColumn('sms_reminder_provider', 'VARCHAR(40) NULL');
        self::addAppointmentColumn('sms_reminder_reference', 'VARCHAR(120) NULL');
    }

    public static function runAppointmentReminderCron(int $limit = 50): array
    {
        self::ensureSchema();
        $config = self::config();
        $summary = [
            'ok' => true,
            'enabled' => self::enabled($config),
            'checked' => 0,
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'items' => [],
        ];

        if (!self::enabled($config)) {
            $summary['ok'] = false;
            $summary['error'] = 'SMS no esta habilitado o falta apikey.';
            return $summary;
        }

        $to = date('Y-m-d H:i:s', time() + ((self::REMINDER_LEAD_MINUTES + self::REMINDER_WINDOW_MINUTES) * 60));
        $now = date('Y-m-d H:i:s');

        $rows = Database::all(
            "SELECT a.id, a.code, a.start_at, a.end_at, a.sms_reminder_attempts,
                    u.name AS client_name, u.phone AS client_phone,
                    b.name AS branch_name,
                    st.slug AS status_slug
             FROM appointments a
             JOIN users u ON u.id = a.user_id
             JOIN branches b ON b.id = a.branch_id
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.sms_reminder_sent = 0
               AND COALESCE(a.sms_reminder_attempts, 0) < 3
               AND st.slug IN ('programada','confirmada')
               AND DATE(a.start_at) = CURDATE()
               AND a.start_at >= ?
               AND a.start_at <= ?
             ORDER BY a.start_at ASC
             LIMIT " . max(1, min(200, $limit)),
            [$now, $to]
        );

        $summary['checked'] = count($rows);
        foreach ($rows as $row) {
            $result = self::sendAppointmentReminderRow($row, $config);
            $summary['items'][] = ['appointment_id' => (int) $row['id'], 'result' => $result];
            if (!empty($result['sent'])) {
                $summary['sent']++;
            } elseif (!empty($result['skipped'])) {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    public static function sendAppointmentReminder(int $appointmentId, bool $force = false): array
    {
        self::ensureSchema();
        $config = self::config();
        if (!self::enabled($config)) {
            return ['ok' => false, 'error' => 'SMS no esta habilitado o falta apikey.'];
        }

        $row = Database::one(
            "SELECT a.id, a.code, a.start_at, a.end_at, a.sms_reminder_sent, a.sms_reminder_attempts,
                    u.name AS client_name, u.phone AS client_phone,
                    b.name AS branch_name,
                    st.slug AS status_slug
             FROM appointments a
             JOIN users u ON u.id = a.user_id
             JOIN branches b ON b.id = a.branch_id
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.id = ? LIMIT 1",
            [$appointmentId]
        );
        if (!$row) {
            return ['ok' => false, 'error' => 'Cita no encontrada.'];
        }
        if (!$force && (int) ($row['sms_reminder_sent'] ?? 0) === 1) {
            return ['ok' => false, 'skipped' => true, 'error' => 'El SMS ya fue enviado para esta cita.'];
        }
        if (!$force && !in_array((string) $row['status_slug'], ['programada', 'confirmada'], true)) {
            return ['ok' => false, 'skipped' => true, 'error' => 'El estado de la cita no permite recordatorio SMS.'];
        }

        return self::sendAppointmentReminderRow($row, $config, $force);
    }

    private static function sendAppointmentReminderRow(array $row, array $config, bool $force = false): array
    {
        $phone = self::normalizeMxPhone((string) ($row['client_phone'] ?? ''));
        if ($phone === '') {
            $error = 'Telefono de cliente invalido o vacio.';
            self::markFailed((int) $row['id'], $error);
            return ['ok' => false, 'skipped' => true, 'error' => $error];
        }

        $message = self::appointmentMessage($row);
        $response = self::send($phone, $message, $config);
        if (!empty($response['ok'])) {
            $reference = (string) ($response['reference'] ?? '');
            Database::exec(
                "UPDATE appointments
                 SET sms_reminder_sent = 1,
                     sms_reminder_sent_at = NOW(),
                     sms_reminder_last_error = NULL,
                     sms_reminder_provider = ?,
                     sms_reminder_reference = ?
                 WHERE id = ?",
                [self::PROVIDER, $reference ?: null, (int) $row['id']]
            );
            try {
                Auth::audit('appointment_sms_reminder_sent', 'appointment', (int) $row['id'], [
                    'phone' => $phone,
                    'provider' => self::PROVIDER,
                    'reference' => $reference,
                ]);
            } catch (Throwable $e) {
                error_log('[sms-audit] ' . $e->getMessage());
            }
            return ['ok' => true, 'sent' => true, 'phone' => $phone, 'reference' => $reference];
        }

        $error = (string) ($response['error'] ?? 'No fue posible enviar SMS.');
        self::markFailed((int) $row['id'], $error);
        return ['ok' => false, 'error' => $error, 'phone' => $phone];
    }

    public static function sendTest(string $phone, string $message = 'BellaNick: prueba de SMS de la agenda.'): array
    {
        self::ensureSchema();
        $config = self::config();
        if (!self::enabled($config)) {
            return ['ok' => false, 'error' => 'SMS no esta habilitado o falta apikey.'];
        }
        $phone = self::normalizeMxPhone($phone);
        if ($phone === '') {
            return ['ok' => false, 'error' => 'Telefono invalido.'];
        }
        return self::send($phone, self::cleanMessage($message), $config);
    }

    public static function configStatus(): array
    {
        $config = self::config();
        $apiKey = trim((string) ($config['apikey'] ?? ''));
        return [
            'enabled' => !empty($config['enabled']),
            'has_apikey' => $apiKey !== '' && $apiKey !== 'TU_API_KEY_SMS_MASIVOS',
            'apikey_preview' => $apiKey !== '' ? substr($apiKey, 0, 4) . '...' . substr($apiKey, -4) : '',
            'sandbox' => !empty($config['sandbox']),
            'base_url' => (string) ($config['base_url'] ?? ''),
            'config_path' => dirname(AGENDA_ROOT) . '/config/secrets.php',
        ];
    }

    private static function send(string $phone, string $message, array $config): array
    {
        $url = rtrim((string) ($config['base_url'] ?? 'https://api.smsmasivos.com.mx'), '/') . '/sms/send';
        $payload = [
            'message' => $message,
            'numbers' => $phone,
            'country_code' => '52',
            'channel' => 1,
            'lang' => 'es',
            'sandbox' => !empty($config['sandbox']) ? 1 : 0,
        ];
        if (!empty($config['sender'])) {
            $payload['sender'] = (string) $config['sender'];
        }

        $transport = self::httpPostForm($url, $payload, ['apikey: ' . (string) $config['apikey']]);
        if (empty($transport['ok'])) {
            return $transport;
        }
        $raw = (string) $transport['body'];
        $httpCode = (int) $transport['http_code'];
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            return ['ok' => false, 'error' => 'Respuesta SMS invalida: ' . substr((string) $raw, 0, 180)];
        }
        if ($httpCode >= 200 && $httpCode < 300 && !empty($body['success'])) {
            $reference = '';
            if (!empty($body['references'][0]['reference'])) {
                $reference = (string) $body['references'][0]['reference'];
            }
            return ['ok' => true, 'reference' => $reference, 'response' => $body];
        }

        return [
            'ok' => false,
            'error' => (string) ($body['message'] ?? ('HTTP ' . $httpCode)),
            'code' => $body['code'] ?? null,
            'response' => $body,
        ];
    }

    private static function httpPostForm(string $url, array $payload, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
            ]);
            $raw = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($raw === false) {
                return ['ok' => false, 'error' => 'Error cURL SMS: ' . $curlError];
            }
            return ['ok' => true, 'body' => (string) $raw, 'http_code' => $httpCode];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", array_merge($headers, ['Content-Type: application/x-www-form-urlencoded'])),
                'content' => http_build_query($payload),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $httpCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
                $httpCode = (int) $m[1];
                break;
            }
        }
        if ($raw === false) {
            return ['ok' => false, 'error' => 'No fue posible conectar con SMS Masivos.'];
        }
        return ['ok' => true, 'body' => (string) $raw, 'http_code' => $httpCode];
    }

    private static function appointmentMessage(array $row): string
    {
        $firstName = trim(strtok((string) ($row['client_name'] ?? ''), ' ') ?: 'cliente');
        $time = date('H:i', strtotime((string) $row['start_at']));
        $message = "BellaNick: Hola {$firstName}, te recordamos tu cita hoy a las {$time} en {$row['branch_name']}. Codigo {$row['code']}. Te esperamos.";
        return self::cleanMessage($message);
    }

    private static function cleanMessage(string $message): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ];
        $message = strtr($message, $map);
        $message = preg_replace('/[^\x20-\x7E]/', '', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        return substr(trim($message), 0, 155);
    }

    private static function normalizeMxPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        return strlen($digits) === 10 ? $digits : '';
    }

    private static function markFailed(int $appointmentId, string $error): void
    {
        Database::exec(
            "UPDATE appointments
             SET sms_reminder_attempts = COALESCE(sms_reminder_attempts, 0) + 1,
                 sms_reminder_last_error = ?,
                 sms_reminder_provider = ?
             WHERE id = ?",
            [substr($error, 0, 255), self::PROVIDER, $appointmentId]
        );
    }

    private static function config(): array
    {
        $secretsPath = dirname(AGENDA_ROOT) . '/config/secrets.php';
        $secrets = is_file($secretsPath) ? require $secretsPath : [];
        $sms = is_array($secrets) ? ($secrets['sms']['smsmasivos'] ?? []) : [];
        return array_replace([
            'enabled' => false,
            'apikey' => '',
            'base_url' => 'https://api.smsmasivos.com.mx',
            'sandbox' => true,
            'sender' => '',
        ], is_array($sms) ? $sms : []);
    }

    private static function enabled(array $config): bool
    {
        return !empty($config['enabled']) && !empty($config['apikey']);
    }

    private static function addAppointmentColumn(string $name, string $definition): void
    {
        if (self::columnExists('appointments', $name)) return;
        try {
            Database::exec("ALTER TABLE appointments ADD COLUMN {$name} {$definition}");
        } catch (Throwable $e) {
            error_log('[sms-schema] ' . $name . ': ' . $e->getMessage());
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            return (bool) Database::one(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$table, $column]
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}

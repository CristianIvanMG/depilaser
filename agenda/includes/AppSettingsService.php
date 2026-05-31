<?php
declare(strict_types=1);

final class AppSettingsService
{
    private const DEFAULT_SMS_TEMPLATE = 'BellaNick: Hola {nombre}, te recordamos tu cita hoy a las {hora} en {sucursal}. Codigo {codigo}. Te esperamos.';
    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        Database::exec(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_app_settings_updated_by (updated_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        Database::exec(
            "CREATE TABLE IF NOT EXISTS sms_inventory_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(40) NOT NULL,
                delta INT NOT NULL DEFAULT 0,
                remaining_after INT NOT NULL DEFAULT 0,
                reference VARCHAR(120) NULL,
                note VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sms_inventory_logs_created_at (created_at),
                INDEX idx_sms_inventory_logs_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$schemaReady = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureSchema();
        $row = Database::one('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1', [$key]);
        return $row ? $row['setting_value'] : $default;
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        self::ensureSchema();
        Database::exec(
            "INSERT INTO app_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
            [$key, (string) $value, $userId]
        );
    }

    public static function smsSettings(): array
    {
        self::ensureSchema();
        $total = max(0, (int) self::get('sms_total_purchased', 0));
        $remaining = max(0, (int) self::get('sms_remaining', 0));
        $threshold = max(0, (int) self::get('sms_low_balance_threshold', 50));
        $lead = max(15, min(1440, (int) self::get('sms_reminder_lead_minutes', 180)));
        $window = max(5, min(180, (int) self::get('sms_reminder_window_minutes', 15)));

        return [
            'inventory_enabled' => self::boolSetting('sms_inventory_enabled', false),
            'reminders_enabled' => self::boolSetting('sms_reminders_enabled', true),
            'total_purchased' => $total,
            'remaining' => $remaining,
            'used' => max(0, $total - $remaining),
            'low_balance_threshold' => $threshold,
            'is_low' => $remaining <= $threshold,
            'reminder_lead_minutes' => $lead,
            'reminder_window_minutes' => $window,
            'message_template' => (string) self::get('sms_message_template', self::DEFAULT_SMS_TEMPLATE),
        ];
    }

    public static function saveSmsSettings(array $data, int $adminId): void
    {
        self::ensureSchema();
        self::set('sms_inventory_enabled', !empty($data['sms_inventory_enabled']) ? '1' : '0', $adminId);
        self::set('sms_reminders_enabled', !empty($data['sms_reminders_enabled']) ? '1' : '0', $adminId);
        $remaining = max(0, (int) ($data['sms_remaining'] ?? 0));
        $total = max($remaining, (int) ($data['sms_total_purchased'] ?? 0));
        self::set('sms_total_purchased', $total, $adminId);
        self::set('sms_remaining', $remaining, $adminId);
        self::set('sms_low_balance_threshold', max(0, (int) ($data['sms_low_balance_threshold'] ?? 50)), $adminId);
        self::set('sms_reminder_lead_minutes', max(15, min(1440, (int) ($data['sms_reminder_lead_minutes'] ?? 180))), $adminId);
        self::set('sms_reminder_window_minutes', max(5, min(180, (int) ($data['sms_reminder_window_minutes'] ?? 15))), $adminId);
        $template = trim((string) ($data['sms_message_template'] ?? self::DEFAULT_SMS_TEMPLATE));
        self::set('sms_message_template', $template !== '' ? $template : self::DEFAULT_SMS_TEMPLATE, $adminId);
    }

    public static function addSmsPurchase(int $quantity, int $adminId, string $note = ''): void
    {
        self::ensureSchema();
        $quantity = max(0, $quantity);
        if ($quantity <= 0) {
            throw new InvalidArgumentException('La cantidad comprada debe ser mayor a cero.');
        }

        $settings = self::smsSettings();
        $total = (int) $settings['total_purchased'] + $quantity;
        $remaining = (int) $settings['remaining'] + $quantity;
        self::set('sms_total_purchased', $total, $adminId);
        self::set('sms_remaining', $remaining, $adminId);
        self::logSmsInventory('purchase', $quantity, $remaining, null, $note ?: 'Compra registrada manualmente', $adminId);
    }

    public static function canSendRealSms(): bool
    {
        $settings = self::smsSettings();
        return empty($settings['inventory_enabled']) || (int) $settings['remaining'] > 0;
    }

    public static function debitSms(string $reference = '', string $note = ''): void
    {
        self::ensureSchema();
        $settings = self::smsSettings();
        if (empty($settings['inventory_enabled'])) {
            return;
        }
        $remaining = max(0, (int) $settings['remaining'] - 1);
        self::set('sms_remaining', $remaining, null);
        self::logSmsInventory('sent', -1, $remaining, $reference ?: null, $note ?: 'SMS enviado', null);
    }

    public static function smsStats(): array
    {
        self::ensureSchema();
        $sentRows = self::safeCount("SELECT COUNT(*) AS n FROM appointments WHERE sms_reminder_sent = 1 AND sms_reminder_provider = 'smsmasivos'");
        $fallbackRows = self::safeCount("SELECT COUNT(*) AS n FROM appointments WHERE sms_reminder_sent = 1 AND sms_reminder_provider = 'email_fallback'");
        $failedRows = self::safeCount("SELECT COUNT(*) AS n FROM appointments WHERE COALESCE(sms_reminder_attempts, 0) > 0 AND sms_reminder_sent = 0");
        $todayRows = self::safeCount("SELECT COUNT(*) AS n FROM appointments WHERE sms_reminder_sent = 1 AND sms_reminder_provider = 'smsmasivos' AND DATE(sms_reminder_sent_at) = CURDATE()");
        $fallbackTodayRows = self::safeCount("SELECT COUNT(*) AS n FROM appointments WHERE sms_reminder_sent = 1 AND sms_reminder_provider = 'email_fallback' AND DATE(sms_reminder_sent_at) = CURDATE()");
        return [
            'sent_total' => $sentRows,
            'fallback_total' => $fallbackRows,
            'failed_pending' => $failedRows,
            'sent_today' => $todayRows,
            'fallback_today' => $fallbackTodayRows,
        ];
    }

    public static function recentSmsInventoryLogs(int $limit = 20): array
    {
        self::ensureSchema();
        return Database::all(
            'SELECT * FROM sms_inventory_logs ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    private static function boolSetting(string $key, bool $default): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function logSmsInventory(string $action, int $delta, int $remaining, ?string $reference, string $note, ?int $userId): void
    {
        Database::exec(
            "INSERT INTO sms_inventory_logs (action, delta, remaining_after, reference, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$action, $delta, $remaining, $reference, substr($note, 0, 255), $userId]
        );
    }

    private static function safeCount(string $sql): int
    {
        try {
            return (int) (Database::one($sql)['n'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

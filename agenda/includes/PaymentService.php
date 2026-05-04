<?php
declare(strict_types=1);

final class PaymentService
{
    private const HOLD_MINUTES = 20;

    public static function ensureSchema(): void
    {
        self::ensureServiceColumn('payment_required', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::ensureServiceColumn("payment_mode", "ENUM('none','deposit','full') NOT NULL DEFAULT 'none'");
        self::ensureServiceColumn('deposit_amount_mxn', 'DECIMAL(10,2) NULL');

        self::ensureAppointmentColumn('payment_required', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::ensureAppointmentColumn("payment_status", "ENUM('not_required','pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'not_required'");
        self::ensureAppointmentColumn('payment_amount_mxn', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        self::ensureAppointmentColumn('payment_due_at', 'DATETIME NULL');
        self::ensureAppointmentColumn('payment_expires_at', 'DATETIME NULL');

        Database::exec(
            "CREATE TABLE IF NOT EXISTS appointment_payments (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                appointment_id INT UNSIGNED NOT NULL,
                provider ENUM('mercadopago','stripe') NOT NULL DEFAULT 'mercadopago',
                provider_payment_id VARCHAR(120) NULL,
                provider_preference_id VARCHAR(120) NULL,
                external_reference VARCHAR(80) NOT NULL,
                status ENUM('created','pending','approved','rejected','cancelled','refunded','expired') NOT NULL DEFAULT 'created',
                payment_method VARCHAR(80) NULL,
                amount_mxn DECIMAL(10,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'MXN',
                checkout_url TEXT NULL,
                raw_payload JSON NULL,
                paid_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_payment_external_ref (external_reference),
                UNIQUE KEY uq_payment_provider_payment (provider, provider_payment_id),
                INDEX idx_payment_appointment (appointment_id),
                INDEX idx_payment_preference (provider_preference_id),
                INDEX idx_payment_status (status),
                CONSTRAINT fk_payment_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function servicePaymentConfig(array $service): array
    {
        $required = !empty($service['payment_required']) && ($service['payment_mode'] ?? 'none') !== 'none';
        $price = (float) ($service['price_mxn'] ?? 0);
        $mode = (string) ($service['payment_mode'] ?? 'none');
        $deposit = (float) ($service['deposit_amount_mxn'] ?? 0);
        $amount = 0.0;
        if ($required) {
            $amount = $mode === 'full' ? $price : $deposit;
        }
        if ($amount <= 0) {
            $required = false;
            $mode = 'none';
        }
        return [
            'required' => $required,
            'mode' => $mode,
            'amount' => round($amount, 2),
            'label' => $mode === 'full' ? 'Pago total' : 'Anticipo',
        ];
    }

    public static function createMercadoPagoCheckout(int $appointmentId): array
    {
        self::ensureSchema();
        $d = self::hydrateAppointment($appointmentId);
        if (!$d) {
            return ['ok' => false, 'error' => 'Cita no encontrada.'];
        }
        if ((int) $d['payment_required'] !== 1 || (float) $d['payment_amount_mxn'] <= 0) {
            return ['ok' => false, 'error' => 'Esta cita no requiere pago anticipado.'];
        }
        if (($d['status_slug'] ?? '') === 'cancelada') {
            return ['ok' => false, 'error' => 'No se puede pagar una cita cancelada.'];
        }
        if (($d['payment_status'] ?? '') === 'paid') {
            return ['ok' => true, 'already_paid' => true, 'redirect_url' => url('mis-citas.php')];
        }
        if (strtotime((string) $d['payment_expires_at']) <= time()) {
            self::expireAppointment($appointmentId, 'El tiempo para pagar expiró.');
            return ['ok' => false, 'error' => 'El tiempo para pagar esta cita expiró. Elige un nuevo horario.'];
        }

        $existing = Database::one(
            "SELECT checkout_url
             FROM appointment_payments
             WHERE appointment_id = ? AND status IN ('created','pending') AND checkout_url IS NOT NULL
             ORDER BY id DESC LIMIT 1",
            [$appointmentId]
        );
        if ($existing) {
            return ['ok' => true, 'redirect_url' => (string) $existing['checkout_url']];
        }

        $cfg = self::mercadoPagoConfig();
        if (!$cfg['access_token']) {
            return ['ok' => false, 'error' => 'Mercado Pago no está configurado.'];
        }

        $externalRef = 'BNC-' . $appointmentId . '-' . bin2hex(random_bytes(5));
        $payload = [
            'items' => [[
                'id' => (string) $d['service_id'],
                'title' => 'BellaNick - ' . (string) $d['service_name'],
                'description' => 'Cita ' . (string) $d['code'],
                'quantity' => 1,
                'currency_id' => 'MXN',
                'unit_price' => (float) $d['payment_amount_mxn'],
            ]],
            'payer' => [
                'name' => (string) $d['client_name'],
                'email' => (string) $d['client_email'],
            ],
            'external_reference' => $externalRef,
            'notification_url' => url('api/mercadopago-webhook.php'),
            'back_urls' => [
                'success' => url('pago-cita.php?appointment_id=' . $appointmentId . '&result=success'),
                'failure' => url('pago-cita.php?appointment_id=' . $appointmentId . '&result=failure'),
                'pending' => url('pago-cita.php?appointment_id=' . $appointmentId . '&result=pending'),
            ],
            'auto_return' => 'approved',
            'expires' => true,
            'expiration_date_to' => date(DATE_ATOM, strtotime((string) $d['payment_expires_at'])),
            'statement_descriptor' => 'BELLANICK',
            'metadata' => [
                'appointment_id' => $appointmentId,
                'code' => (string) $d['code'],
            ],
        ];

        $response = self::mpRequest('POST', '/checkout/preferences', $payload);
        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error'] ?? 'No fue posible iniciar el pago.'];
        }

        $body = $response['body'];
        $checkoutUrl = (string) ($cfg['sandbox'] ? ($body['sandbox_init_point'] ?? '') : ($body['init_point'] ?? ''));
        if ($checkoutUrl === '') {
            $checkoutUrl = (string) ($body['init_point'] ?? $body['sandbox_init_point'] ?? '');
        }
        if ($checkoutUrl === '') {
            return ['ok' => false, 'error' => 'Mercado Pago no devolvió URL de pago.'];
        }

        Database::exec(
            "INSERT INTO appointment_payments
                (appointment_id, provider, provider_preference_id, external_reference, status, amount_mxn, checkout_url, raw_payload)
             VALUES (?, 'mercadopago', ?, ?, 'created', ?, ?, ?)",
            [
                $appointmentId,
                (string) ($body['id'] ?? ''),
                $externalRef,
                (float) $d['payment_amount_mxn'],
                $checkoutUrl,
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        return ['ok' => true, 'redirect_url' => $checkoutUrl];
    }

    public static function handleMercadoPagoWebhook(array $payload, array $query, array $headers): array
    {
        self::ensureSchema();
        $cfg = self::mercadoPagoConfig();
        if (!$cfg['access_token']) {
            return ['ok' => false, 'error' => 'Mercado Pago no configurado.'];
        }
        if ($cfg['webhook_secret'] && !self::validMercadoPagoSignature($query, $headers, $cfg['webhook_secret'])) {
            return ['ok' => false, 'error' => 'Firma inválida.'];
        }

        $type = (string) ($payload['type'] ?? $payload['topic'] ?? $query['type'] ?? $query['topic'] ?? '');
        $paymentId = (string) ($payload['data']['id'] ?? $payload['id'] ?? $query['data.id'] ?? $query['id'] ?? '');
        if ($type !== 'payment' || $paymentId === '') {
            return ['ok' => true, 'ignored' => true];
        }

        $payment = self::mpRequest('GET', '/v1/payments/' . rawurlencode($paymentId));
        if (!$payment['ok']) {
            return ['ok' => false, 'error' => $payment['error'] ?? 'No fue posible consultar el pago.'];
        }
        return self::applyMercadoPagoPayment($payment['body']);
    }

    public static function applyMercadoPagoPayment(array $mpPayment): array
    {
        $externalRef = (string) ($mpPayment['external_reference'] ?? '');
        $providerPaymentId = (string) ($mpPayment['id'] ?? '');
        $status = (string) ($mpPayment['status'] ?? '');
        if ($externalRef === '' || $providerPaymentId === '') {
            return ['ok' => false, 'error' => 'Pago sin referencia.'];
        }

        $row = Database::one('SELECT * FROM appointment_payments WHERE external_reference = ? LIMIT 1', [$externalRef]);
        if (!$row) {
            return ['ok' => false, 'error' => 'Referencia de pago no encontrada.'];
        }
        $appointmentId = (int) $row['appointment_id'];
        $d = self::hydrateAppointment($appointmentId);
        if (!$d) {
            return ['ok' => false, 'error' => 'Cita no encontrada.'];
        }
        $paidAmount = (float) ($mpPayment['transaction_amount'] ?? 0);
        $expected = (float) $row['amount_mxn'];
        if ($paidAmount + 0.009 < $expected) {
            return ['ok' => false, 'error' => 'Monto pagado menor al esperado.'];
        }
        if (($d['status_slug'] ?? '') === 'cancelada') {
            return ['ok' => false, 'error' => 'La cita ya está cancelada.'];
        }

        $normalized = match ($status) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'cancelled' => 'cancelled',
            default => 'pending',
        };

        Database::exec(
            "UPDATE appointment_payments
             SET provider_payment_id = ?,
                 status = ?,
                 payment_method = ?,
                 raw_payload = ?,
                 paid_at = IF(? = 'approved', NOW(), paid_at)
             WHERE id = ?",
            [
                $providerPaymentId,
                $normalized,
                (string) ($mpPayment['payment_method_id'] ?? $mpPayment['payment_type_id'] ?? ''),
                json_encode($mpPayment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $normalized,
                (int) $row['id'],
            ]
        );

        if ($normalized === 'approved') {
            self::markAppointmentPaid($appointmentId);
            return ['ok' => true, 'paid' => true, 'appointment_id' => $appointmentId];
        }
        if (in_array($normalized, ['rejected', 'cancelled'], true)) {
            self::cancelUnpaidAppointment($appointmentId, 'El pago no fue aprobado.');
        }
        return ['ok' => true, 'status' => $normalized, 'appointment_id' => $appointmentId];
    }

    public static function markAppointmentPaid(int $appointmentId): void
    {
        $status = Database::one("SELECT id FROM appointment_statuses WHERE slug = 'confirmada' LIMIT 1");
        if (!$status) {
            return;
        }
        Database::exec(
            "UPDATE appointments
             SET payment_status = 'paid',
                 status_id = ?,
                 confirmed_at = COALESCE(confirmed_at, NOW())
             WHERE id = ? AND payment_required = 1 AND payment_status <> 'paid'",
            [(int) $status['id'], $appointmentId]
        );
        EmailNotificationService::sendForAppointment($appointmentId, 'appointment_confirmed');
        Auth::audit('appointment_payment_confirmed', 'appointment', $appointmentId);
    }

    public static function expireAppointment(int $appointmentId, string $reason = 'Pago expirado.'): void
    {
        self::cancelUnpaidAppointment($appointmentId, $reason, 'expired');
    }

    public static function markReturnFailure(int $appointmentId): void
    {
        self::cancelUnpaidAppointment($appointmentId, 'El pago no fue completado.', 'failed');
    }

    public static function expirePendingPayments(): int
    {
        self::ensureSchema();
        $rows = Database::all(
            "SELECT id FROM appointments
             WHERE payment_required = 1
               AND payment_status = 'pending'
               AND payment_expires_at IS NOT NULL
               AND payment_expires_at <= NOW()
             LIMIT 100"
        );
        foreach ($rows as $row) {
            self::expireAppointment((int) $row['id']);
        }
        return count($rows);
    }

    public static function paymentForAppointment(int $appointmentId): ?array
    {
        self::ensureSchema();
        return Database::one(
            'SELECT * FROM appointment_payments WHERE appointment_id = ? ORDER BY id DESC LIMIT 1',
            [$appointmentId]
        );
    }

    public static function paymentLabel(?string $status): string
    {
        return match ($status) {
            'paid' => 'Pagado',
            'pending' => 'Pendiente de pago',
            'failed' => 'Pago rechazado',
            'expired' => 'Pago expirado',
            'cancelled' => 'Pago cancelado',
            default => 'No requerido',
        };
    }

    public static function mercadoPagoConfigured(): bool
    {
        return self::mercadoPagoConfig()['access_token'] !== '';
    }

    private static function cancelUnpaidAppointment(int $appointmentId, string $reason, string $paymentStatus = 'failed'): void
    {
        $status = Database::one("SELECT id FROM appointment_statuses WHERE slug = 'cancelada' LIMIT 1");
        if (!$status) return;
        Database::exec(
            "UPDATE appointments
             SET status_id = ?,
                 payment_status = ?,
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 cancel_reason = COALESCE(cancel_reason, ?)
             WHERE id = ? AND payment_required = 1 AND payment_status <> 'paid'",
            [(int) $status['id'], $paymentStatus, $reason, $appointmentId]
        );
    }

    private static function hydrateAppointment(int $appointmentId): ?array
    {
        return Database::one(
            "SELECT a.*, st.slug AS status_slug,
                    u.name AS client_name, u.email AS client_email,
                    s.name AS service_name, s.price_mxn,
                    b.name AS branch_name
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN users u ON u.id = a.user_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             WHERE a.id = ? LIMIT 1",
            [$appointmentId]
        );
    }

    private static function mercadoPagoConfig(): array
    {
        global $CONFIG;
        $cfg = $CONFIG['payments']['mercadopago'] ?? [];
        $secretCfg = self::secretsPaymentConfig();
        $cfg = array_replace($cfg, $secretCfg);
        $token = (string) ($cfg['access_token'] ?? getenv('MP_ACCESS_TOKEN') ?: '');
        $secret = (string) ($cfg['webhook_secret'] ?? getenv('MP_WEBHOOK_SECRET') ?: '');
        $sandbox = (bool) ($cfg['sandbox'] ?? getenv('MP_SANDBOX') ?: false);
        return ['access_token' => $token, 'webhook_secret' => $secret, 'sandbox' => $sandbox];
    }

    private static function secretsPaymentConfig(): array
    {
        $paths = [
            // Produccion recomendada: fuera de public_html.
            AGENDA_ROOT . '/../../../../secrets.php',
            AGENDA_ROOT . '/../../../secrets.php',
            AGENDA_ROOT . '/../../secrets.php',
            // Fallbacks compatibles con la estructura actual.
            AGENDA_ROOT . '/../config/secrets.php',
            AGENDA_ROOT . '/config/secrets.php',
        ];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $secrets = require $path;
            if (!is_array($secrets)) {
                continue;
            }
            if (isset($secrets['payments']['mercadopago']) && is_array($secrets['payments']['mercadopago'])) {
                return $secrets['payments']['mercadopago'];
            }
            if (isset($secrets['mercadopago']) && is_array($secrets['mercadopago'])) {
                return $secrets['mercadopago'];
            }
        }
        return [];
    }

    private static function mpRequest(string $method, string $path, ?array $payload = null): array
    {
        $cfg = self::mercadoPagoConfig();
        $ch = curl_init('https://api.mercadopago.com' . $path);
        $headers = [
            'Authorization: Bearer ' . $cfg['access_token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            return ['ok' => false, 'error' => $error ?: 'Error de conexión con Mercado Pago.'];
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) $body = [];
        if ($http < 200 || $http >= 300) {
            return ['ok' => false, 'error' => $body['message'] ?? 'Mercado Pago rechazó la solicitud.', 'body' => $body, 'http' => $http];
        }
        return ['ok' => true, 'body' => $body, 'http' => $http];
    }

    private static function validMercadoPagoSignature(array $query, array $headers, string $secret): bool
    {
        $signature = $headers['x-signature'] ?? $headers['X-Signature'] ?? '';
        $requestId = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? '';
        if (!$signature || !$requestId) return false;

        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$k] = $v;
        }
        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        $dataId = strtolower((string) ($query['data.id'] ?? $query['id'] ?? ''));
        if (!$ts || !$v1 || !$dataId) return false;

        $manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $ts . ';';
        $hash = hash_hmac('sha256', $manifest, $secret);
        return hash_equals($hash, $v1);
    }

    private static function ensureServiceColumn(string $name, string $definition): void
    {
        if (!self::columnExists('services', $name)) {
            Database::exec("ALTER TABLE services ADD COLUMN {$name} {$definition}");
        }
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
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$table, $column]
            );
        } catch (Throwable $e) {
            return false;
        }
    }
}

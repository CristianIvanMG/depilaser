<?php
declare(strict_types=1);

final class PaymentService
{
    private const HOLD_MINUTES = 20;

    public static function ensureSchema(): void
    {
        if (class_exists('ServiceCatalogService')) {
            ServiceCatalogService::ensureSchema();
        }
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
                provider ENUM('mercadopago','stripe','manual') NOT NULL DEFAULT 'mercadopago',
                provider_payment_id VARCHAR(120) NULL,
                provider_preference_id VARCHAR(120) NULL,
                external_reference VARCHAR(80) NOT NULL,
                status ENUM('created','pending','approved','rejected','cancelled','refunded','expired') NOT NULL DEFAULT 'created',
                payment_method VARCHAR(80) NULL,
                payment_reference VARCHAR(190) NULL,
                registered_by_user_id INT UNSIGNED NULL,
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
                INDEX idx_payment_registered_by (registered_by_user_id),
                CONSTRAINT fk_payment_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
                CONSTRAINT fk_payment_registered_by FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureProviderAllowsManual();
        self::ensurePaymentColumn('provider_payment_id', 'VARCHAR(120) NULL');
        self::ensurePaymentColumn('provider_preference_id', 'VARCHAR(120) NULL');
        self::ensurePaymentColumn('external_reference', 'VARCHAR(80) NULL');
        self::ensurePaymentColumn("status", "ENUM('created','pending','approved','rejected','cancelled','refunded','expired') NOT NULL DEFAULT 'created'");
        self::ensurePaymentColumn('payment_method', 'VARCHAR(80) NULL');
        self::ensurePaymentColumn('payment_reference', 'VARCHAR(190) NULL');
        self::ensurePaymentColumn('registered_by_user_id', 'INT UNSIGNED NULL');
        self::ensurePaymentColumn('amount_mxn', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        self::ensurePaymentColumn("currency", "CHAR(3) NOT NULL DEFAULT 'MXN'");
        self::ensurePaymentColumn('checkout_url', 'TEXT NULL');
        self::ensurePaymentColumn('raw_payload', 'JSON NULL');
        self::ensurePaymentColumn('paid_at', 'DATETIME NULL');
        self::ensurePaymentIndex('idx_payment_registered_by', 'registered_by_user_id');
        self::ensurePaymentRegisteredByConstraint();
    }

    public static function servicePaymentConfig(array $service): array
    {
        $price = (float) ($service['price_mxn'] ?? 0);
        $required = !empty($service['payment_required']) && ($service['payment_mode'] ?? 'none') !== 'none';
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

    public static function serviceAvailableForOnlineBooking(array $service): bool
    {
        $price = (float) ($service['price_mxn'] ?? 0);
        if ($price <= 0) {
            return true;
        }
        $payment = self::servicePaymentConfig($service);
        return !empty($payment['required']) && (float) ($payment['amount'] ?? 0) > 0;
    }

    public static function servicePaymentUnavailableReason(array $service): string
    {
        $price = (float) ($service['price_mxn'] ?? 0);
        if ($price <= 0) {
            return '';
        }
        if (empty($service['payment_required']) || ($service['payment_mode'] ?? 'none') === 'none') {
            return 'Este servicio tiene costo y no tiene pago en linea activo.';
        }
        $mode = (string) ($service['payment_mode'] ?? 'none');
        $deposit = (float) ($service['deposit_amount_mxn'] ?? 0);
        $amount = $mode === 'full' ? $price : $deposit;
        if ($amount <= 0) {
            return 'Este servicio tiene pago en linea incompleto.';
        }
        return '';
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

        $cfg = self::mercadoPagoConfig($d);
        if (!$cfg['access_token']) {
            return ['ok' => false, 'error' => 'Mercado Pago no está configurado.'];
        }

        $externalRef = self::externalReference($appointmentId);
        $payerEmail = filter_var((string) $d['client_email'], FILTER_VALIDATE_EMAIL)
            ? (string) $d['client_email']
            : 'pagos@bellanickclinic.com';
        $itemType = class_exists('ServiceCatalogService') ? ServiceCatalogService::paymentItemLabel($d) : 'Servicio';
        $itemTitle = trim('BellaNick - ' . $itemType . ' - ' . (string) $d['service_name']);
        if ($itemTitle === 'BellaNick -') {
            $itemTitle = 'BellaNick - Cita';
        }
        $amount = round((float) $d['payment_amount_mxn'], 2);
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'El monto de pago no es válido.'];
        }
        $payload = [
            'items' => [[
                'id' => (string) $d['service_id'],
                'title' => $itemTitle,
                'description' => 'Cita ' . (string) $d['code'],
                'quantity' => 1,
                'currency_id' => 'MXN',
                'unit_price' => $amount,
            ]],
            'payer' => [
                'name' => (string) $d['client_name'],
                'email' => $payerEmail,
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

        $response = self::mpRequest('POST', '/checkout/preferences', $payload, $cfg);
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
            "UPDATE appointments
             SET payment_due_at = NOW()
             WHERE id = ? AND payment_status = 'pending'",
            [$appointmentId]
        );

        return ['ok' => true, 'redirect_url' => $checkoutUrl];
    }

    public static function handleMercadoPagoWebhook(array $payload, array $query, array $headers): array
    {
        self::ensureSchema();
        if (!self::mercadoPagoAnyConfigured()) {
            return ['ok' => false, 'error' => 'Mercado Pago no configurado.'];
        }
        if (!self::validMercadoPagoWebhookSignature($query, $headers)) {
            return ['ok' => false, 'error' => 'Firma inválida.'];
        }

        $type = (string) ($payload['type'] ?? $payload['topic'] ?? $query['type'] ?? $query['topic'] ?? '');
        $paymentId = (string) ($payload['data']['id'] ?? $payload['id'] ?? $query['data.id'] ?? $query['id'] ?? '');
        if ($type !== 'payment' || $paymentId === '') {
            return ['ok' => true, 'ignored' => true];
        }

        $externalRef = (string) ($payload['external_reference'] ?? $query['external_reference'] ?? '');
        $cfg = $externalRef !== '' ? self::mercadoPagoConfigForExternalReference($externalRef) : null;
        $payment = ($cfg && !empty($cfg['access_token']))
            ? self::mpRequest('GET', '/v1/payments/' . rawurlencode($paymentId), null, $cfg)
            : self::mpRequestAcrossAccounts('GET', '/v1/payments/' . rawurlencode($paymentId));
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

        $appointmentId = self::appointmentIdFromExternalReference($externalRef);
        if ($appointmentId <= 0) {
            return ['ok' => false, 'error' => 'Referencia de pago no encontrada.'];
        }
        $d = self::hydrateAppointment($appointmentId);
        if (!$d) {
            return ['ok' => false, 'error' => 'Cita no encontrada.'];
        }
        $paidAmount = (float) ($mpPayment['transaction_amount'] ?? 0);
        $expected = (float) $d['payment_amount_mxn'];
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

        self::recordProviderPayment($appointmentId, $externalRef, $providerPaymentId, $normalized, $mpPayment);

        if ($normalized === 'approved') {
            self::markAppointmentPaid($appointmentId);
            return ['ok' => true, 'paid' => true, 'appointment_id' => $appointmentId];
        }
        if (in_array($normalized, ['rejected', 'cancelled'], true)) {
            self::cancelUnpaidAppointment($appointmentId, 'El pago no fue aprobado.');
        }
        return ['ok' => true, 'status' => $normalized, 'appointment_id' => $appointmentId];
    }

    public static function syncMercadoPagoReturn(int $appointmentId, array $query): array
    {
        self::ensureSchema();
        $d = self::hydrateAppointment($appointmentId);
        if (!$d) {
            return ['ok' => false, 'error' => 'Cita no encontrada.'];
        }
        $cfg = self::mercadoPagoConfig($d);
        if (!$cfg['access_token']) {
            return ['ok' => false, 'error' => 'Mercado Pago no configurado.'];
        }

        $paymentId = (string) (
            $query['payment_id']
            ?? $query['collection_id']
            ?? $query['data.id']
            ?? $query['id']
            ?? ''
        );

        if ($paymentId !== '' && $paymentId !== 'null') {
            $payment = self::mpRequest('GET', '/v1/payments/' . rawurlencode($paymentId), null, $cfg);
            if ($payment['ok']) {
                return self::applyMercadoPagoPayment($payment['body']);
            }

            if (($query['status'] ?? '') === 'approved' || ($query['collection_status'] ?? '') === 'approved') {
                return self::applyMercadoPagoReturnPayload($appointmentId, $query, $paymentId);
            }

            return ['ok' => false, 'error' => $payment['error'] ?? 'No fue posible consultar el pago.'];
        }

        $externalRef = self::externalReference($appointmentId);
        $search = self::mpRequest(
            'GET',
            '/v1/payments/search?external_reference=' . rawurlencode($externalRef) . '&sort=date_created&criteria=desc',
            null,
            $cfg
        );
        if (!$search['ok']) {
            return ['ok' => false, 'error' => $search['error'] ?? 'No fue posible consultar el pago.'];
        }

        $results = $search['body']['results'] ?? [];
        if (!is_array($results) || !$results) {
            return ['ok' => false, 'pending' => true, 'error' => 'Mercado Pago todavía no devuelve un pago para esta cita.'];
        }

        $selected = null;
        foreach ($results as $candidate) {
            if (($candidate['status'] ?? '') === 'approved') {
                $selected = $candidate;
                break;
            }
        }
        $selected ??= $results[0];

        return self::applyMercadoPagoPayment($selected);
    }

    private static function applyMercadoPagoReturnPayload(int $appointmentId, array $query, string $paymentId): array
    {
        $d = self::hydrateAppointment($appointmentId);
        if (!$d) {
            return ['ok' => false, 'error' => 'Cita no encontrada.'];
        }
        $externalRef = (string) ($query['external_reference'] ?? '');
        if ($externalRef !== self::externalReference($appointmentId)) {
            return ['ok' => false, 'error' => 'La referencia del pago no corresponde a esta cita.'];
        }
        if (($d['status_slug'] ?? '') === 'cancelada') {
            return ['ok' => false, 'error' => 'La cita ya está cancelada.'];
        }

        $payload = [
            'id' => $paymentId,
            'status' => 'approved',
            'external_reference' => $externalRef,
            'preference_id' => (string) ($query['preference_id'] ?? ''),
            'payment_method_id' => (string) ($query['payment_type'] ?? ''),
            'transaction_amount' => (float) $d['payment_amount_mxn'],
            'return_payload' => $query,
        ];

        self::recordProviderPayment($appointmentId, $externalRef, $paymentId, 'approved', $payload);
        self::markAppointmentPaid($appointmentId);

        return ['ok' => true, 'paid' => true, 'appointment_id' => $appointmentId, 'from_return' => true];
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
            "SELECT * FROM appointment_payments
             WHERE appointment_id = ?
             ORDER BY (status = 'approved') DESC, paid_at DESC, id DESC
             LIMIT 1",
            [$appointmentId]
        );
    }

    public static function paidSummaryForAppointment(int $appointmentId): array
    {
        $payment = self::paymentForAppointment($appointmentId);
        return [
            'is_paid' => $payment && ($payment['status'] ?? '') === 'approved',
            'payment' => $payment,
        ];
    }

    public static function registerManualPayment(
        int $appointmentId,
        int $actorUserId,
        string $method = 'manual',
        ?string $reference = null,
        ?float $amount = null,
        bool $sendReceipt = true
    ): array {
        self::ensureSchema();
        AppointmentService::ensureReceiptSchema();

        $d = self::hydrateAppointment($appointmentId);
        if (!$d) {
            return ['ok' => false, 'error' => 'Cita no encontrada.'];
        }
        if (($d['status_slug'] ?? '') !== 'atendida') {
            return ['ok' => false, 'error' => 'El pago solo puede registrarse cuando la cita está Atendida.'];
        }

        if (class_exists('AppointmentService') && AppointmentService::isPackageIncludedSession($d)) {
            Database::exec(
                "UPDATE appointments
                 SET payment_required = 0,
                     payment_status = 'not_required',
                     payment_amount_mxn = 0.00
                 WHERE id = ?",
                [$appointmentId]
            );
            return [
                'ok' => true,
                'paid' => false,
                'skipped_package_session' => true,
                'receipt_sent' => false,
                'message' => 'Sesion incluida en paquete ya pagado; no se registro nuevo pago ni recibo.',
            ];
        }

        $existing = self::paymentForAppointment($appointmentId);
        if ($existing && ($existing['status'] ?? '') === 'approved') {
            return ['ok' => true, 'already_paid' => true, 'payment' => $existing];
        }

        $amount = $amount ?? (float) ($d['payment_amount_mxn'] ?? 0);
        if ($amount <= 0) {
            $amount = (float) ($d['price_mxn'] ?? 0);
        }
        if ($amount < 0) {
            return ['ok' => false, 'error' => 'El monto de pago no es válido.'];
        }

        $method = trim($method) ?: 'manual';
        $reference = trim((string) $reference) ?: null;
        $externalRef = 'BNC-MANUAL-' . $appointmentId;
        $raw = json_encode([
            'source' => 'admin',
            'registered_by_user_id' => $actorUserId,
            'method' => $method,
            'reference' => $reference,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($existing) {
            Database::exec(
                "UPDATE appointment_payments
                 SET provider = 'manual',
                     provider_payment_id = NULL,
                     provider_preference_id = NULL,
                     external_reference = ?,
                     status = 'approved',
                     payment_method = ?,
                     payment_reference = ?,
                     registered_by_user_id = ?,
                     amount_mxn = ?,
                     raw_payload = ?,
                     paid_at = COALESCE(paid_at, NOW())
                 WHERE id = ?",
                [$externalRef, $method, $reference, $actorUserId, $amount, $raw, (int) $existing['id']]
            );
        } else {
            Database::exec(
                "INSERT INTO appointment_payments
                    (appointment_id, provider, external_reference, status, payment_method, payment_reference,
                     registered_by_user_id, amount_mxn, raw_payload, paid_at)
                 VALUES (?, 'manual', ?, 'approved', ?, ?, ?, ?, ?, NOW())",
                [$appointmentId, $externalRef, $method, $reference, $actorUserId, $amount, $raw]
            );
        }

        Database::exec(
            "UPDATE appointments
             SET payment_status = 'paid',
                 payment_amount_mxn = CASE WHEN payment_amount_mxn <= 0 THEN ? ELSE payment_amount_mxn END
             WHERE id = ?",
            [$amount, $appointmentId]
        );

        $payment = self::paymentForAppointment($appointmentId);
        Auth::audit('appointment_manual_payment_registered', 'appointment', $appointmentId, [
            'method' => $method,
            'reference' => $reference,
            'amount_mxn' => $amount,
        ]);

        $receipt = null;
        if ($sendReceipt) {
            $receipt = ReceiptService::emailReceipt($appointmentId, false);
        }

        return [
            'ok' => true,
            'paid' => true,
            'payment' => $payment,
            'receipt_sent' => (bool) ($receipt['ok'] ?? false),
            'receipt_warning' => $receipt && empty($receipt['ok']) ? ($receipt['error'] ?? 'No fue posible enviar el recibo.') : null,
        ];
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

    public static function mercadoPagoConfigured(?int $appointmentId = null): bool
    {
        if ($appointmentId) {
            $d = self::hydrateAppointment($appointmentId);
            return $d ? self::mercadoPagoConfig($d)['access_token'] !== '' : false;
        }
        return self::mercadoPagoAnyConfigured();
    }

    public static function mercadoPagoConfiguredForBranch(int $branchId): bool
    {
        $branch = Database::one('SELECT id AS branch_id, slug AS branch_slug, name AS branch_name FROM branches WHERE id = ? LIMIT 1', [$branchId]);
        return $branch ? self::mercadoPagoConfig($branch)['access_token'] !== '' : self::mercadoPagoAnyConfigured();
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

    private static function externalReference(int $appointmentId): string
    {
        return 'BNC-APPT-' . $appointmentId;
    }

    private static function appointmentIdFromExternalReference(string $reference): int
    {
        if (preg_match('/^BNC-APPT-(\d+)$/', $reference, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^BNC-(\d+)-/', $reference, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private static function recordProviderPayment(
        int $appointmentId,
        string $externalRef,
        string $providerPaymentId,
        string $status,
        array $payload
    ): void {
        self::ensureSchema();
        $preferenceId = (string) ($payload['preference_id'] ?? '');
        $paymentMethod = (string) ($payload['payment_method_id'] ?? $payload['payment_type_id'] ?? '');
        $amount = (float) ($payload['transaction_amount'] ?? 0);
        $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existing = $providerPaymentId !== ''
            ? Database::one(
                "SELECT id FROM appointment_payments
                 WHERE provider = 'mercadopago' AND provider_payment_id = ?
                 LIMIT 1",
                [$providerPaymentId]
            )
            : null;
        if (!$existing) {
            $existing = Database::one(
                "SELECT id FROM appointment_payments
                 WHERE appointment_id = ? AND external_reference = ?
                 ORDER BY id DESC LIMIT 1",
                [$appointmentId, $externalRef]
            );
        }

        if ($existing) {
            Database::exec(
                "UPDATE appointment_payments
                 SET provider_payment_id = ?,
                     provider_preference_id = ?,
                     external_reference = ?,
                     status = ?,
                     payment_method = ?,
                     amount_mxn = ?,
                     raw_payload = ?,
                     paid_at = IF(? = 'approved', COALESCE(paid_at, NOW()), paid_at)
                 WHERE id = ?",
                [
                    $providerPaymentId ?: null,
                    $preferenceId ?: null,
                    $externalRef,
                    $status,
                    $paymentMethod ?: null,
                    $amount,
                    $rawPayload,
                    $status,
                    (int) $existing['id'],
                ]
            );
            return;
        }

        Database::exec(
            "INSERT INTO appointment_payments
                (appointment_id, provider, provider_payment_id, provider_preference_id, external_reference, status,
                 payment_method, amount_mxn, checkout_url, raw_payload, paid_at)
             VALUES (?, 'mercadopago', ?, ?, ?, ?, ?, ?, NULL, ?, IF(? = 'approved', NOW(), NULL))",
            [
                $appointmentId,
                $providerPaymentId ?: null,
                $preferenceId ?: null,
                $externalRef,
                $status,
                $paymentMethod ?: null,
                $amount,
                $rawPayload,
                $status,
            ]
        );
    }

    private static function hydrateAppointment(int $appointmentId): ?array
    {
        return Database::one(
            "SELECT a.*, st.slug AS status_slug,
                    u.name AS client_name, u.email AS client_email,
                    s.name AS service_name, " . ServiceCatalogService::priceSql('s') . " AS price_mxn,
                    COALESCE(s.item_type, 'service') AS item_type,
                    COALESCE(s.sessions_count, 1) AS sessions_count,
                    b.id AS branch_id, b.slug AS branch_slug, b.name AS branch_name
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN users u ON u.id = a.user_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             WHERE a.id = ? LIMIT 1",
            [$appointmentId]
        );
    }

    private static function mercadoPagoConfig(?array $appointmentOrBranch = null): array
    {
        $cfg = self::baseMercadoPagoConfig();
        $branchCfg = self::mercadoPagoBranchConfig($appointmentOrBranch, $cfg);
        if ($branchCfg) {
            $cfg = array_replace($cfg, $branchCfg);
        }

        $token = (string) ($cfg['access_token'] ?? getenv('MP_ACCESS_TOKEN') ?: '');
        $secret = (string) ($cfg['webhook_secret'] ?? getenv('MP_WEBHOOK_SECRET') ?: '');
        $sandbox = (bool) ($cfg['sandbox'] ?? getenv('MP_SANDBOX') ?: false);

        return [
            'access_token' => $token,
            'webhook_secret' => $secret,
            'sandbox' => $sandbox,
            'branch_id' => isset($appointmentOrBranch['branch_id']) ? (int) $appointmentOrBranch['branch_id'] : null,
            'branch_slug' => $appointmentOrBranch['branch_slug'] ?? null,
        ];
    }

    private static function baseMercadoPagoConfig(): array
    {
        global $CONFIG;
        $cfg = $CONFIG['payments']['mercadopago'] ?? [];
        $secretCfg = self::secretsPaymentConfig();
        return array_replace($cfg, $secretCfg);
    }

    private static function mercadoPagoBranchConfig(?array $appointmentOrBranch, array $cfg): array
    {
        if (!$appointmentOrBranch) {
            return [];
        }
        $branches = $cfg['branches'] ?? $cfg['branch_tokens'] ?? [];
        if (!is_array($branches) || !$branches) {
            return [];
        }

        $branchId = isset($appointmentOrBranch['branch_id']) ? (string) (int) $appointmentOrBranch['branch_id'] : '';
        $branchSlug = (string) ($appointmentOrBranch['branch_slug'] ?? '');
        $candidates = array_values(array_filter([$branchId, $branchSlug]));

        foreach ($candidates as $key) {
            if (!array_key_exists($key, $branches)) {
                continue;
            }
            $branchCfg = $branches[$key];
            if (is_string($branchCfg)) {
                return ['access_token' => $branchCfg];
            }
            if (is_array($branchCfg)) {
                return $branchCfg;
            }
        }

        return [];
    }

    private static function mercadoPagoConfigForExternalReference(string $externalRef): ?array
    {
        $appointmentId = self::appointmentIdFromExternalReference($externalRef);
        if ($appointmentId <= 0) {
            return null;
        }
        $d = self::hydrateAppointment($appointmentId);
        return $d ? self::mercadoPagoConfig($d) : null;
    }

    private static function mercadoPagoConfiguredAccounts(): array
    {
        $base = self::baseMercadoPagoConfig();
        $accounts = [];
        $global = self::mercadoPagoConfig(null);
        if ($global['access_token'] !== '') {
            $accounts['global'] = $global;
        }

        $branches = $base['branches'] ?? $base['branch_tokens'] ?? [];
        if (is_array($branches)) {
            foreach ($branches as $key => $branchCfg) {
                $cfg = is_string($branchCfg) ? ['access_token' => $branchCfg] : (is_array($branchCfg) ? $branchCfg : []);
                $cfg = array_replace($base, $cfg);
                unset($cfg['branches'], $cfg['branch_tokens']);
                $token = (string) ($cfg['access_token'] ?? '');
                if ($token === '') {
                    continue;
                }
                $accounts['branch:' . (string) $key] = [
                    'access_token' => $token,
                    'webhook_secret' => (string) ($cfg['webhook_secret'] ?? ''),
                    'sandbox' => (bool) ($cfg['sandbox'] ?? false),
                ];
            }
        }

        return $accounts;
    }

    private static function mercadoPagoAnyConfigured(): bool
    {
        return (bool) self::mercadoPagoConfiguredAccounts();
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
            $flatToken = $secrets['MP_ACCESS_TOKEN']
                ?? $secrets['mp_access_token']
                ?? $secrets['mercadopago_access_token']
                ?? null;
            if (is_string($flatToken) && trim($flatToken) !== '') {
                return [
                    'access_token' => trim($flatToken),
                    'webhook_secret' => (string) (
                        $secrets['MP_WEBHOOK_SECRET']
                        ?? $secrets['mp_webhook_secret']
                        ?? $secrets['mercadopago_webhook_secret']
                        ?? ''
                    ),
                    'sandbox' => (bool) (
                        $secrets['MP_SANDBOX']
                        ?? $secrets['mp_sandbox']
                        ?? $secrets['mercadopago_sandbox']
                        ?? false
                    ),
                ];
            }
        }
        return [];
    }

    private static function mpRequest(string $method, string $path, ?array $payload = null, ?array $cfg = null): array
    {
        $cfg ??= self::mercadoPagoConfig(null);
        if (empty($cfg['access_token'])) {
            return ['ok' => false, 'error' => 'Mercado Pago no configurado.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'La extension cURL de PHP no esta disponible para conectar con Mercado Pago.'];
        }
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

    private static function mpRequestAcrossAccounts(string $method, string $path, ?array $payload = null): array
    {
        $last = ['ok' => false, 'error' => 'Mercado Pago no configurado.'];
        foreach (self::mercadoPagoConfiguredAccounts() as $cfg) {
            $result = self::mpRequest($method, $path, $payload, $cfg);
            if (!empty($result['ok'])) {
                return $result;
            }
            $last = $result;
        }
        return $last;
    }

    private static function validMercadoPagoWebhookSignature(array $query, array $headers): bool
    {
        $secrets = [];
        foreach (self::mercadoPagoConfiguredAccounts() as $cfg) {
            $secret = (string) ($cfg['webhook_secret'] ?? '');
            if ($secret !== '') {
                $secrets[$secret] = true;
            }
        }
        if (!$secrets) {
            return true;
        }
        foreach (array_keys($secrets) as $secret) {
            if (self::validMercadoPagoSignature($query, $headers, $secret)) {
                return true;
            }
        }
        return false;
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

    private static function ensurePaymentColumn(string $name, string $definition): void
    {
        if (!self::columnExists('appointment_payments', $name)) {
            Database::exec("ALTER TABLE appointment_payments ADD COLUMN {$name} {$definition}");
        }
    }

    private static function ensureProviderAllowsManual(): void
    {
        try {
            $row = Database::one("SHOW COLUMNS FROM appointment_payments LIKE 'provider'");
            if ($row && isset($row['Type']) && !str_contains((string) $row['Type'], "'manual'")) {
                Database::exec("ALTER TABLE appointment_payments MODIFY provider ENUM('mercadopago','stripe','manual') NOT NULL DEFAULT 'mercadopago'");
            }
        } catch (Throwable $e) {
            error_log('[payment-schema] provider manual: ' . $e->getMessage());
        }
    }

    private static function ensurePaymentIndex(string $name, string $column): void
    {
        try {
            $idx = Database::one(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'appointment_payments'
                   AND INDEX_NAME = ?
                 LIMIT 1",
                [$name]
            );
            if (!$idx) {
                Database::exec("ALTER TABLE appointment_payments ADD INDEX {$name} ({$column})");
            }
        } catch (Throwable $e) {
            error_log('[payment-schema] index ' . $name . ': ' . $e->getMessage());
        }
    }

    private static function ensurePaymentRegisteredByConstraint(): void
    {
        try {
            $fk = Database::one(
                "SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'appointment_payments'
                   AND CONSTRAINT_NAME = 'fk_payment_registered_by'
                 LIMIT 1"
            );
            if (!$fk) {
                Database::exec(
                    'ALTER TABLE appointment_payments
                     ADD CONSTRAINT fk_payment_registered_by
                     FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL'
                );
            }
        } catch (Throwable $e) {
            error_log('[payment-schema] fk registered_by: ' . $e->getMessage());
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

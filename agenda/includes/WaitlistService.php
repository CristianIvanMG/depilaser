<?php
declare(strict_types=1);

final class WaitlistService
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_PROMOTED = 'promoted';
    public const STATUS_CANCELLED = 'cancelled';

    public static function ensureSchema(): void
    {
        Database::exec(
            "CREATE TABLE IF NOT EXISTS waitlist_entries (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                branch_id SMALLINT UNSIGNED NOT NULL,
                service_id SMALLINT UNSIGNED NOT NULL,
                zone VARCHAR(120) NULL,
                preferred_date_from DATE NULL,
                preferred_date_to DATE NULL,
                preferred_time_start TIME NULL,
                preferred_time_end TIME NULL,
                status ENUM('waiting','promoted','cancelled') NOT NULL DEFAULT 'waiting',
                source ENUM('web','admin') NOT NULL DEFAULT 'web',
                notes VARCHAR(500) NULL,
                promoted_appointment_id INT UNSIGNED NULL,
                promoted_from_appointment_id INT UNSIGNED NULL,
                promoted_at DATETIME NULL,
                notified_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_waitlist_match (status, branch_id, service_id, created_at),
                INDEX idx_waitlist_user (user_id, status),
                CONSTRAINT fk_waitlist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_waitlist_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
                CONSTRAINT fk_waitlist_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
                CONSTRAINT fk_waitlist_promoted_appt FOREIGN KEY (promoted_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
                CONSTRAINT fk_waitlist_source_appt FOREIGN KEY (promoted_from_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function createForClient(int $userId, array $data): array
    {
        self::ensureSchema();

        $branchId = (int) ($data['branch_id'] ?? 0);
        $serviceId = (int) ($data['service_id'] ?? 0);
        $date = self::validDate((string) ($data['preferred_date'] ?? ''));
        $zone = trim((string) ($data['zone'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($branchId <= 0 || $serviceId <= 0 || !$date) {
            return ['ok' => false, 'error' => 'Selecciona sucursal, servicio y fecha para agregarte a la lista de espera.'];
        }
        if (!Database::one('SELECT id FROM branches WHERE id = ? AND active = 1 LIMIT 1', [$branchId])) {
            return ['ok' => false, 'error' => 'La sucursal seleccionada no está disponible.'];
        }
        if (!Database::one('SELECT id FROM services WHERE id = ? AND active = 1 LIMIT 1', [$serviceId])) {
            return ['ok' => false, 'error' => 'El servicio seleccionado no está disponible.'];
        }
        if (!Database::one('SELECT 1 FROM service_branches WHERE branch_id = ? AND service_id = ? LIMIT 1', [$branchId, $serviceId])) {
            return ['ok' => false, 'error' => 'Esta sucursal no ofrece el servicio seleccionado.'];
        }
        if (strtotime($date) < strtotime('today')) {
            return ['ok' => false, 'error' => 'Selecciona una fecha actual o futura.'];
        }

        $existing = Database::one(
            "SELECT id
             FROM waitlist_entries
             WHERE user_id = ? AND branch_id = ? AND service_id = ?
               AND status = 'waiting'
               AND preferred_date_from = ?
             LIMIT 1",
            [$userId, $branchId, $serviceId, $date]
        );
        if ($existing) {
            return ['ok' => true, 'duplicate' => true, 'id' => (int) $existing['id']];
        }

        Database::exec(
            "INSERT INTO waitlist_entries
                (user_id, branch_id, service_id, zone, preferred_date_from, preferred_date_to, notes, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'web')",
            [
                $userId,
                $branchId,
                $serviceId,
                $zone !== '' ? mb_substr($zone, 0, 120) : null,
                $date,
                $date,
                $notes !== '' ? mb_substr($notes, 0, 500) : null,
            ]
        );
        $id = Database::lastId();
        Auth::audit('waitlist_join', 'waitlist', $id, [
            'branch_id' => $branchId,
            'service_id' => $serviceId,
            'date' => $date,
        ]);

        return ['ok' => true, 'id' => $id];
    }

    public static function promoteForCancelledAppointment(int $cancelledAppointmentId): array
    {
        self::ensureSchema();

        $cancelled = Database::one(
            "SELECT a.*, s.duration_min, st.slug AS status_slug
             FROM appointments a
             JOIN services s ON s.id = a.service_id
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.id = ? LIMIT 1",
            [$cancelledAppointmentId]
        );
        if (!$cancelled || ($cancelled['status_slug'] ?? '') !== 'cancelada') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'La cita cancelada no está disponible para promoción.'];
        }

        $startSql = (string) $cancelled['start_at'];
        $endSql = (string) $cancelled['end_at'];
        $date = substr($startSql, 0, 10);
        $time = substr($startSql, 11, 8);

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();

            if (AppointmentService::hasConflict((int) $cancelled['branch_id'], $startSql, $endSql, $cancelledAppointmentId, true)) {
                $pdo->commit();
                return ['ok' => false, 'skipped' => true, 'reason' => 'El horario ya fue ocupado por otra cita.'];
            }

            $entry = Database::one(
                "SELECT wl.*, u.email, u.email_verified, u.active
                 FROM waitlist_entries wl
                 JOIN users u ON u.id = wl.user_id
                 WHERE wl.status = 'waiting'
                   AND wl.branch_id = ?
                   AND wl.service_id = ?
                   AND u.active = 1
                   AND NOT EXISTS (
                       SELECT 1
                       FROM appointments ax
                       JOIN appointment_statuses sx ON sx.id = ax.status_id
                       WHERE ax.user_id = wl.user_id
                         AND sx.slug IN ('programada','confirmada','atendida')
                         AND ax.start_at < ?
                         AND ax.end_at > ?
                   )
                   AND (wl.preferred_date_from IS NULL OR wl.preferred_date_from <= ?)
                   AND (wl.preferred_date_to IS NULL OR wl.preferred_date_to >= ?)
                   AND (wl.preferred_time_start IS NULL OR wl.preferred_time_start <= ?)
                   AND (wl.preferred_time_end IS NULL OR wl.preferred_time_end >= ?)
                 ORDER BY wl.created_at ASC, wl.id ASC
                 LIMIT 1
                 FOR UPDATE",
                [
                    (int) $cancelled['branch_id'],
                    (int) $cancelled['service_id'],
                    $endSql,
                    $startSql,
                    $date,
                    $date,
                    $time,
                    $time,
                ]
            );

            if (!$entry) {
                $pdo->commit();
                return ['ok' => false, 'skipped' => true, 'reason' => 'No hay clientes elegibles en lista de espera.'];
            }

            $servicePayment = Database::one('SELECT price_mxn, payment_required, payment_mode, deposit_amount_mxn FROM services WHERE id = ? LIMIT 1', [(int) $cancelled['service_id']]);
            $payment = PaymentService::servicePaymentConfig($servicePayment ?: []);
            $statusSlug = $payment['required'] ? 'programada' : 'confirmada';
            $statusId = (int) (Database::one("SELECT id FROM appointment_statuses WHERE slug = ? LIMIT 1", [$statusSlug])['id'] ?? 0);
            if (!$statusId) {
                throw new RuntimeException('WAITLIST_STATUS_MISSING');
            }

            $professionalId = !empty($cancelled['professional_id']) ? (int) $cancelled['professional_id'] : null;
            if ($professionalId && AppointmentService::professionalHasConflict($professionalId, $startSql, $endSql, $cancelledAppointmentId, true)) {
                $professionalId = null;
            }

            $code = generate_appointment_code();
            Database::exec(
                "INSERT INTO appointments
                    (code, user_id, professional_id, branch_id, service_id, status_id, start_at, end_at, source, notes_admin, created_by_user_id,
                     payment_required, payment_status, payment_amount_mxn, payment_due_at, payment_expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'web', ?, NULL, ?, ?, ?, NOW(), ?)",
                [
                    $code,
                    (int) $entry['user_id'],
                    $professionalId,
                    (int) $cancelled['branch_id'],
                    (int) $cancelled['service_id'],
                    $statusId,
                    $startSql,
                    $endSql,
                    'Promoción automática desde lista de espera. Entrada #' . (int) $entry['id'],
                    $payment['required'] ? 1 : 0,
                    $payment['required'] ? 'pending' : 'not_required',
                    $payment['amount'],
                    $payment['required'] ? date('Y-m-d H:i:s', time() + 20 * 60) : null,
                ]
            );
            $newAppointmentId = Database::lastId();

            Database::exec(
                "UPDATE waitlist_entries
                 SET status = 'promoted',
                     promoted_appointment_id = ?,
                     promoted_from_appointment_id = ?,
                     promoted_at = NOW()
                 WHERE id = ?",
                [$newAppointmentId, $cancelledAppointmentId, (int) $entry['id']]
            );

            $pdo->commit();

            Auth::audit('waitlist_promote', 'waitlist', (int) $entry['id'], [
                'appointment_id' => $newAppointmentId,
                'cancelled_appointment_id' => $cancelledAppointmentId,
            ]);

            if ($payment['required']) {
                PaymentService::createMercadoPagoCheckout($newAppointmentId);
            }
            $mail = EmailNotificationService::sendForAppointment($newAppointmentId, $payment['required'] ? 'appointment_created' : 'appointment_confirmed');
            if (!empty($mail['sent'])) {
                Database::exec('UPDATE waitlist_entries SET notified_at = NOW() WHERE id = ?', [(int) $entry['id']]);
            }

            return [
                'ok' => true,
                'waitlist_id' => (int) $entry['id'],
                'appointment_id' => $newAppointmentId,
                'code' => $code,
                'email_sent' => !empty($mail['sent']),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[waitlist-promote] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No fue posible promover la lista de espera.'];
        }
    }

    public static function rows(array $filters = []): array
    {
        self::ensureSchema();

        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'wl.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'wl.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['service_id'])) {
            $where[] = 'wl.service_id = ?';
            $params[] = (int) $filters['service_id'];
        }

        return Database::all(
            "SELECT wl.*, u.name AS client_name, u.email AS client_email, u.phone AS client_phone,
                    b.name AS branch_name, s.name AS service_name,
                    pa.code AS promoted_code, pa.start_at AS promoted_start_at
             FROM waitlist_entries wl
             JOIN users u ON u.id = wl.user_id
             JOIN branches b ON b.id = wl.branch_id
             JOIN services s ON s.id = wl.service_id
             LEFT JOIN appointments pa ON pa.id = wl.promoted_appointment_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(wl.status, 'waiting','promoted','cancelled'), wl.created_at ASC
             LIMIT 300",
            $params
        );
    }

    public static function cancel(int $id, int $actorId): bool
    {
        self::ensureSchema();
        $updated = Database::exec(
            "UPDATE waitlist_entries
             SET status = 'cancelled'
             WHERE id = ? AND status = 'waiting'",
            [$id]
        );
        if ($updated) {
            Auth::audit('waitlist_cancel', 'waitlist', $id, ['actor_id' => $actorId]);
        }
        return $updated > 0;
    }

    private static function validDate(string $date): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date ? $date : null;
    }
}

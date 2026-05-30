<?php
declare(strict_types=1);

final class AppointmentService
{
    public const BLOCKING_STATUSES = ['programada', 'confirmada', 'atendida'];

    public static function ensureAppointmentDurationSchema(): void
    {
        if (!self::columnExists('appointments', 'end_at')) {
            Database::exec('ALTER TABLE appointments ADD COLUMN end_at DATETIME NULL AFTER start_at');
        }

        if (self::columnExists('appointments', 'end_at')) {
            try {
                Database::exec(
                    "UPDATE appointments a
                     JOIN services s ON s.id = a.service_id
                     SET a.end_at = DATE_ADD(a.start_at, INTERVAL GREATEST(COALESCE(s.duration_min, 30), 5) MINUTE)
                     WHERE a.start_at IS NOT NULL
                       AND (a.end_at IS NULL OR a.end_at <= a.start_at)"
                );
            } catch (Throwable $e) {
                error_log('[appointments-duration] no pude normalizar end_at: ' . $e->getMessage());
            }
        }
    }

    public static function validateSchedule(
        int $branchId,
        int $serviceId,
        string $startAt,
        ?int $ignoreAppointmentId = null
    ): array {
        self::ensureAppointmentDurationSchema();
        self::ensureMachinerySchema();
        $errors = [];

        if (!$branchId) {
            $errors['branch_id'] = 'Selecciona una sucursal.';
        }
        if (!$serviceId) {
            $errors['service_id'] = 'Selecciona un servicio.';
        }
        if (!$startAt) {
            $errors['start_at'] = 'Selecciona fecha y hora.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $startAt)
            && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $startAt)) {
            return ['ok' => false, 'errors' => ['start_at' => 'Fecha y hora con formato invalido.']];
        }

        $startTs = strtotime(str_replace('T', ' ', $startAt));
        if (!$startTs) {
            return ['ok' => false, 'errors' => ['start_at' => 'Fecha y hora invalida.']];
        }

        $service = Database::one(
            'SELECT id, duration_min FROM services WHERE id = ? AND active = 1 LIMIT 1',
            [$serviceId]
        );
        if (!$service) {
            return ['ok' => false, 'errors' => ['service_id' => 'Servicio no disponible.']];
        }

        $branch = Database::one('SELECT id FROM branches WHERE id = ? AND active = 1 LIMIT 1', [$branchId]);
        if (!$branch) {
            return ['ok' => false, 'errors' => ['branch_id' => 'Sucursal no disponible.']];
        }

        $offered = Database::one(
            'SELECT 1 FROM service_branches WHERE branch_id = ? AND service_id = ? LIMIT 1',
            [$branchId, $serviceId]
        );
        if (!$offered) {
            return ['ok' => false, 'errors' => ['service_id' => 'Esta sucursal no ofrece el servicio seleccionado.']];
        }

        $durationMin = (int) $service['duration_min'];
        $endTs = $startTs + ($durationMin * 60);
        $startSql = date('Y-m-d H:i:s', $startTs);
        $endSql = date('Y-m-d H:i:s', $endTs);
        $date = date('Y-m-d', $startTs);

        $availabilityError = self::availabilityError($branchId, $date, $startTs, $endTs);
        if ($availabilityError) {
            return ['ok' => false, 'errors' => ['start_at' => $availabilityError]];
        }

        if (self::hasConflict($branchId, $startSql, $endSql, $ignoreAppointmentId)) {
            return ['ok' => false, 'errors' => ['start_at' => 'Ese horario ya no tiene cabinas disponibles.']];
        }
        if (self::hasMachineryConflict($branchId, $serviceId, $startSql, $endSql, $ignoreAppointmentId)) {
            return ['ok' => false, 'errors' => ['start_at' => 'La maquinaria necesaria para ese servicio ya está ocupada en ese horario.']];
        }

        return [
            'ok' => true,
            'errors' => [],
            'start_sql' => $startSql,
            'end_sql' => $endSql,
            'duration_min' => $durationMin,
        ];
    }

    public static function hasConflict(
        int $branchId,
        string $startSql,
        string $endSql,
        ?int $ignoreAppointmentId = null,
        bool $forUpdate = false
    ): bool
    {
        $params = [$branchId, $endSql, $startSql];
        $ignoreSql = '';
        if ($ignoreAppointmentId) {
            $ignoreSql = ' AND id <> ?';
            $params[] = $ignoreAppointmentId;
        }

        $paymentSql = '';
        if (self::columnExists('appointments', 'payment_status')) {
            $paymentSql = " AND (
                 payment_required = 0
                 OR payment_status = 'paid'
                 OR (payment_status = 'pending' AND payment_expires_at > NOW())
               )";
        }
        $lockSql = $forUpdate ? ' FOR UPDATE' : '';
        $rows = Database::all(
            "SELECT id FROM appointments
             WHERE branch_id = ?
               AND status_id IN (SELECT id FROM appointment_statuses WHERE slug IN ('programada','confirmada','atendida'))
               AND start_at < ? AND end_at > ?
               {$paymentSql}
               {$ignoreSql}
             {$lockSql}",
            $params
        );

        return count($rows) >= self::branchCabinCapacity($branchId);
    }

    public static function availableCabins(
        int $branchId,
        string $startSql,
        string $endSql,
        ?int $ignoreAppointmentId = null
    ): int {
        $params = [$branchId, $endSql, $startSql];
        $ignoreSql = '';
        if ($ignoreAppointmentId) {
            $ignoreSql = ' AND a.id <> ?';
            $params[] = $ignoreAppointmentId;
        }

        $paymentSql = '';
        if (self::columnExists('appointments', 'payment_status')) {
            $paymentSql = " AND (
                 a.payment_required = 0
                 OR a.payment_status = 'paid'
                 OR (a.payment_status = 'pending' AND a.payment_expires_at > NOW())
               )";
        }

        $row = Database::one(
            "SELECT COUNT(*) AS n
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.branch_id = ?
               AND st.slug IN ('programada','confirmada','atendida')
               AND a.start_at < ? AND a.end_at > ?
               {$paymentSql}
               {$ignoreSql}",
            $params
        );

        return max(0, self::branchCabinCapacity($branchId) - (int) ($row['n'] ?? 0));
    }

    public static function branchCabinCapacity(int $branchId): int
    {
        $select = self::columnExists('branches', 'cabin_capacity') ? 'slug, name, cabin_capacity' : 'slug, name';
        $branch = Database::one("SELECT {$select} FROM branches WHERE id = ? LIMIT 1", [$branchId]);
        if (isset($branch['cabin_capacity']) && (int) $branch['cabin_capacity'] > 0) {
            return (int) $branch['cabin_capacity'];
        }
        $key = mb_strtolower(($branch['slug'] ?? '') . ' ' . ($branch['name'] ?? ''));
        return (str_contains($key, 'queretaro') || str_contains($key, 'querétaro')) ? 2 : 3;
    }

    public static function ensureCabinCapacityColumn(): void
    {
        if (!self::columnExists('branches', 'cabin_capacity')) {
            Database::exec('ALTER TABLE branches ADD COLUMN cabin_capacity TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER gmaps_url');
            Database::exec("UPDATE branches SET cabin_capacity = 2 WHERE LOWER(CONCAT(slug, ' ', name)) LIKE '%queretaro%' OR LOWER(CONCAT(slug, ' ', name)) LIKE '%querétaro%'");
        }
    }

    public static function ensureMachinerySchema(): void
    {
        if (!self::tableExists('branch_service_resources')) {
            Database::exec(
                "CREATE TABLE branch_service_resources (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    branch_id SMALLINT UNSIGNED NOT NULL,
                    resource_key VARCHAR(80) NOT NULL,
                    name VARCHAR(120) NOT NULL,
                    capacity TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_branch_resource (branch_id, resource_key),
                    INDEX idx_bsr_branch_active (branch_id, active),
                    CONSTRAINT fk_bsr_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        foreach (Database::all('SELECT id FROM branches WHERE active = 1') as $branch) {
            Database::exec(
                "INSERT IGNORE INTO branch_service_resources (branch_id, resource_key, name, capacity, active)
                 VALUES (?, 'depilacion_laser', 'Máquina de depilación láser', 1, 1)",
                [(int) $branch['id']]
            );
        }
    }

    public static function serviceResourceKey(int $serviceId): ?string
    {
        $service = Database::one("SELECT category, name, COALESCE(item_type, 'service') AS item_type FROM services WHERE id = ? LIMIT 1", [$serviceId]);
        if (!$service) {
            return null;
        }
        if (($service['item_type'] ?? 'service') === 'package' && self::tableExists('service_package_items')) {
            $items = Database::all(
                'SELECT s.id
                 FROM service_package_items spi
                 JOIN services s ON s.id = spi.included_service_id
                 WHERE spi.package_service_id = ?',
                [$serviceId]
            );
            foreach ($items as $item) {
                $key = self::serviceResourceKey((int) $item['id']);
                if ($key) {
                    return $key;
                }
            }
        }
        $raw = mb_strtolower(trim((string) ($service['category'] ?: $service['name'])));
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw) ?: $raw;
        if (str_contains($normalized, 'depil') || str_contains($normalized, 'laser')) {
            return 'depilacion_laser';
        }
        return null;
    }

    public static function resourceCapacity(int $branchId, ?string $resourceKey): int
    {
        if (!$resourceKey) {
            return PHP_INT_MAX;
        }
        self::ensureMachinerySchema();
        $row = Database::one(
            'SELECT capacity FROM branch_service_resources WHERE branch_id = ? AND resource_key = ? AND active = 1 LIMIT 1',
            [$branchId, $resourceKey]
        );
        return max(0, (int) ($row['capacity'] ?? 0));
    }

    public static function availableMachineUnits(
        int $branchId,
        int $serviceId,
        string $startSql,
        string $endSql,
        ?int $ignoreAppointmentId = null,
        bool $forUpdate = false
    ): int {
        $resourceKey = self::serviceResourceKey($serviceId);
        if (!$resourceKey) {
            return PHP_INT_MAX;
        }
        $capacity = self::resourceCapacity($branchId, $resourceKey);
        if ($capacity <= 0) {
            return 0;
        }

        $params = [$branchId, $endSql, $startSql];
        $ignoreSql = '';
        if ($ignoreAppointmentId) {
            $ignoreSql = ' AND a.id <> ?';
            $params[] = $ignoreAppointmentId;
        }
        $paymentSql = '';
        if (self::columnExists('appointments', 'payment_status')) {
            $paymentSql = " AND (
                 a.payment_required = 0
                 OR a.payment_status = 'paid'
                 OR (a.payment_status = 'pending' AND a.payment_expires_at > NOW())
               )";
        }
        $selectSql = $forUpdate ? 'SELECT a.id' : 'SELECT COUNT(*) AS n';
        $lockSql = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "{$selectSql}
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN services s ON s.id = a.service_id
             WHERE a.branch_id = ?
               AND st.slug IN ('programada','confirmada','atendida')
               AND a.start_at < ? AND a.end_at > ?
               AND (
                    LOWER(s.category) LIKE '%depil%'
                    OR LOWER(s.category) LIKE '%laser%'
                    OR LOWER(s.name) LIKE '%depil%'
                    OR LOWER(s.name) LIKE '%laser%'
                    OR EXISTS (
                        SELECT 1
                        FROM service_package_items spi
                        JOIN services si ON si.id = spi.included_service_id
                        WHERE spi.package_service_id = s.id
                          AND (
                            LOWER(si.category) LIKE '%depil%'
                            OR LOWER(si.category) LIKE '%laser%'
                            OR LOWER(si.name) LIKE '%depil%'
                            OR LOWER(si.name) LIKE '%laser%'
                          )
                    )
               )
               {$paymentSql}
               {$ignoreSql}
             {$lockSql}";
        if ($forUpdate) {
            $rows = Database::all($sql, $params);
            return max(0, $capacity - count($rows));
        }
        $row = Database::one($sql, $params);

        return max(0, $capacity - (int) ($row['n'] ?? 0));
    }

    public static function hasMachineryConflict(
        int $branchId,
        int $serviceId,
        string $startSql,
        string $endSql,
        ?int $ignoreAppointmentId = null,
        bool $forUpdate = false
    ): bool {
        return self::availableMachineUnits($branchId, $serviceId, $startSql, $endSql, $ignoreAppointmentId, $forUpdate) <= 0;
    }

    /**
     * Auto-migracion de la fase 3 (profesionales). Idempotente.
     * Se llama desde admin/profesionales.php y desde admin/cita-form.php
     * por si el usuario olvido correr phase3_professionals.sql.
     */
    public static function ensureProfessionalSchema(): void
    {
        // 1) Rol professional
        if (!Database::one("SELECT id FROM roles WHERE slug = 'professional' LIMIT 1")) {
            Database::exec(
                "INSERT INTO roles (slug, name, description) VALUES
                 ('professional', 'Profesional / Especialista', 'Realiza tratamientos, atiende citas en cabina, pertenece a una o mas sucursales.')"
            );
        }

        // 2) Tabla user_branches
        if (!self::tableExists('user_branches')) {
            Database::exec(
                "CREATE TABLE user_branches (
                    user_id    INT UNSIGNED NOT NULL,
                    branch_id  SMALLINT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, branch_id),
                    INDEX idx_ub_branch (branch_id),
                    CONSTRAINT fk_ub_user   FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE,
                    CONSTRAINT fk_ub_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        // 3) Columna appointments.professional_id
        if (!self::columnExists('appointments', 'professional_id')) {
            Database::exec(
                'ALTER TABLE appointments
                   ADD COLUMN professional_id INT UNSIGNED NULL AFTER user_id,
                   ADD INDEX idx_appt_prof_start (professional_id, start_at),
                   ADD CONSTRAINT fk_appt_prof FOREIGN KEY (professional_id) REFERENCES users(id) ON DELETE SET NULL'
            );
        } else {
            $idx = Database::one(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
                   AND INDEX_NAME = 'idx_appt_prof_start' LIMIT 1"
            );
            if (!$idx) {
                try { Database::exec('ALTER TABLE appointments ADD INDEX idx_appt_prof_start (professional_id, start_at)'); } catch (\Throwable $e) { /* noop */ }
            }
        }
    }

    /**
     * Devuelve true si el profesional ya tiene OTRA cita activa que solape
     * el rango [startSql, endSql].
     */
    public static function professionalHasConflict(
        int $professionalId,
        string $startSql,
        string $endSql,
        ?int $ignoreAppointmentId = null,
        bool $forUpdate = false
    ): bool {
        if ($professionalId <= 0) return false;
        if (!self::columnExists('appointments', 'professional_id')) return false;

        $params = [$professionalId, $endSql, $startSql];
        $ignoreSql = '';
        if ($ignoreAppointmentId) {
            $ignoreSql = ' AND id <> ?';
            $params[] = $ignoreAppointmentId;
        }
        $paymentSql = '';
        if (self::columnExists('appointments', 'payment_status')) {
            $paymentSql = " AND (
                 payment_required = 0
                 OR payment_status = 'paid'
                 OR (payment_status = 'pending' AND payment_expires_at > NOW())
               )";
        }
        $lockSql = $forUpdate ? ' FOR UPDATE' : '';

        $row = Database::one(
            "SELECT id FROM appointments
             WHERE professional_id = ?
               AND status_id IN (SELECT id FROM appointment_statuses WHERE slug IN ('programada','confirmada','atendida'))
               AND start_at < ? AND end_at > ?
               {$paymentSql}
               {$ignoreSql}
             LIMIT 1{$lockSql}",
            $params
        );
        return (bool) $row;
    }

    /**
     * Valida que un profesional pueda ser asignado a una cita.
     * Reglas:
     *  - Existe y tiene rol 'professional'
     *  - Esta activo
     *  - Pertenece a la sucursal de la cita (via user_branches)
     *  - No tiene otra cita que solape ese horario
     */
    public static function validateProfessionalAssignment(
        int $professionalId,
        int $branchId,
        string $startSql,
        string $endSql,
        ?int $ignoreAppointmentId = null
    ): array {
        if ($professionalId <= 0) {
            return ['ok' => false, 'error' => 'Selecciona un profesional.'];
        }

        $prof = Database::one(
            "SELECT u.id, u.name, u.active
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND r.slug = 'professional' LIMIT 1",
            [$professionalId]
        );
        if (!$prof) {
            return ['ok' => false, 'error' => 'El profesional seleccionado no existe.'];
        }
        if (!(int) $prof['active']) {
            return ['ok' => false, 'error' => 'No se pueden asignar citas a profesionales inactivos.'];
        }

        if (!self::tableExists('user_branches')) {
            self::ensureProfessionalSchema();
        }

        $belongs = Database::one(
            'SELECT 1 FROM user_branches WHERE user_id = ? AND branch_id = ? LIMIT 1',
            [$professionalId, $branchId]
        );
        if (!$belongs) {
            return ['ok' => false, 'error' => 'El profesional no atiende en esta sucursal.'];
        }

        if (self::professionalHasConflict($professionalId, $startSql, $endSql, $ignoreAppointmentId)) {
            return ['ok' => false, 'error' => 'Este profesional ya tiene una cita en ese horario, por favor selecciona otro horario o profesional.'];
        }

        return ['ok' => true, 'professional' => $prof];
    }

    /**
     * Auto-migracion de la fase 4 (recibos / nota de venta + correo empatico).
     * Anade columnas en appointments si faltan. Idempotente.
     */
    public static function ensureReceiptSchema(): void
    {
        $cols = [
            'receipt_folio'         => 'VARCHAR(24) NULL',
            'receipt_sent'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            'receipt_sent_at'       => 'DATETIME NULL',
            'attended_at'           => 'DATETIME NULL',
            'confirmed_at'          => 'DATETIME NULL',
            'empathy_email_sent'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'empathy_email_sent_at' => 'DATETIME NULL',
        ];
        foreach ($cols as $name => $def) {
            try {
                if (!self::columnExists('appointments', $name)) {
                    Database::exec("ALTER TABLE appointments ADD COLUMN {$name} {$def}");
                }
            } catch (Throwable $e) {
                error_log('[appointments-package-billing] no pude agregar columna ' . $name . ': ' . $e->getMessage());
                return;
            }
        }
        // UNIQUE en folio si la columna existe pero no tiene indice
        if (self::columnExists('appointments', 'receipt_folio')) {
            $idx = Database::one(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
                   AND COLUMN_NAME = 'receipt_folio' LIMIT 1"
            );
            if (!$idx) {
                try { Database::exec('ALTER TABLE appointments ADD UNIQUE INDEX uq_appt_receipt_folio (receipt_folio)'); } catch (\Throwable $e) { /* noop */ }
            }
        }
    }

    public static function ensurePackageBillingSchema(): void
    {
        $cols = [
            'billing_type' => "ENUM('standard','package_sale','package_session') NOT NULL DEFAULT 'standard'",
            'package_parent_appointment_id' => 'INT UNSIGNED NULL',
            'package_session_number' => 'SMALLINT UNSIGNED NULL',
            'package_total_sessions' => 'SMALLINT UNSIGNED NULL',
        ];
        foreach ($cols as $name => $def) {
            if (!self::columnExists('appointments', $name)) {
                Database::exec("ALTER TABLE appointments ADD COLUMN {$name} {$def}");
            }
        }
        try {
            $idx = Database::one(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
                   AND INDEX_NAME = 'idx_appt_package_parent' LIMIT 1"
            );
            if (!$idx) {
                Database::exec('ALTER TABLE appointments ADD INDEX idx_appt_package_parent (package_parent_appointment_id)');
            }
        } catch (Throwable $e) {
            /* no-op: no bloquea la operacion principal */
        }
    }

    public static function isPackageIncludedSession(array $appointment): bool
    {
        return (string) ($appointment['billing_type'] ?? 'standard') === 'package_session';
    }

    /**
     * Devuelve los slugs de transiciones permitidas desde un estado dado.
     * Reglas clinicas:
     *   programada  -> confirmada | cancelada
     *   confirmada  -> atendida   | no_asistio | cancelada
     *   atendida    -> (final, solo recibo)
     *   cancelada   -> (final)
     *   no_asistio  -> (final)
     */
    public static function allowedTransitions(string $fromSlug): array
    {
        $map = [
            'programada' => ['confirmada', 'cancelada'],
            'confirmada' => ['atendida', 'no_asistio', 'cancelada'],
            'atendida'   => [],
            'cancelada'  => [],
            'no_asistio' => [],
        ];
        return $map[$fromSlug] ?? [];
    }

    public static function canCloseOut(string $appointmentStartAt, ?string $appointmentEndAt = null): bool
    {
        $startTs = strtotime($appointmentStartAt);
        if (!$startTs) {
            return false;
        }
        $endTs = $appointmentEndAt ? strtotime($appointmentEndAt) : false;
        if (!$endTs || $endTs < $startTs) {
            $endTs = $startTs;
        }
        return time() >= $endTs;
    }

    public static function statusTimingError(string $toSlug, string $appointmentStartAt, ?string $appointmentEndAt = null): ?string
    {
        if (!in_array($toSlug, ['atendida', 'no_asistio'], true)) {
            return null;
        }
        $startTs = strtotime($appointmentStartAt);
        if (!$startTs) {
            return 'La fecha de la cita no es válida para cambiar el estado.';
        }
        if (!self::canCloseOut($appointmentStartAt, $appointmentEndAt)) {
            return $toSlug === 'atendida'
                ? 'Solo puedes marcar una cita confirmada como Atendida cuando su hora de inicio y fin ya pasaron.'
                : 'Solo puedes marcar No asistió cuando la hora de inicio y fin de la cita ya pasaron.';
        }
        return null;
    }

    /**
     * Cambia el estado de una cita validando reglas clinicas. Devuelve
     * ['ok' => bool, 'error' => string?, 'appointment' => array?, 'receipt_folio' => string?].
     */
    public static function transitionStatus(int $appointmentId, string $toSlug, int $actorUserId, ?string $reason = null): array
    {
        self::ensureReceiptSchema();
        self::ensurePackageBillingSchema();

        $appt = Database::one(
            "SELECT a.*, st.slug AS status_slug, st.name AS status_name
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.id = ? LIMIT 1",
            [$appointmentId]
        );
        if (!$appt) {
            return ['ok' => false, 'error' => 'La cita no existe.'];
        }

        $from = (string) $appt['status_slug'];
        if ($from === $toSlug) {
            return ['ok' => false, 'error' => 'La cita ya se encuentra en ese estado.'];
        }

        $allowed = self::allowedTransitions($from);
        if (!in_array($toSlug, $allowed, true)) {
            return ['ok' => false, 'error' => 'Transición no válida desde "' . $from . '".'];
        }
        $timingError = self::statusTimingError($toSlug, (string) $appt['start_at'], $appt['end_at'] ?? null);
        if ($timingError) {
            return ['ok' => false, 'error' => $timingError];
        }

        $newStatus = Database::one('SELECT id FROM appointment_statuses WHERE slug = ? LIMIT 1', [$toSlug]);
        if (!$newStatus) {
            return ['ok' => false, 'error' => 'Estado destino no encontrado.'];
        }

        // Reglas adicionales
        if ($toSlug === 'atendida') {
            if (empty($appt['professional_id'])) {
                return ['ok' => false, 'error' => 'Asigna primero un profesional para marcar la cita como Atendida.'];
            }
        }
        if ($toSlug === 'confirmada') {
            if (self::columnExists('appointments', 'professional_id') && empty($appt['professional_id'])) {
                return ['ok' => false, 'error' => 'Asigna un profesional antes de confirmar la cita.'];
            }
        }

        $sets = ['status_id = ?'];
        $params = [(int) $newStatus['id']];

        if ($toSlug === 'confirmada') {
            $sets[] = 'confirmed_at = NOW()';
        }
        if ($toSlug === 'atendida') {
            $sets[] = 'attended_at = NOW()';
            // Las sesiones ya pagadas de paquete no generan nuevo folio ni recibo.
            if (!self::isPackageIncludedSession($appt) && empty($appt['receipt_folio'])) {
                $folio = self::nextReceiptFolio();
                $sets[] = 'receipt_folio = ?';
                $params[] = $folio;
            }
        }
        if ($toSlug === 'cancelada') {
            $sets[] = 'cancelled_at = NOW()';
            $sets[] = 'cancelled_by_user_id = ?';
            $params[] = $actorUserId;
            $sets[] = 'cancel_reason = ?';
            $params[] = $reason ?: null;
        }

        $params[] = $appointmentId;
        Database::exec(
            'UPDATE appointments SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        // Recarga con datos completos para devolver al caller
        $appt = Database::one(
            "SELECT a.*, st.slug AS status_slug, st.name AS status_name, st.color_hex
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.id = ? LIMIT 1",
            [$appointmentId]
        );

        Auth::audit('appointment_transition', 'appointment', $appointmentId, [
            'code'   => $appt['code'] ?? null,
            'from'   => $from,
            'to'     => $toSlug,
            'reason' => $reason,
        ]);

        $waitlist = null;
        if ($toSlug === 'cancelada') {
            $waitlist = WaitlistService::promoteForCancelledAppointment($appointmentId);
        }

        return [
            'ok' => true,
            'appointment'   => $appt,
            'receipt_folio' => $appt['receipt_folio'] ?? null,
            'waitlist' => $waitlist,
        ];
    }

    /** Genera un folio de recibo único: BNC-NV-YYYY-NNNNN */
    public static function nextReceiptFolio(): string
    {
        for ($i = 0; $i < 6; $i++) {
            $candidate = sprintf('BNC-NV-%d-%05d', (int) date('Y'), random_int(1, 99999));
            $exists = Database::one('SELECT 1 FROM appointments WHERE receipt_folio = ? LIMIT 1', [$candidate]);
            if (!$exists) return $candidate;
        }
        // Fallback con microtime si todos los random colisionan
        return 'BNC-NV-' . date('Ymd') . '-' . substr((string) microtime(true), -6);
    }

    /** Lista de profesionales activos asignados a una sucursal. */
    public static function professionalsByBranch(int $branchId): array
    {
        if (!self::tableExists('user_branches')) return [];
        return Database::all(
            "SELECT u.id, u.name, u.email, u.phone
             FROM users u
             JOIN roles r ON r.id = u.role_id
             JOIN user_branches ub ON ub.user_id = u.id
             WHERE r.slug = 'professional' AND u.active = 1 AND ub.branch_id = ?
             ORDER BY u.name",
            [$branchId]
        );
    }

    private static function tableExists(string $table): bool
    {
        try {
            return (bool) Database::one(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                [$table]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function availabilityError(int $branchId, string $date, int $startTs, int $endTs): ?string
    {
        $exception = Database::one(
            'SELECT type, time_start, time_end, reason FROM availability_exceptions WHERE branch_id = ? AND date = ? LIMIT 1',
            [$branchId, $date]
        );

        if ($exception && $exception['type'] === 'closed') {
            return $exception['reason']
                ? 'La sucursal permanece cerrada ese dia: ' . $exception['reason'] . '.'
                : 'La sucursal permanece cerrada ese dia.';
        }

        $windows = [];
        if ($exception && $exception['type'] === 'custom') {
            if ($exception['time_start'] && $exception['time_end']) {
                $windows[] = [$exception['time_start'], $exception['time_end']];
            }
        } else {
            $weekday = (int) date('w', strtotime($date));
            $rows = Database::all(
                'SELECT time_start, time_end FROM availability
                 WHERE branch_id = ? AND weekday = ? AND active = 1
                 ORDER BY time_start',
                [$branchId, $weekday]
            );
            foreach ($rows as $row) {
                $windows[] = [$row['time_start'], $row['time_end']];
            }
        }

        if (!$windows) {
            return 'La sucursal no tiene horario laboral ese dia.';
        }

        foreach ($windows as [$from, $to]) {
            $windowStart = strtotime($date . ' ' . $from);
            $windowEnd = strtotime($date . ' ' . $to);
            if ($startTs >= $windowStart && $endTs <= $windowEnd) {
                return null;
            }
        }

        return 'La cita queda fuera del horario laboral de la sucursal.';
    }

    public static function sourceOptions(): array
    {
        $values = self::sourceEnumValues();
        $labels = [
            'phone' => 'Telefono',
            'email' => 'Correo electronico',
            'social' => 'Redes sociales',
            'presencial' => 'Presencial',
            'whatsapp' => 'WhatsApp',
            'web' => 'Sitio web',
        ];

        $preferred = ['phone', 'email', 'social', 'presencial', 'whatsapp', 'web'];
        $options = [];
        foreach ($preferred as $value) {
            if (!$values || in_array($value, $values, true)) {
                $options[$value] = $labels[$value];
            }
        }
        return $options;
    }

    private static function sourceEnumValues(): array
    {
        try {
            $row = Database::one("SHOW COLUMNS FROM appointments LIKE 'source'");
            if (!$row || empty($row['Type'])) {
                return [];
            }
            preg_match_all("/'([^']+)'/", $row['Type'], $matches);
            return $matches[1] ?? [];
        } catch (Throwable $e) {
            return [];
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

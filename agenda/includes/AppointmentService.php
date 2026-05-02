<?php
declare(strict_types=1);

final class AppointmentService
{
    public const BLOCKING_STATUSES = ['programada', 'confirmada', 'atendida'];

    public static function validateSchedule(
        int $branchId,
        int $serviceId,
        string $startAt,
        ?int $ignoreAppointmentId = null
    ): array {
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

        $lockSql = $forUpdate ? ' FOR UPDATE' : '';
        $rows = Database::all(
            "SELECT id FROM appointments
             WHERE branch_id = ?
               AND status_id IN (SELECT id FROM appointment_statuses WHERE slug IN ('programada','confirmada','atendida'))
               AND start_at < ? AND end_at > ?
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

        $row = Database::one(
            "SELECT COUNT(*) AS n
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             WHERE a.branch_id = ?
               AND st.slug IN ('programada','confirmada','atendida')
               AND a.start_at < ? AND a.end_at > ?
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
            return (bool) Database::one("SHOW COLUMNS FROM {$table} LIKE ?", [$column]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

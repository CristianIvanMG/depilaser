<?php
declare(strict_types=1);

final class ReportService
{
    public static function filters(array $input): array
    {
        $today = new DateTimeImmutable('today');
        $defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
        $defaultTo = $today->modify('last day of this month')->format('Y-m-d');

        $from = self::validDate((string) ($input['from'] ?? '')) ?: $defaultFrom;
        $to = self::validDate((string) ($input['to'] ?? '')) ?: $defaultTo;

        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }

        $maxTo = date('Y-m-d', strtotime($from . ' +366 days'));
        if (strtotime($to) > strtotime($maxTo)) {
            $to = $maxTo;
        }

        $allowedTypes = ['general', 'citas_sucursal', 'ingresos', 'asistencia', 'ocupacion'];
        $reportType = (string) ($input['report_type'] ?? 'general');
        if (!in_array($reportType, $allowedTypes, true)) {
            $reportType = 'general';
        }

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => max(0, (int) ($input['branch_id'] ?? 0)),
            'professional_id' => max(0, (int) ($input['professional_id'] ?? 0)),
            'report_type' => $reportType,
        ];
    }

    public static function branches(): array
    {
        return Database::all('SELECT id, name, cabin_capacity FROM branches WHERE active=1 ORDER BY display_order, name');
    }

    public static function professionals(): array
    {
        return Database::all(
            "SELECT u.id, u.name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'professional' AND u.active = 1
             ORDER BY u.name"
        );
    }

    public static function dashboard(array $filters): array
    {
        return [
            'kpis' => self::kpis($filters),
            'appointmentsByMonth' => self::appointmentsByMonth($filters),
            'appointmentsByBranch' => self::appointmentsByBranch($filters),
            'appointmentsByStatus' => self::appointmentsByStatus($filters),
            'occupancyByBranch' => self::occupancyByBranch($filters),
            'appointmentsByProfessional' => self::appointmentsByProfessional($filters),
            'demandByHour' => self::demandByHour($filters),
            'revenueByMonth' => self::revenueByMonth($filters),
            'revenueByBranch' => self::revenueByBranch($filters),
            'newClientsByMonth' => self::newClientsByMonth($filters),
            'professionalPerformance' => self::professionalPerformance($filters),
        ];
    }

    public static function kpis(array $f): array
    {
        $base = self::where($f);
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    SUM(st.slug = 'atendida') AS attended,
                    SUM(st.slug = 'cancelada') AS cancelled,
                    SUM(st.slug = 'no_asistio') AS no_show,
                    COALESCE(SUM(CASE WHEN st.slug='atendida' THEN s.price_mxn ELSE 0 END),0) AS revenue
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN services s ON s.id = a.service_id
             {$base['sql']}",
            $base['params']
        ) ?: [];

        $clients = Database::one(
            "SELECT COUNT(*) AS n
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'cliente' AND u.created_at >= ? AND u.created_at < DATE_ADD(?, INTERVAL 1 DAY)",
            [$f['from'], $f['to']]
        );

        $occupancy = self::occupancyByBranch($f);
        $avgOccupancy = $occupancy
            ? array_sum(array_column($occupancy, 'occupancy_pct')) / max(1, count($occupancy))
            : 0;

        return [
            'total' => (int) ($row['total'] ?? 0),
            'attended' => (int) ($row['attended'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
            'no_show' => (int) ($row['no_show'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0),
            'new_clients' => (int) ($clients['n'] ?? 0),
            'occupancy_pct' => round($avgOccupancy, 1),
        ];
    }

    public static function appointmentsByMonth(array $f): array
    {
        $base = self::where($f);
        return Database::all(
            "SELECT DATE_FORMAT(a.start_at, '%Y-%m') AS label, COUNT(*) AS value
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             {$base['sql']}
             GROUP BY label ORDER BY label",
            $base['params']
        );
    }

    public static function appointmentsByBranch(array $f): array
    {
        $base = self::where($f);
        return Database::all(
            "SELECT b.name AS label, COUNT(*) AS value
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN branches b ON b.id = a.branch_id
             {$base['sql']}
             GROUP BY b.id, b.name ORDER BY value DESC",
            $base['params']
        );
    }

    public static function appointmentsByStatus(array $f): array
    {
        $base = self::where($f);
        return Database::all(
            "SELECT st.name AS label, st.slug, st.color_hex, COUNT(*) AS value
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             {$base['sql']}
             GROUP BY st.id, st.name, st.slug, st.color_hex ORDER BY st.id",
            $base['params']
        );
    }

    public static function appointmentsByProfessional(array $f): array
    {
        $base = self::where($f);
        return Database::all(
            "SELECT COALESCE(pr.name, 'Sin asignar') AS label, COUNT(*) AS value
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             LEFT JOIN users pr ON pr.id = a.professional_id
             {$base['sql']}
             GROUP BY label ORDER BY value DESC LIMIT 12",
            $base['params']
        );
    }

    public static function demandByHour(array $f): array
    {
        $base = self::where($f);
        return Database::all(
            "SELECT DATE_FORMAT(a.start_at, '%H:00') AS label,
                    COUNT(*) AS value,
                    SUM(st.slug NOT IN ('cancelada','no_asistio')) AS effective
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             {$base['sql']}
             GROUP BY label ORDER BY label",
            $base['params']
        );
    }

    public static function revenueByMonth(array $f): array
    {
        $base = self::where($f, "st.slug = 'atendida'");
        return Database::all(
            "SELECT DATE_FORMAT(a.start_at, '%Y-%m') AS label,
                    COALESCE(SUM(s.price_mxn),0) AS value
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN services s ON s.id = a.service_id
             {$base['sql']}
             GROUP BY label ORDER BY label",
            $base['params']
        );
    }

    public static function revenueByBranch(array $f): array
    {
        $base = self::where($f, "st.slug = 'atendida'");
        return Database::all(
            "SELECT b.name AS label, COALESCE(SUM(s.price_mxn),0) AS value
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             {$base['sql']}
             GROUP BY b.id, b.name ORDER BY value DESC",
            $base['params']
        );
    }

    public static function newClientsByMonth(array $f): array
    {
        return Database::all(
            "SELECT DATE_FORMAT(u.created_at, '%Y-%m') AS label, COUNT(*) AS value
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'cliente'
               AND u.created_at >= ?
               AND u.created_at < DATE_ADD(?, INTERVAL 1 DAY)
             GROUP BY label ORDER BY label",
            [$f['from'], $f['to']]
        );
    }

    public static function professionalPerformance(array $f): array
    {
        $base = self::where($f);
        return Database::all(
            "SELECT COALESCE(pr.name, 'Sin asignar') AS professional,
                    COUNT(*) AS total_appointments,
                    SUM(st.slug = 'atendida') AS attended,
                    SUM(st.slug = 'no_asistio') AS no_show,
                    COALESCE(SUM(CASE WHEN st.slug='atendida' THEN s.price_mxn ELSE 0 END),0) AS revenue
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN services s ON s.id = a.service_id
             LEFT JOIN users pr ON pr.id = a.professional_id
             {$base['sql']}
             GROUP BY professional
             ORDER BY revenue DESC, attended DESC, total_appointments DESC
             LIMIT 20",
            $base['params']
        );
    }

    public static function createdByPerformance(array $f): array
    {
        $base = self::where($f);
        return Database::all(
            "SELECT COALESCE(cu.name, 'Sistema / Web') AS creator,
                    COUNT(*) AS created_count,
                    SUM(st.slug = 'atendida') AS converted_attended,
                    COALESCE(SUM(CASE WHEN st.slug='atendida' THEN s.price_mxn ELSE 0 END),0) AS revenue
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN services s ON s.id = a.service_id
             LEFT JOIN users cu ON cu.id = a.created_by_user_id
             {$base['sql']}
             GROUP BY creator
             ORDER BY revenue DESC, created_count DESC
             LIMIT 20",
            $base['params']
        );
    }

    public static function occupancyByBranch(array $f): array
    {
        $joinFilters = " AND a.start_at >= ? AND a.start_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $params = [$f['from'], $f['to']];
        if (!empty($f['professional_id'])) {
            $joinFilters .= ' AND a.professional_id = ?';
            $params[] = (int) $f['professional_id'];
        }
        if (!empty($f['branch_id'])) {
            $params[] = (int) $f['branch_id'];
        }

        $booked = Database::all(
            "SELECT b.id, b.name AS label, b.cabin_capacity,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, a.start_at, a.end_at)),0) AS booked_minutes,
                    COUNT(a.id) AS appointments
             FROM branches b
             LEFT JOIN appointments a ON a.branch_id = b.id{$joinFilters}
             LEFT JOIN appointment_statuses st ON st.id = a.status_id
             WHERE b.active = 1"
             . ($f['branch_id'] ? ' AND b.id = ?' : '') .
             " AND (a.id IS NULL OR st.slug NOT IN ('cancelada','no_asistio'))
             GROUP BY b.id, b.name, b.cabin_capacity
             ORDER BY b.name",
            $params
        );

        $openMinutes = self::openMinutesByBranch($f);
        return array_map(function ($row) use ($openMinutes) {
            $capacity = max(1, (int) ($row['cabin_capacity'] ?? 1));
            $available = ($openMinutes[(int) $row['id']] ?? 0) * $capacity;
            $booked = (int) ($row['booked_minutes'] ?? 0);
            return [
                'label' => $row['label'],
                'appointments' => (int) $row['appointments'],
                'booked_minutes' => $booked,
                'available_minutes' => $available,
                'occupancy_pct' => $available > 0 ? round(($booked / $available) * 100, 1) : 0,
            ];
        }, $booked);
    }

    private static function openMinutesByBranch(array $f): array
    {
        $branches = self::branches();
        $filterBranch = (int) ($f['branch_id'] ?? 0);
        if ($filterBranch) {
            $branches = array_values(array_filter($branches, fn($b) => (int) $b['id'] === $filterBranch));
        }

        $availability = Database::all('SELECT branch_id, weekday, time_start, time_end FROM availability WHERE active = 1');
        $byBranchDay = [];
        foreach ($availability as $row) {
            $byBranchDay[(int) $row['branch_id']][(int) $row['weekday']][] = [$row['time_start'], $row['time_end']];
        }

        $out = [];
        $start = new DateTimeImmutable($f['from']);
        $end = new DateTimeImmutable($f['to']);
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $weekday = (int) $d->format('w');
            foreach ($branches as $branch) {
                $branchId = (int) $branch['id'];
                foreach ($byBranchDay[$branchId][$weekday] ?? [] as [$from, $to]) {
                    $out[$branchId] = ($out[$branchId] ?? 0) + max(0, (strtotime($to) - strtotime($from)) / 60);
                }
            }
        }
        return $out;
    }

    private static function where(array $f, ?string $extra = null): array
    {
        $where = ['a.start_at >= ?', 'a.start_at < DATE_ADD(?, INTERVAL 1 DAY)'];
        $params = [$f['from'], $f['to']];

        if (!empty($f['branch_id'])) {
            $where[] = 'a.branch_id = ?';
            $params[] = (int) $f['branch_id'];
        }
        if (!empty($f['professional_id'])) {
            $where[] = 'a.professional_id = ?';
            $params[] = (int) $f['professional_id'];
        }
        if ($extra) {
            $where[] = $extra;
        }

        return ['sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
    }

    private static function validDate(string $date): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date ? $date : null;
    }
}

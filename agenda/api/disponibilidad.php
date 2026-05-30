<?php
/**
 * GET /agenda/api/disponibilidad.php?branch=1&service=4&date=2026-05-10
 * Devuelve slots libres para esa combinación.
 *
 * Algoritmo:
 *   1. Lee horario base de la sucursal para ese weekday
 *   2. Aplica excepciones (closed, custom)
 *   3. Trocea en bloques del tamaño = service.duration_min
 *   4. Resta citas existentes (no canceladas) que solapen
 *   5. Filtra slots que ya hayan pasado (si la fecha es hoy)
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
AppointmentService::ensureAppointmentDurationSchema();
AppointmentService::ensureMachinerySchema();
ServiceCatalogService::ensureSchema();

// Solo usuarios autenticados
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
if (Auth::isClient() && !Auth::emailVerified()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Confirma tu correo para consultar disponibilidad']);
    exit;
}

global $CONFIG;
$cfg = $CONFIG['business'];

$branchId  = (int) ($_GET['branch']  ?? 0);
$serviceId = (int) ($_GET['service'] ?? 0);
$dateRaw   = (string) ($_GET['date'] ?? '');
$ignoreId  = Auth::isAdmin() ? (int) ($_GET['ignore'] ?? 0) : 0;
$professionalId = Auth::isAdmin() ? (int) ($_GET['professional'] ?? 0) : 0;
$includeUnavailable = Auth::isAdmin() && !empty($_GET['include_unavailable']);
$allowImmediate = Auth::isAdmin() && !empty($_GET['allow_immediate']);

if (!$branchId || !$serviceId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos']);
    exit;
}

$dateTs = strtotime($dateRaw);
if (!$dateTs) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Fecha inválida']);
    exit;
}

// Validar rango
$minTs = strtotime('today') + $cfg['booking_min_hours'] * 3600;
$maxTs = strtotime('today') + $cfg['booking_max_days'] * 86400;
if ($dateTs < strtotime('today')) {
    echo json_encode([
        'ok' => true,
        'slots' => [],
        'count' => 0,
        'note' => 'No se pueden consultar horarios de fechas pasadas',
    ]);
    exit;
}
if ($dateTs > $maxTs) {
    echo json_encode([
        'ok' => true,
        'slots' => [],
        'count' => 0,
        'note' => 'La fecha queda fuera del rango permitido de agenda',
    ]);
    exit;
}

// Servicio
$svc = Database::one(
    "SELECT id, duration_min, COALESCE(item_type, 'service') AS item_type FROM services WHERE id = ? AND active = 1",
    [$serviceId]
);
if (!$svc) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Servicio no encontrado o inactivo']);
    exit;
}
$duration = (int) $svc['duration_min'];
$step = max(5, (int) ($cfg['slot_interval_min'] ?? 15));

// Branch + verificar que ofrece el servicio
$ofrece = Database::one(
    'SELECT 1 FROM service_branches WHERE branch_id = ? AND service_id = ?',
    [$branchId, $serviceId]
);
if (!$ofrece) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Esta sucursal no ofrece ese servicio']);
    exit;
}

// 1) Horario base
$weekday = (int) date('w', $dateTs); // 0=dom..6=sáb
$baseRows = Database::all(
    'SELECT time_start, time_end FROM availability
     WHERE branch_id = ? AND weekday = ? AND active = 1
     ORDER BY time_start',
    [$branchId, $weekday]
);

if (!$baseRows) {
    echo json_encode([
        'ok' => true,
        'slots' => [],
        'count' => 0,
        'note' => 'La sucursal no tiene horario laboral ese dia',
    ]);
    exit;
}

// 2) Excepciones
$exc = Database::one(
    'SELECT type, time_start, time_end FROM availability_exceptions
     WHERE branch_id = ? AND date = ? LIMIT 1',
    [$branchId, $dateRaw]
);

$windows = [];
if ($exc) {
    if ($exc['type'] === 'closed') {
        echo json_encode([
            'ok' => true,
            'slots' => [],
            'count' => 0,
            'note' => 'Dia cerrado por excepcion de agenda',
        ]);
        exit;
    }
    // custom
    if ($exc['time_start'] && $exc['time_end']) {
        $windows[] = ['s' => $exc['time_start'], 'e' => $exc['time_end']];
    }
} else {
    foreach ($baseRows as $row) {
        $windows[] = ['s' => $row['time_start'], 'e' => $row['time_end']];
    }
}

// 3) Citas existentes que ocupan slots
$paymentSql = '';
if (Database::one("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'payment_status' LIMIT 1")) {
    $paymentSql = " AND (
        payment_required = 0
        OR payment_status = 'paid'
        OR (payment_status = 'pending' AND payment_expires_at > NOW())
    )";
}

$busySql = "SELECT start_at, end_at FROM appointments
            WHERE branch_id = ?
              AND DATE(start_at) = ?
              AND status_id IN (SELECT id FROM appointment_statuses WHERE slug NOT IN ('cancelada','no_asistio'))
              {$paymentSql}";
$busyArgs = [$branchId, $dateRaw];
if ($ignoreId > 0) {
    $busySql .= ' AND id <> ?';
    $busyArgs[] = $ignoreId;
}
$busy = Database::all($busySql, $busyArgs);

$busyRanges = array_map(fn($b) => [strtotime($b['start_at']), strtotime($b['end_at'])], $busy);

$busyProsSql = "SELECT professional_id, start_at, end_at
                FROM appointments
                WHERE professional_id IS NOT NULL
                  AND DATE(start_at) = ?
                  AND status_id IN (SELECT id FROM appointment_statuses WHERE slug IN ('programada','confirmada','atendida'))
                  {$paymentSql}";
$busyProsArgs = [$dateRaw];
if ($ignoreId > 0) {
    $busyProsSql .= ' AND id <> ?';
    $busyProsArgs[] = $ignoreId;
}
$busyPros = Auth::isAdmin() ? Database::all($busyProsSql, $busyProsArgs) : [];
$busyProRanges = array_map(
    fn($b) => [(int) $b['professional_id'], strtotime($b['start_at']), strtotime($b['end_at'])],
    $busyPros
);

$branchProfessionals = [];
if (Auth::isAdmin()) {
    $branchProfessionals = Database::all(
        "SELECT u.id, u.name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN user_branches ub ON ub.user_id = u.id
         WHERE r.slug = 'professional'
           AND u.active = 1
           AND ub.branch_id = ?
         ORDER BY u.name",
        [$branchId]
    );
}
$branchProfessionalIds = array_map('intval', array_column($branchProfessionals, 'id'));

// 4) Generar slots
$slots = [];
$now = time();
$today = date('Y-m-d');

foreach ($windows as $w) {
    $startTs = strtotime("$dateRaw {$w['s']}");
    $endTs   = strtotime("$dateRaw {$w['e']}");

    for ($t = $startTs; $t + $duration * 60 <= $endTs; $t += $step * 60) {
        $slotEnd = $t + $duration * 60;

        // Si es hoy, descartar slots que ya pasaron + buffer mínimo
        $reasons = [];
        if ($dateRaw === $today && $t < $now) {
            $reasons[] = 'La hora ya paso.';
        } elseif (!$allowImmediate && $dateRaw === $today && $t < $now + $cfg['booking_min_hours'] * 3600) {
            $reasons[] = 'Requiere ' . (int) $cfg['booking_min_hours'] . ' h de anticipacion.';
        }

        // ¿Choca con alguna cita?
        $busyCount = 0;
        foreach ($busyRanges as [$bs, $be]) {
            if ($t < $be && $slotEnd > $bs) { $busyCount++; }
        }
        $slotStartSql = date('Y-m-d H:i:s', $t);
        $slotEndSql = date('Y-m-d H:i:s', $slotEnd);
        $availableCabins = AppointmentService::availableCabins($branchId, $slotStartSql, $slotEndSql, $ignoreId ?: null);
        $availableMachines = AppointmentService::availableMachineUnits($branchId, $serviceId, $slotStartSql, $slotEndSql, $ignoreId ?: null);
        $busyProfessionalIds = [];
        foreach ($busyProRanges as [$busyProfessionalId, $busyStart, $busyEnd]) {
            if ($t < $busyEnd && $slotEnd > $busyStart) {
                $busyProfessionalIds[] = $busyProfessionalId;
            }
        }
        $busyProfessionalIds = array_values(array_unique($busyProfessionalIds));
        if ($professionalId > 0 && in_array($professionalId, $busyProfessionalIds, true)) {
            $reasons[] = 'El profesional seleccionado ya tiene una cita.';
        } elseif (Auth::isAdmin() && $professionalId <= 0 && $branchProfessionalIds) {
            if (!array_values(array_diff($branchProfessionalIds, $busyProfessionalIds))) {
                $reasons[] = 'No hay profesional libre en este horario.';
            }
        } elseif (Auth::isAdmin() && !$branchProfessionalIds) {
            $reasons[] = 'No hay profesionales activos asignados a la sucursal.';
        }
        if ($availableCabins <= 0) {
            $reasons[] = 'No hay cabinas disponibles.';
        }
        if ($availableMachines <= 0) {
            $reasons[] = 'La maquinaria requerida esta ocupada.';
        }
        $isAvailable = !$reasons;
        if (!$isAvailable && !$includeUnavailable) continue;

        $slots[] = [
            'start'      => $slotStartSql,
            'end'        => $slotEndSql,
            'label'      => date('H:i', $t),
            'label_long' => fmt_dt($slotStartSql),
            'available'  => $isAvailable,
            'reason'     => $isAvailable ? null : implode(' ', $reasons),
            'reasons'    => $reasons,
            'available_cabins' => $availableCabins,
            'available_machines' => $availableMachines === PHP_INT_MAX ? null : $availableMachines,
            'busy_professional_ids' => $busyProfessionalIds,
        ];
    }
}

echo json_encode([
    'ok' => true,
    'slots' => $slots,
    'count' => count(array_filter($slots, fn($slot) => !empty($slot['available']))),
    'total_slots' => count($slots),
    'include_unavailable' => $includeUnavailable,
    'allow_immediate' => $allowImmediate,
    'booking_min_hours' => (int) $cfg['booking_min_hours'],
    'cabin_capacity' => AppointmentService::branchCabinCapacity($branchId),
    'machine_capacity' => AppointmentService::serviceResourceKey($serviceId)
        ? AppointmentService::resourceCapacity($branchId, AppointmentService::serviceResourceKey($serviceId))
        : null,
]);

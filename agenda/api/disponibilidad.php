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

// Solo usuarios autenticados
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

global $CONFIG;
$cfg = $CONFIG['business'];

$branchId  = (int) ($_GET['branch']  ?? 0);
$serviceId = (int) ($_GET['service'] ?? 0);
$dateRaw   = (string) ($_GET['date'] ?? '');

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
if ($dateTs > $maxTs) {
    echo json_encode(['ok' => true, 'slots' => [], 'note' => 'Fuera de rango permitido']);
    exit;
}

// Servicio
$svc = Database::one('SELECT id, duration_min FROM services WHERE id = ? AND active = 1', [$serviceId]);
if (!$svc) {
    echo json_encode(['ok' => false, 'error' => 'Servicio no encontrado']);
    exit;
}
$duration = (int) $svc['duration_min'];
$padding  = (int) $cfg['slot_padding_min'];
$step     = $duration + $padding;

// Branch + verificar que ofrece el servicio
$ofrece = Database::one(
    'SELECT 1 FROM service_branches WHERE branch_id = ? AND service_id = ?',
    [$branchId, $serviceId]
);
if (!$ofrece) {
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
    echo json_encode(['ok' => true, 'slots' => [], 'note' => 'Sucursal cerrada ese día']);
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
        echo json_encode(['ok' => true, 'slots' => [], 'note' => 'Día cerrado']);
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
$busy = Database::all(
    "SELECT start_at, end_at FROM appointments
     WHERE branch_id = ?
       AND DATE(start_at) = ?
       AND status_id IN (SELECT id FROM appointment_statuses WHERE slug NOT IN ('cancelada','no_asistio'))",
    [$branchId, $dateRaw]
);

$busyRanges = array_map(fn($b) => [strtotime($b['start_at']), strtotime($b['end_at'])], $busy);

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
        if ($dateRaw === $today && $t < $now + $cfg['booking_min_hours'] * 3600) continue;

        // ¿Choca con alguna cita?
        $busy = false;
        foreach ($busyRanges as [$bs, $be]) {
            if ($t < $be && $slotEnd > $bs) { $busy = true; break; }
        }
        if ($busy) continue;

        $slots[] = [
            'start'      => date('Y-m-d H:i:s', $t),
            'end'        => date('Y-m-d H:i:s', $slotEnd),
            'label'      => date('H:i', $t),
            'label_long' => fmt_dt(date('Y-m-d H:i:s', $t)),
        ];
    }
}

echo json_encode(['ok' => true, 'slots' => $slots, 'count' => count($slots)]);

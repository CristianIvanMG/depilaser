<?php
/**
 * GET /agenda/api/calendario.json.php?from=2026-05-01&to=2026-06-01&branch=1
 * Devuelve eventos en formato FullCalendar.
 * Solo admins.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Asegura que exista el esquema de profesionales y de recibos
try { AppointmentService::ensureProfessionalSchema(); } catch (\Throwable $e) { /* silencioso */ }
try { AppointmentService::ensureReceiptSchema(); } catch (\Throwable $e) { /* silencioso */ }

Auth::enforceSessionTimeout();
if (!Auth::isAdmin() && !Auth::isProfessional()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso restringido']);
    exit;
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d', strtotime('+30 days'));
$branchId = isset($_GET['branch']) ? (int) $_GET['branch'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Fechas inválidas']);
    exit;
}

$sql = "SELECT a.id, a.code, a.start_at, a.end_at, a.notes_client, a.notes_admin,
               a.professional_id, a.receipt_folio, a.receipt_sent,
               u.name AS client, u.phone,
               s.name AS service,
               b.name AS branch,
               st.slug AS status_slug, st.name AS status, st.color_hex,
               pr.name AS professional
        FROM appointments a
        JOIN users u ON u.id = a.user_id
        JOIN services s ON s.id = a.service_id
        JOIN branches b ON b.id = a.branch_id
        JOIN appointment_statuses st ON st.id = a.status_id
        LEFT JOIN users pr ON pr.id = a.professional_id
        WHERE a.start_at >= ? AND a.start_at < ?";
$args = [$from, $to];
if (Auth::isProfessional() && !Auth::isAdmin()) {
    $sql .= " AND a.professional_id = ?";
    $args[] = (int) (Auth::user()['id'] ?? 0);
}
if ($branchId) {
    $sql .= " AND a.branch_id = ?";
    $args[] = $branchId;
}
$sql .= " ORDER BY a.start_at";

$rows = Database::all($sql, $args);

$statusClassMap = [
    'programada' => 'bnc-fc-status-programada',
    'confirmada' => 'bnc-fc-status-confirmada',
    'cancelada' => 'bnc-fc-status-cancelada',
    'atendida' => 'bnc-fc-status-atendida',
    'no_asistio' => 'bnc-fc-status-no-asistio',
    'no-asistio' => 'bnc-fc-status-no-asistio',
];

$events = array_map(fn($r) => [
    'id'              => $r['id'],
    'title'           => $r['client'] . ' - ' . $r['service'],
    'start'           => str_replace(' ', 'T', $r['start_at']),
    'end'             => str_replace(' ', 'T', $r['end_at']),
    'backgroundColor' => '#ffffff',
    'borderColor'     => $r['color_hex'] ?: '#d63b93',
    'classNames'      => [
        'bnc-fc-event',
        $statusClassMap[$r['status_slug']] ?? 'bnc-fc-status-programada',
    ],
    'extendedProps'   => [
        'code'         => $r['code'],
        'client'       => $r['client'],
        'phone'        => $r['phone'],
        'service'      => $r['service'],
        'branch'       => $r['branch'],
        'professional' => $r['professional'] ?? null,
        'professional_id' => $r['professional_id'] ? (int) $r['professional_id'] : null,
        'receipt_folio'   => $r['receipt_folio'] ?? null,
        'receipt_sent'    => (int) ($r['receipt_sent'] ?? 0),
        'status'       => $r['status'],
        'status_slug'  => $r['status_slug'],
        'status_color' => $r['color_hex'] ?: '#d63b93',
        'when'         => fmt_dt($r['start_at']),
        'start_at'     => $r['start_at'],
        'start_time'   => date('H:i', strtotime($r['start_at'])),
        'end_time'     => date('H:i', strtotime($r['end_at'])),
        'notes_client' => $r['notes_client'],
        'notes_admin'  => $r['notes_admin'],
    ],
], $rows);

echo json_encode(['ok' => true, 'events' => $events]);

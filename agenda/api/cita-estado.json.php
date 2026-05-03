<?php
/**
 * POST /agenda/api/cita-estado.json.php
 *
 * Body (form-data o JSON):
 *   _csrf           : token CSRF
 *   appointment_id  : int
 *   to              : 'confirmada' | 'atendida' | 'no_asistio' | 'cancelada'
 *   reason          : string (opcional)
 *   send_email      : '1' opcional (envía recibo si pasa a atendida o correo empático si cancela / no_asistio)
 *
 * Respuesta JSON:
 *   { ok: bool, error?: string, status?: { slug,name,color }, receipt_folio?: string,
 *     receipt_sent?: bool, empathy_sent?: bool }
 *
 * Solo admin. Toda la lógica de validación está en AppointmentService::transitionStatus().
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo administradores.']);
    exit;
}

// Acepta JSON body además de form-data
$payload = $_POST;
if (empty($payload) && !empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $payload = $decoded;
}

$token = (string) ($payload[Csrf::FIELD] ?? '');
if (!Csrf::valid($token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.']);
    exit;
}

$appointmentId = (int) ($payload['appointment_id'] ?? 0);
$to            = (string) ($payload['to'] ?? '');
$reason        = trim((string) ($payload['reason'] ?? '')) ?: null;
$sendEmail     = !empty($payload['send_email']);

if (!$appointmentId || !$to) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parámetros incompletos.']);
    exit;
}

$user = Auth::user();
$result = AppointmentService::transitionStatus($appointmentId, $to, (int) $user['id'], $reason);

if (!$result['ok']) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'No fue posible cambiar el estado.']);
    exit;
}

$appt = $result['appointment'] ?? [];
$response = [
    'ok' => true,
    'status' => [
        'slug'  => $appt['status_slug'] ?? $to,
        'name'  => $appt['status_name'] ?? $to,
        'color' => $appt['color_hex']   ?? null,
    ],
    'receipt_folio' => $result['receipt_folio'] ?? null,
];

// Disparos automáticos de correo cuando aplica
if ($sendEmail) {
    if ($to === 'atendida') {
        $sent = ReceiptService::emailReceipt($appointmentId, false);
        $response['receipt_sent'] = $sent['ok'];
        if (!$sent['ok']) {
            $response['receipt_warning'] = $sent['error'] ?? null;
        }
    } elseif (in_array($to, ['cancelada', 'no_asistio'], true)) {
        $sent = ReceiptService::emailEmpathy($appointmentId, false);
        $response['empathy_sent'] = $sent['ok'];
        if (!$sent['ok']) {
            $response['empathy_warning'] = $sent['error'] ?? null;
        }
    }
}

echo json_encode($response);

<?php
/**
 * POST /agenda/api/cita-estado.json.php
 *
 * Body (form-data o JSON):
 *   _csrf           : token CSRF
 *   appointment_id  : int
 *   to              : 'confirmada' | 'atendida' | 'no_asistio' | 'cancelada'
 *   reason          : string (opcional)
 *   send_email      : '1' opcional (envia recibo si pasa a atendida)
 *
 * Respuesta JSON:
 *   { ok: bool, error?: string, status?: { slug,name,color }, receipt_folio?: string,
 *     status_email_sent?: bool, receipt_sent?: bool }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo administradores.']);
    exit;
}

$payload = $_POST;
if (empty($payload) && !empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $payload = $decoded;
}

$token = (string) ($payload[Csrf::FIELD] ?? '');
if (!Csrf::valid($token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalido. Recarga la pagina.']);
    exit;
}

$appointmentId = (int) ($payload['appointment_id'] ?? 0);
$to            = (string) ($payload['to'] ?? '');
$reason        = trim((string) ($payload['reason'] ?? '')) ?: null;
$sendEmail     = !empty($payload['send_email']);

if (!$appointmentId || !$to) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parametros incompletos.']);
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

$emailType = match ($to) {
    'confirmada' => 'appointment_confirmed',
    'cancelada' => 'appointment_cancelled',
    'no_asistio' => 'appointment_no_show',
    'atendida' => 'appointment_attended',
    default => 'appointment_status_changed',
};
$statusMail = EmailNotificationService::sendForAppointment($appointmentId, $emailType);
$response['status_email_sent'] = !empty($statusMail['sent']);
if (!$response['status_email_sent'] && empty($statusMail['duplicate']) && empty($statusMail['skipped'])) {
    $response['status_email_warning'] = $statusMail['error'] ?? 'No fue posible enviar la notificacion.';
}

if ($sendEmail && $to === 'atendida') {
    $sent = ReceiptService::emailReceipt($appointmentId, false);
    $response['receipt_sent'] = $sent['ok'];
    if (!$sent['ok']) {
        $response['receipt_warning'] = $sent['error'] ?? null;
    }
}

echo json_encode($response);

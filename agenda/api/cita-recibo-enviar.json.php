<?php
/**
 * POST /agenda/api/cita-recibo-enviar.json.php
 *  body: _csrf, appointment_id, force? (1 para reenviar)
 *  → { ok, error?, already_sent? }
 *
 * Envía por correo el recibo al cliente (solo si la cita está atendida).
 * Bloquea reenvíos salvo que se pase force=1.
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

$token = (string) ($_POST[Csrf::FIELD] ?? '');
if (!Csrf::valid($token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$id    = (int) ($_POST['appointment_id'] ?? 0);
$force = !empty($_POST['force']);

if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
    exit;
}

AppointmentService::ensureReceiptSchema();

$result = ReceiptService::emailReceipt($id, $force);
if (!$result['ok']) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}
echo json_encode(['ok' => true]);

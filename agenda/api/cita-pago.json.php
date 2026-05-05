<?php
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
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if (!Csrf::valid((string) ($payload[Csrf::FIELD] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalido. Recarga la pagina.']);
    exit;
}

$appointmentId = (int) ($payload['appointment_id'] ?? 0);
$markPaid = (string) ($payload['paid'] ?? '') === '1';
$method = trim((string) ($payload['method'] ?? 'manual')) ?: 'manual';
$reference = trim((string) ($payload['reference'] ?? '')) ?: null;
$sendReceipt = !array_key_exists('send_receipt', $payload) || !empty($payload['send_receipt']);
$user = Auth::user();

if (!$appointmentId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Cita no valida.']);
    exit;
}
if (!$markPaid) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El pago no puede desmarcarse desde este control.']);
    exit;
}

$result = PaymentService::registerManualPayment(
    $appointmentId,
    (int) ($user['id'] ?? 0),
    $method,
    $reference,
    null,
    $sendReceipt
);

if (empty($result['ok'])) {
    http_response_code(409);
}

echo json_encode($result);

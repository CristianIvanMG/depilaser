<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$result = PaymentService::handleMercadoPagoWebhook($payload, $_GET, $headers);

if (!$result['ok']) {
    error_log('[mercadopago-webhook] ' . ($result['error'] ?? 'Error desconocido'));
    http_response_code(($result['error'] ?? '') === 'Firma inválida.' ? 401 : 422);
    echo json_encode(['ok' => false]);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check() || (!Auth::isAdmin() && !Auth::isProfessional())) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso restringido.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$token = trim((string) ($payload['token'] ?? ''));
$branchId = isset($payload['branch_id']) && $payload['branch_id'] !== '' ? (int) $payload['branch_id'] : null;
$csrf = (string) ($payload[Csrf::FIELD] ?? '');

if (!Csrf::valid($csrf)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalido. Recarga la pagina.']);
    exit;
}

if (!$branchId) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Selecciona una sucursal antes de escanear el QR.']);
    exit;
}

if ($token === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No se recibio ningun QR.']);
    exit;
}

try {
    $result = RewardsService::registerAttendance($token, (int) Auth::user()['id'], $branchId);
    http_response_code(!empty($result['ok']) ? 200 : (!empty($result['duplicate']) ? 409 : 422));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[qr-scan] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible registrar la asistencia. Revisa la configuracion de recompensas.']);
}

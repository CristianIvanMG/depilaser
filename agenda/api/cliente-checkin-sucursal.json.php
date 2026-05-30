<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check() || !Auth::isClient()) {
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

$csrf = (string) ($payload[Csrf::FIELD] ?? '');
if (!Csrf::valid($csrf)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalido. Recarga la pagina.']);
    exit;
}

$token = trim((string) ($payload['token'] ?? ''));
if ($token === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Escanea el QR de la sucursal para registrar tu visita.']);
    exit;
}

$result = RewardsService::registerClientBranchAttendance((int) Auth::user()['id'], $token);
http_response_code(!empty($result['ok']) ? 200 : (!empty($result['duplicate']) ? 409 : 422));
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Inicia sesion para agregarte a la lista de espera.']);
    exit;
}
if (Auth::isClient() && !Auth::emailVerified()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Confirma tu correo para usar la lista de espera.']);
    exit;
}

$payload = $_POST;
if (empty($payload) && !empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if (!Csrf::valid((string) ($payload[Csrf::FIELD] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Tu sesion expiro. Recarga la pagina e intenta de nuevo.']);
    exit;
}

$user = Auth::user();
$result = WaitlistService::createForClient((int) $user['id'], $payload);
if (!$result['ok']) {
    http_response_code(422);
    echo json_encode($result);
    exit;
}

echo json_encode([
    'ok' => true,
    'duplicate' => !empty($result['duplicate']),
    'message' => !empty($result['duplicate'])
        ? 'Ya estas en la lista de espera para esta fecha.'
        : 'Te agregamos a la lista de espera. Si se libera un espacio, te avisaremos por correo.',
]);

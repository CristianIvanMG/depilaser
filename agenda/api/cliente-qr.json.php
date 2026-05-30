<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check() || !Auth::isClient()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso restringido.']);
    exit;
}

$user = Auth::user();
try {
    RewardsService::ensureSchema();
    echo json_encode([
        'ok' => true,
        'token' => RewardsService::qrTokenForUser($user),
        'payload' => RewardsService::qrPayloadForUser($user),
        'progress' => RewardsService::progressForClient((int) $user['id']),
        'rewards' => RewardsService::rewardsForClient((int) $user['id'], 6),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[cliente-qr] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible generar el QR del cliente.']);
}

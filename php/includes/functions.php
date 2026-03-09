<?php
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check($token) {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// Registrar intento de login para rate-limit
function register_attempt(PDO $pdo, ?string $email) {
    $ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip, email) VALUES (?, ?)");
    $stmt->execute([$ip, $email]);
}

function too_many_attempts(PDO $pdo, ?string $email, int $max = 5, int $windowSeconds = 900) {
    $ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM login_attempts
        WHERE ip = ?
          AND attempted_at > (NOW() - INTERVAL ? SECOND)
    ");
    $stmt->execute([$ip, $windowSeconds]);
    $count = (int)$stmt->fetch()['c'];
    return $count >= $max;
}

<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

if (!csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('CSRF inválido');
}

$email = trim(strtolower($_POST['email'] ?? ''));
$pass  = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
    $_SESSION['flash'] = 'Completa los campos.';
    header('Location: /index.php'); exit;
}

// Rate limit por IP (y puedes incluir por email)
if (too_many_attempts($pdo, $email)) {
    $_SESSION['flash'] = 'Demasiados intentos. Intenta más tarde.';
    header('Location: /index.php'); exit;
}

register_attempt($pdo, $email);

// Buscar usuario
$stmt = $pdo->prepare("SELECT id, password_hash, is_active FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

$ok = false;
if ($user && (int)$user['is_active'] === 1) {
    $ok = password_verify($pass, $user['password_hash']);
}

// Importante: tiempo constante (evitar filtrar si existe o no)
if (!$ok) {
    // No reveles si el correo existe
    $_SESSION['flash'] = 'Credenciales inválidas.';
    echo json_encode(['ok' => false, 'error' => 'Credenciales inválidas']); exit;
    header('Location: /index.php'); exit;
}

// Éxito: regenerar ID de sesión para evitar fijación
session_regenerate_id(true);
$_SESSION['uid']   = (int)$user['id'];
$_SESSION['email'] = $email;
echo json_encode(['ok' => true]); exit;
// Si quieres, guarda hash del user-agent/IP para “atar” sesión
// $_SESSION['ua'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

header('Location: /dashboard.php');
exit;


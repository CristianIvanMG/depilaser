
<?php
// Forzar HTTPS en producción
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'on') {
    // En Hostinger, asegúrate de tener SSL y redirigir con .htaccess también
}

// Iniciar sesión con parámetros seguros
session_set_cookie_params([
    'lifetime' => 0,              // sesión del navegador
    'path' => '/',
    'domain' => '',               // dejar vacío normalmente
    'secure' => true,             // requiere HTTPS
    'httponly' => true,           // inaccesible desde JS
    'samesite' => 'Strict',       // o 'Lax' si necesitas POST cross-site legítimo
]);
session_name('__Host-SESSIONID'); // prefijo recomendado en HTTPS
session_start();

// Conexión PDO
$dsn = 'mysql:host=localhost;dbname=TU_DB;charset=utf8mb4';
$user = 'TU_USUARIO';
$pass = 'TU_PASSWORD';

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
  http_response_code(500);
  exit('Error de conexión');
}

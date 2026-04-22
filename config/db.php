<?php
// ============================================================
// CONEXION A BASE DE DATOS - BellaNick Clinic
// Lee credenciales desde secrets.php (bloqueado via .htaccess)
// Expone $pdo (PDO) listo para usar con prepared statements.
// ============================================================

// 1) Buscar secrets.php en varias rutas (orden de seguridad)
$secretsPaths = [
  __DIR__ . '/../../../../secrets.php', // /home/USER/secrets.php                (mas seguro)
  __DIR__ . '/../../../secrets.php',    // /home/USER/domains/secrets.php
  __DIR__ . '/../../secrets.php',       // fuera de public_html
  __DIR__ . '/secrets.php',             // /config/secrets.php (fallback local, este repo)
];

$config = null;
foreach ($secretsPaths as $p) {
  if (is_file($p)) { $config = require $p; break; }
}

if (!is_array($config)) {
  http_response_code(500);
  error_log('[db.php] secrets.php no encontrado');
  echo json_encode(['status' => 'error', 'message' => 'Config no disponible']);
  exit;
}

// 2) Validar claves minimas
$required = ['db_host','db_name','db_user','db_pass'];
foreach ($required as $k) {
  if (empty($config[$k]) || (is_string($config[$k]) && strpos($config[$k], 'REEMPLAZAR_') === 0)) {
    http_response_code(500);
    error_log("[db.php] Credencial faltante o placeholder: $k");
    echo json_encode(['status' => 'error', 'message' => 'Config DB incompleta']);
    exit;
  }
}

// 3) Construir DSN
$host    = $config['db_host'];
$dbname  = $config['db_name'];
$charset = $config['db_charset'] ?? 'utf8mb4';
$port    = (int)($config['db_port'] ?? 3306);

$dsn = sprintf(
  'mysql:host=%s;port=%d;dbname=%s;charset=%s',
  $host, $port, $dbname, $charset
);

// 4) Crear PDO
try {
  $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => false,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  error_log('[db.php] Fallo conexion: ' . $e->getMessage());
  echo json_encode(['status' => 'error', 'message' => 'Error de conexion']);
  exit;
}

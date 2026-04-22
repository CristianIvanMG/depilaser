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
  $raw  = $e->getMessage();
  error_log('[db.php] Fallo conexion: ' . $raw);

  // Diagnostico legible (sin exponer credenciales)
  $hint = null;
  if (stripos($raw, 'Access denied') !== false) {
    $hint = 'Credenciales incorrectas: revisa db_user y db_pass en secrets.php. En hPanel > Bases de datos MySQL confirma que ese usuario tiene permisos sobre esa base.';
  } elseif (stripos($raw, 'Unknown database') !== false) {
    $hint = 'La base de datos no existe: revisa db_name en secrets.php (Hostinger usa prefijo tipo u1234567_nombre).';
  } elseif (stripos($raw, 'Unknown MySQL server') !== false || stripos($raw, "can't connect") !== false || stripos($raw, 'getaddrinfo') !== false) {
    $hint = 'Host MySQL inaccesible: revisa db_host. En Hostinger suele ser "localhost". Si usas hosting con base remota, copia el host exacto que muestra hPanel.';
  } elseif (stripos($raw, 'SSL') !== false) {
    $hint = 'Problema de SSL en la conexion MySQL.';
  }

  // Modo debug: activar solo poniendo 'db_debug' => true en secrets.php
  $debug = !empty($config['db_debug']);
  $resp = ['status' => 'error', 'message' => 'Error de conexion'];
  if ($hint) $resp['hint'] = $hint;
  if ($debug) {
    // Solo en modo debug expone el detalle crudo (nunca en produccion)
    $resp['debug'] = $raw;
    $resp['dsn_preview'] = sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);
    $resp['user_preview'] = substr($config['db_user'], 0, 3) . '***';
  }
  echo json_encode($resp);
  exit;
}

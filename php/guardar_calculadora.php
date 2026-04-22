<?php
// ============================================================
// Endpoint: guardar respuestas de la calculadora de sesiones
// Ruta publica: /php/guardar_calculadora.php
// Metodo: POST (application/json)
// ============================================================

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Metodo no permitido']);
  exit;
}

// ---- Body ----
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Payload invalido']);
  exit;
}

// ---- Sanitizar / validar ----
$allowedZona     = ['axilas','bikini','piernas','brazos','rostro','espalda_pecho','cuerpo_completo'];
$allowedVello    = ['delgado','medio','grueso'];
$allowedRasurado = ['cada_semana','cada_2_3','rara_vez'];
$allowedAtencion = ['femenino','masculino'];

$zona     = in_array(($data['zona']     ?? ''), $allowedZona,     true) ? $data['zona']     : null;
$vello    = in_array(($data['vello']    ?? ''), $allowedVello,    true) ? $data['vello']    : null;
$rasurado = in_array(($data['rasurado'] ?? ''), $allowedRasurado, true) ? $data['rasurado'] : null;
$atencion = in_array(($data['atencion'] ?? ''), $allowedAtencion, true) ? $data['atencion'] : null;

if (!$zona || !$vello || !$rasurado || !$atencion) {
  http_response_code(422);
  echo json_encode(['status' => 'error', 'message' => 'Campos incompletos o invalidos']);
  exit;
}

$sesionesMin = isset($data['sesiones_min']) ? max(1, min(20, (int)$data['sesiones_min'])) : null;
$sesionesMax = isset($data['sesiones_max']) ? max(1, min(20, (int)$data['sesiones_max'])) : null;

$nombre   = isset($data['nombre'])   ? mb_substr(trim((string)$data['nombre']), 0, 120)   : null;
$telefono = isset($data['telefono']) ? preg_replace('/[^0-9+\s\-()]/', '', (string)$data['telefono']) : null;
if ($telefono !== null) $telefono = mb_substr($telefono, 0, 30);
if ($nombre   === '') $nombre   = null;
if ($telefono === '') $telefono = null;

$url = isset($data['url']) ? mb_substr((string)$data['url'], 0, 500) : null;

// ---- Conexion y persistencia ----
require __DIR__ . '/../config/db.php';

try {
  $sql = "INSERT INTO calculadora_sesiones
          (zona, vello, rasurado, atencion, sesiones_min, sesiones_max, nombre, telefono, url, ip, user_agent, created_at)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
  $stmt = $pdo->prepare($sql);
  $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
  $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

  $stmt->execute([
    $zona, $vello, $rasurado, $atencion,
    $sesionesMin, $sesionesMax,
    $nombre, $telefono, $url, $ip, $ua
  ]);

  echo json_encode(['status' => 'ok', 'id' => (int)$pdo->lastInsertId()]);
} catch (PDOException $e) {
  // Fallback: si la tabla no tiene columnas ip/user_agent/created_at, reintenta con schema minimo
  if (strpos($e->getMessage(), 'Unknown column') !== false) {
    try {
      $stmt = $pdo->prepare("INSERT INTO calculadora_sesiones
        (zona, vello, rasurado, atencion, sesiones_min, sesiones_max, nombre, telefono, url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$zona, $vello, $rasurado, $atencion, $sesionesMin, $sesionesMax, $nombre, $telefono, $url]);
      echo json_encode(['status' => 'ok', 'id' => (int)$pdo->lastInsertId(), 'schema' => 'legacy']);
      exit;
    } catch (PDOException $e2) {
      error_log('[guardar_calculadora] ' . $e2->getMessage());
    }
  } else {
    error_log('[guardar_calculadora] ' . $e->getMessage());
  }
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar']);
}

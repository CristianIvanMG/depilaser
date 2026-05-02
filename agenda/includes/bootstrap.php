<?php
/**
 * Bootstrap del sistema de citas BellaNick.
 * Cargar al inicio de TODOS los .php públicos:
 *   require_once __DIR__ . '/includes/bootstrap.php';
 */

declare(strict_types=1);

// 1. Modo estricto para errores en development
if (!defined('AGENDA_ROOT')) {
    define('AGENDA_ROOT', dirname(__DIR__));
}

// 2. Carga config
$configPath = AGENDA_ROOT . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    die('Falta /agenda/config/config.php — copia config.example.php y configúralo.');
}
$CONFIG = require $configPath;

// 3. Define globales
define('APP_NAME',     $CONFIG['app']['name']);
define('APP_ENV',      $CONFIG['app']['env']);
define('APP_DEBUG',    (bool) $CONFIG['app']['debug']);
define('APP_BASE_URL', rtrim($CONFIG['app']['base_url'], '/'));

// 4. Errores
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(APP_DEBUG ? E_ALL : E_ALL & ~E_NOTICE & ~E_DEPRECATED);

// 5. Zona horaria + locale
date_default_timezone_set($CONFIG['app']['timezone']);
setlocale(LC_TIME, $CONFIG['app']['locale'] . '.UTF-8', $CONFIG['app']['locale']);

// 6. Sesión segura
if (session_status() === PHP_SESSION_NONE) {
    session_name($CONFIG['session']['name']);
    session_set_cookie_params([
        'lifetime' => $CONFIG['session']['lifetime'],
        'path'     => '/',
        'secure'   => $CONFIG['session']['secure'],
        'httponly' => true,
        'samesite' => $CONFIG['session']['samesite'],
    ]);
    session_start();
}

// 7. Autoload PSR-4 simple (clases en /includes/)
spl_autoload_register(function (string $class): void {
    $file = AGENDA_ROOT . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// 8. Helpers globales
require_once __DIR__ . '/functions.php';

// 9. Conexión BD lista para usar como `Database::pdo()`
Database::init($CONFIG['db']);

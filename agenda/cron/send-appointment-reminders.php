<?php
declare(strict_types=1);

/**
 * Cron: envia recordatorios de cita 24 horas antes.
 *
 * Ejemplo:
 *   /usr/bin/php /ruta/public_html/agenda/cron/send-appointment-reminders.php
 *
 * Opcionales:
 *   --window=10  Ventana en minutos alrededor de 24h exactas.
 *   --limit=100  Maximo de citas por ejecucion.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este proceso solo puede ejecutarse por CLI.\n";
    exit(1);
}

$window = 10;
$limit = 100;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--window=(\d+)$/', $arg, $m)) {
        $window = (int) $m[1];
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$started = date('c');
$result = EmailNotificationService::runReminderCron($window, $limit);
$result['started_at'] = $started;
$result['finished_at'] = date('c');

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['failed'] > 0 ? 2 : 0);

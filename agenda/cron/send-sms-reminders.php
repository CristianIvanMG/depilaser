<?php
declare(strict_types=1);

/**
 * Cron: envia SMS el mismo dia de la cita, idealmente 3 horas antes.
 *
 * Ejemplo Hostinger:
 *   /usr/bin/php /home/USUARIO/domains/depilasermexico.com/public_html/agenda/cron/send-sms-reminders.php
 *
 * Opcionales:
 *   --limit=50  Maximo de SMS por ejecucion.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este proceso solo puede ejecutarse por CLI.\n";
    exit(1);
}

$limit = 50;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$started = date('c');
$result = SmsService::runAppointmentReminderCron($limit);
$result['started_at'] = $started;
$result['finished_at'] = date('c');

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['failed'] > 0 ? 2 : 0);

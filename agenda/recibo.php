<?php
/**
 * Ticket publico firmado para cliente.
 * El correo solo incluye texto plano y apunta a esta pagina para ver/imprimir PDF.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

AppointmentService::ensureReceiptSchema();
AppointmentService::ensurePackageBillingSchema();

$token = trim((string) ($_GET['t'] ?? ''));
$id = $token !== '' ? ReceiptService::appointmentIdFromPublicToken($token) : null;

if (!$id) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Ticket no disponible</title><body style="font-family:system-ui,sans-serif;padding:32px"><h1>Ticket no disponible</h1><p>El enlace no es valido o ya no esta disponible.</p></body>';
    exit;
}

$d = ReceiptService::hydrate($id);
if (!$d) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Ticket no encontrado</title><body style="font-family:system-ui,sans-serif;padding:32px"><h1>Ticket no encontrado</h1></body>';
    exit;
}

if ($d['status_slug'] !== 'atendida') {
    http_response_code(409);
    echo '<!doctype html><meta charset="utf-8"><title>Ticket pendiente</title><body style="font-family:system-ui,sans-serif;padding:32px"><h1>Ticket pendiente</h1><p>El ticket se habilita cuando la cita esta marcada como atendida.</p></body>';
    exit;
}

if (AppointmentService::isPackageIncludedSession($d)) {
    http_response_code(409);
    echo '<!doctype html><meta charset="utf-8"><title>Sesion incluida</title><body style="font-family:system-ui,sans-serif;padding:32px"><h1>Sesion incluida</h1><p>Esta cita forma parte de un paquete ya pagado y no genera ticket nuevo.</p></body>';
    exit;
}

if (empty($d['receipt_folio'])) {
    $folio = AppointmentService::nextReceiptFolio();
    Database::exec('UPDATE appointments SET receipt_folio = ? WHERE id = ?', [$folio, $id]);
}

header('Content-Type: text/html; charset=utf-8');
echo ReceiptService::render($id);

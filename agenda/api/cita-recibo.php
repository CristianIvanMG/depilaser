<?php
/**
 * GET /agenda/api/cita-recibo.php?id=123
 * Devuelve el HTML del recibo (nota de servicio) listo para imprimir / guardar PDF.
 * Solo admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (!Auth::isAdmin()) {
    http_response_code(403);
    echo 'Solo administradores.';
    exit;
}

AppointmentService::ensureReceiptSchema();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo 'ID inválido.';
    exit;
}

$d = ReceiptService::hydrate($id);
if (!$d) {
    http_response_code(404);
    echo 'Cita no encontrada.';
    exit;
}

if ($d['status_slug'] !== 'atendida') {
    http_response_code(409);
    echo 'El recibo solo se genera cuando la cita está marcada como atendida.';
    exit;
}

// Si no hay folio aún (cita atendida desde antes de la fase 4), créalo on-the-fly
if (empty($d['receipt_folio'])) {
    $folio = AppointmentService::nextReceiptFolio();
    Database::exec('UPDATE appointments SET receipt_folio = ? WHERE id = ?', [$folio, $id]);
}

header('Content-Type: text/html; charset=utf-8');
echo ReceiptService::render($id);

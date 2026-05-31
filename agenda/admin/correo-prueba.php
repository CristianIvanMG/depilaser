<?php
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireAdmin();

$result = null;
$to = trim((string) ($_POST['to'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = ['ok' => false, 'message' => 'Ingresa un correo destino valido.'];
    } else {
        $subject = 'Prueba de correo BellaNick';
        $body = "Hola,\n\n"
            . "Este es un correo de prueba enviado desde la agenda BellaNick.\n\n"
            . "Fecha: " . date('Y-m-d H:i:s') . "\n"
            . "URL: " . APP_BASE_URL . "\n\n"
            . "BellaNick Clinic";
        $sent = MailService::sendPlain($to, '', $subject, $body);
        $result = [
            'ok' => $sent,
            'message' => $sent ? 'El servidor acepto el correo de prueba.' : (MailService::lastError() ?: 'No fue posible enviar el correo de prueba.'),
        ];
    }
}

$pageTitle = 'Prueba de correo';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="bnc-card" style="max-width:680px">
  <div class="bnc-card-header">
    <h2 class="h6 fw-bold mb-0">Prueba de correo transaccional</h2>
  </div>
  <div class="bnc-card-body">
    <?php if ($result): ?>
      <div class="alert alert-<?= $result['ok'] ? 'success' : 'danger' ?>">
        <?= e($result['message']) ?>
      </div>
    <?php endif; ?>
    <form method="POST" class="d-grid gap-3">
      <?= Csrf::input() ?>
      <div>
        <label class="bnc-label">Correo destino</label>
        <input type="email" name="to" class="form-control" value="<?= e($to) ?>" placeholder="tu-correo@gmail.com" required>
      </div>
      <button class="btn btn-bnc-primary" type="submit">
        <i class="bi bi-envelope-paper"></i> Enviar prueba
      </button>
    </form>
    <p class="text-muted small mt-3 mb-0">
      Esta prueba usa la misma configuracion de correo que las confirmaciones de citas.
    </p>
  </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

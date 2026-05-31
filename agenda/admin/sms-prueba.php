<?php
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireAdmin();

$result = null;
$phone = trim((string) ($_POST['phone'] ?? ''));
$message = trim((string) ($_POST['message'] ?? 'BellaNick: prueba de SMS de la agenda.'));
$configStatus = SmsService::configStatus();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $message = $message !== '' ? $message : 'BellaNick: prueba de SMS de la agenda.';
    $result = SmsService::sendTest($phone, $message);
}

$pageTitle = 'Prueba SMS';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="bnc-card" style="max-width:720px">
  <div class="bnc-card-header">
    <h2 class="h6 fw-bold mb-0">Prueba de SMS Masivos</h2>
  </div>
  <div class="bnc-card-body">
    <?php if ($result): ?>
      <div class="alert alert-<?= !empty($result['ok']) ? 'success' : 'danger' ?>">
        <?php if (!empty($result['ok'])): ?>
          SMS aceptado por SMS Masivos<?= !empty($result['reference']) ? ' - Ref. ' . e((string) $result['reference']) : '' ?>.
        <?php else: ?>
          <?= e((string) ($result['error'] ?? 'No fue posible enviar el SMS.')) ?>
          <?php if (!empty($result['code'])): ?>
            <span class="d-block small">Codigo API: <?= e((string) $result['code']) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="d-grid gap-3">
      <?= Csrf::input() ?>
      <div>
        <label class="bnc-label">Telefono destino</label>
        <input type="tel" name="phone" class="form-control" value="<?= e($phone) ?>" placeholder="5512345678" required>
        <div class="form-text">Usa 10 digitos de Mexico. Tambien acepta +52.</div>
      </div>
      <div>
        <label class="bnc-label">Mensaje</label>
        <textarea name="message" class="form-control" rows="3" maxlength="155" required><?= e($message) ?></textarea>
        <div class="form-text">SMS Masivos V2 no acepta acentos; el sistema limpia el texto antes de enviarlo.</div>
      </div>
      <button class="btn btn-bnc-primary" type="submit">
        <i class="bi bi-chat-dots"></i> Enviar SMS de prueba
      </button>
    </form>
    <p class="text-muted small mt-3 mb-0">
      Esta prueba usa la misma configuracion que el cron de recordatorios de citas.
    </p>
    <hr>
    <div class="small text-muted">
      <div><strong>Diagnostico:</strong></div>
      <div>Archivo: <?= e((string) $configStatus['config_path']) ?></div>
      <div>Habilitado: <?= !empty($configStatus['enabled']) ? 'si' : 'no' ?></div>
      <div>API key detectada: <?= !empty($configStatus['has_apikey']) ? 'si (' . e((string) $configStatus['apikey_preview']) . ')' : 'no' ?></div>
      <div>Sandbox: <?= !empty($configStatus['sandbox']) ? 'si' : 'no' ?></div>
      <div>Base URL: <?= e((string) $configStatus['base_url']) ?></div>
      <div>Rutas revisadas:</div>
      <ul class="mb-0">
        <?php foreach (($configStatus['config_paths_checked'] ?? []) as $checkedPath): ?>
          <li><?= e((string) $checkedPath) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

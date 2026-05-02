<?php
require_once __DIR__ . '/includes/bootstrap.php';

$token = trim($_GET['token'] ?? '');
$result = EmailVerification::confirm($token);

if ($result['ok']) {
    flash('success', $result['message']);
} else {
    flash(!empty($result['expired']) ? 'warning' : 'danger', $result['message']);
}

$pageTitle = 'Confirmación de correo';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container py-4 py-md-5">
  <div class="bnc-card mx-auto" style="max-width: 620px;">
    <div class="bnc-card-body p-md-5 text-center">
      <div class="bnc-stat-icon mx-auto mb-3">
        <i class="bi <?= $result['ok'] ? 'bi-check2-circle' : 'bi-exclamation-triangle' ?>"></i>
      </div>
      <h1 class="h3 fw-bold mb-2"><?= $result['ok'] ? 'Correo confirmado' : 'No pudimos confirmar tu correo' ?></h1>
      <p class="text-muted mb-4"><?= e($result['message']) ?></p>

      <?php if ($result['ok']): ?>
        <a href="<?= url(Auth::check() ? '' : 'login.php') ?>" class="btn btn-bnc-primary">Continuar</a>
      <?php elseif (Auth::check()): ?>
        <a href="<?= url('verificar-email.php') ?>" class="btn btn-bnc-primary">Solicitar nuevo enlace</a>
      <?php else: ?>
        <a href="<?= url('login.php') ?>" class="btn btn-bnc-primary">Iniciar sesión</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

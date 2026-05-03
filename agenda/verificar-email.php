<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();

$user = Auth::user();
$rawNext  = $_POST['next'] ?? $_GET['next'] ?? '';
$safeNext = safe_next($rawNext) ?? Auth::defaultLanding(Auth::role());

if (Auth::emailVerified()) {
    flash('success', 'Tu correo ya fue confirmado correctamente.');
    redirect($safeNext);
}

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $result = EmailVerification::issueAndSend((int) $user['id']);
    $notice = $result;
    flash($result['ok'] ? 'success' : 'warning', $result['message']);
    redirect('verificar-email.php?next=' . urlencode($safeNext));
}

$pageTitle = 'Confirmar correo';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container py-4 py-md-5">
  <div class="bnc-card mx-auto" style="max-width: 620px;">
    <div class="bnc-card-body p-md-5 text-center">
      <div class="bnc-stat-icon mx-auto mb-3"><i class="bi bi-envelope-check"></i></div>
      <h1 class="h3 fw-bold mb-2">Confirma tu correo electrónico</h1>
      <p class="text-muted mb-4">
        Enviamos un enlace de confirmación a <strong><?= e($user['email']) ?></strong>.
        Para proteger tu cuenta, confirma tu correo antes de agendar o gestionar citas.
      </p>

      <div class="alert alert-info text-start">
        Revisa tu bandeja de entrada y también spam o promociones. El enlace vence en 24 horas.
      </div>

      <form method="POST" class="d-inline-block">
        <?= Csrf::input() ?>
        <input type="hidden" name="next" value="<?= e($safeNext) ?>">
        <button class="btn btn-bnc-primary" type="submit"><i class="bi bi-send"></i> Reenviar correo</button>
      </form>
      <a href="<?= url('logout.php') ?>" class="btn btn-bnc-outline ms-2">Salir</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

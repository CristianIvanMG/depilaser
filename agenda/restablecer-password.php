<?php
require_once __DIR__ . '/includes/bootstrap.php';

Auth::enforceSessionTimeout();

if (Auth::check()) {
    redirect(Auth::defaultLanding(Auth::role()));
}

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenStatus = PasswordResetService::validateToken($token);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');

    $result = PasswordResetService::resetPassword(
        $token,
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['password_confirm'] ?? '')
    );

    if (!empty($result['ok'])) {
        flash('success', 'Tu contrasena fue actualizada correctamente. Ya puedes iniciar sesion.');
        redirect('login.php');
    }

    $errors = $result['errors'] ?? ['_' => 'No pudimos actualizar tu contrasena. Intenta nuevamente.'];
    $tokenStatus = PasswordResetService::validateToken($token);
}

$pageTitle = 'Crear nueva contrasena';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container">
  <div class="bnc-auth-shell">

    <aside class="bnc-auth-aside">
      <h2>Crea una contrasena nueva</h2>
      <p>Usa una clave segura que solo tu conozcas para mantener protegida tu cuenta.</p>
      <ul>
        <li>Minimo 8 caracteres</li>
        <li>Al menos una mayuscula</li>
        <li>Al menos un numero</li>
        <li>El enlace no podra reutilizarse</li>
      </ul>
    </aside>

    <div class="bnc-card mx-auto" style="max-width: 460px; width: 100%;">
      <div class="bnc-card-body p-md-5">
        <h1 class="h3 fw-bold mb-1">Nueva contrasena</h1>
        <p class="text-muted small mb-4">Define una contrasena segura para volver a entrar.</p>

        <?php if (!$tokenStatus['ok']): ?>
          <div class="alert alert-warning small"><?= e($tokenStatus['message']) ?></div>
          <a href="<?= url('recuperar-password.php') ?>" class="btn btn-bnc-primary w-100 py-2">Solicitar nuevo enlace</a>
        <?php else: ?>
          <?php if (!empty($errors['_'])): ?>
            <div class="alert alert-danger small"><?= e($errors['_']) ?></div>
          <?php endif; ?>

          <form method="POST" novalidate>
            <?= Csrf::input() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="mb-3">
              <label class="bnc-label" for="password">Nueva contrasena</label>
              <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                     id="password" name="password" required autofocus autocomplete="new-password" minlength="8">
              <?php if (isset($errors['password'])): ?>
                <div class="invalid-feedback"><?= e($errors['password']) ?></div>
              <?php else: ?>
                <small class="text-muted">Minimo 8 caracteres, 1 mayuscula y 1 numero.</small>
              <?php endif; ?>
            </div>

            <div class="mb-3">
              <label class="bnc-label" for="password_confirm">Confirmar contrasena</label>
              <input type="password" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                     id="password_confirm" name="password_confirm" required autocomplete="new-password">
              <?php if (isset($errors['password_confirm'])): ?>
                <div class="invalid-feedback"><?= e($errors['password_confirm']) ?></div>
              <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-bnc-primary w-100 mt-2 py-2">Guardar contrasena</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

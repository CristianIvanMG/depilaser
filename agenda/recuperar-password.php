<?php
require_once __DIR__ . '/includes/bootstrap.php';

Auth::enforceSessionTimeout();

if (Auth::check()) {
    redirect(Auth::defaultLanding(Auth::role()));
}

$errors = [];
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');

    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '') {
        $errors['email'] = 'Ingresa tu correo electronico.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Ingresa un correo valido.';
    }

    if (!$errors) {
        $result = PasswordResetService::request($email);
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
        } else {
            $sent = true;
            clear_old();
        }
    }

    if (!$sent) {
        set_old(['email' => $email]);
    }
}

$pageTitle = 'Recuperar contrasena';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container">
  <div class="bnc-auth-shell">

    <aside class="bnc-auth-aside">
      <h2>Recupera tu acceso con seguridad</h2>
      <p>Te enviaremos un enlace privado para crear una nueva contrasena y volver a tu agenda.</p>
      <ul>
        <li>El enlace vence automaticamente</li>
        <li>Solo puede usarse una vez</li>
        <li>No compartimos si un correo existe o no</li>
        <li>Tu cuenta se mantiene protegida</li>
      </ul>
    </aside>

    <div class="bnc-card mx-auto" style="max-width: 460px; width: 100%;">
      <div class="bnc-card-body p-md-5">
        <h1 class="h3 fw-bold mb-1">Recuperar contrasena</h1>
        <p class="text-muted small mb-4">Escribe el correo asociado a tu cuenta.</p>

        <?php if ($sent): ?>
          <div class="alert alert-success small">
            Si el correo esta registrado, te enviamos un enlace para restablecer tu contrasena.
          </div>
          <a href="<?= url('login.php') ?>" class="btn btn-bnc-primary w-100 py-2">Volver al login</a>
        <?php else: ?>
          <form method="POST" novalidate>
            <?= Csrf::input() ?>

            <div class="mb-3">
              <label class="bnc-label" for="email">Correo electronico</label>
              <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                     id="email" name="email" value="<?= old('email') ?>" required autofocus autocomplete="email">
              <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= e($errors['email']) ?></div>
              <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-bnc-primary w-100 mt-2 py-2">Enviar enlace</button>

            <p class="text-center small text-muted mt-4 mb-0">
              <a href="<?= url('login.php') ?>" class="fw-bold text-decoration-none" style="color:var(--bnc-pink)">Volver a iniciar sesion</a>
            </p>
          </form>
        <?php endif; ?>
      </div>
    </div>

  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

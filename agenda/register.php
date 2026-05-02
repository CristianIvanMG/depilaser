<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    redirect('');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');

    $result = Auth::register($_POST);

    if ($result['ok']) {
        // Auto-login
        Auth::attempt($_POST['email'], $_POST['password']);
        flash('success', '¡Cuenta creada! Bienvenida a BellaNick.');
        redirect('');
    }

    $errors = $result['errors'];
    set_old([
        'name'  => $_POST['name']  ?? '',
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
    ]);
}

$pageTitle = 'Crear cuenta';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container">
  <div class="bnc-auth-shell">

    <aside class="bnc-auth-aside">
      <h2>Crea tu cuenta en 30 segundos</h2>
      <p>Es 100 % gratis. Reserva tu primera cita de valoración sin costo.</p>
      <ul>
        <li>Agenda en cualquier momento, desde donde estés</li>
        <li>Cancela o reprograma con 1 clic (mín. 24 h antes)</li>
        <li>Tu información está protegida (LFPDPPP)</li>
        <li>Soporte por WhatsApp en horario de clínica</li>
      </ul>
    </aside>

    <div class="bnc-card mx-auto" style="max-width: 480px; width: 100%;">
      <div class="bnc-card-body p-md-5">
        <h1 class="h3 fw-bold mb-1">Crear cuenta</h1>
        <p class="text-muted small mb-4">Tarda menos de un minuto.</p>

        <form method="POST" novalidate>
          <?= Csrf::input() ?>

          <div class="mb-3">
            <label class="bnc-label" for="name">Nombre completo</label>
            <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                   id="name" name="name" value="<?= old('name') ?>" required autocomplete="name" minlength="2">
            <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
          </div>

          <div class="row g-3">
            <div class="col-12 col-md-7 mb-3">
              <label class="bnc-label" for="email">Correo electrónico</label>
              <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                     id="email" name="email" value="<?= old('email') ?>" required autocomplete="email">
              <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="col-12 col-md-5 mb-3">
              <label class="bnc-label" for="phone">Teléfono</label>
              <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                     id="phone" name="phone" value="<?= old('phone') ?>" required autocomplete="tel" minlength="10" placeholder="55 1234 5678">
              <?php if (isset($errors['phone'])): ?><div class="invalid-feedback"><?= e($errors['phone']) ?></div><?php endif; ?>
            </div>
          </div>

          <div class="mb-3">
            <label class="bnc-label" for="password">Contraseña</label>
            <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                   id="password" name="password" required autocomplete="new-password" minlength="8">
            <?php if (isset($errors['password'])): ?>
              <div class="invalid-feedback"><?= e($errors['password']) ?></div>
            <?php else: ?>
              <small class="text-muted">Mínimo 8 caracteres, 1 mayúscula y 1 número.</small>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label class="bnc-label" for="password_confirm">Confirmar contraseña</label>
            <input type="password" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                   id="password_confirm" name="password_confirm" required autocomplete="new-password">
            <?php if (isset($errors['password_confirm'])): ?><div class="invalid-feedback"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="terms" required>
            <label class="form-check-label small" for="terms">
              Acepto el <a href="https://depilasermexico.com/aviso-privacidad.html" target="_blank" style="color:var(--bnc-pink)">Aviso de privacidad</a> y términos de uso.
            </label>
          </div>

          <button type="submit" class="btn btn-bnc-primary w-100 py-2">Crear mi cuenta</button>

          <p class="text-center small text-muted mt-4 mb-0">
            ¿Ya tienes cuenta?
            <a href="<?= url('login.php') ?>" class="fw-bold text-decoration-none" style="color:var(--bnc-pink)">Inicia sesión</a>
          </p>
        </form>
      </div>
    </div>

  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

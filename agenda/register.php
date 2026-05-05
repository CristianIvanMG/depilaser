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
        Auth::attempt($_POST['email'], $_POST['password']);
        $verification = $result['verification'] ?? ['ok' => true, 'message' => 'Te enviamos un correo para confirmar tu cuenta.'];
        flash(!empty($verification['mail_sent']) ? 'success' : 'warning', $verification['message']);
        redirect('verificar-email.php');
    }

    $errors = $result['errors'];
    set_old([
        'first_name' => $_POST['first_name'] ?? '',
        'last_name'  => $_POST['last_name']  ?? '',
        'email'      => $_POST['email']      ?? '',
        'phone'      => $_POST['phone']      ?? '',
        'birth_date' => $_POST['birth_date'] ?? '',
        'gender'     => $_POST['gender']     ?? '',
        'address'    => $_POST['address']    ?? '',
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
        <li>Tu información está protegida</li>
        <li>Soporte por WhatsApp en horario de clínica</li>
      </ul>
    </aside>

    <div class="bnc-card mx-auto" style="max-width: 480px; width: 100%;">
      <div class="bnc-card-body p-md-5">
        <h1 class="h3 fw-bold mb-1">Crear cuenta</h1>
        <p class="text-muted small mb-4">Tarda menos de un minuto.</p>

        <form method="POST" novalidate>
          <?= Csrf::input() ?>

          <div class="row g-3">
            <div class="col-12 col-md-6 mb-3">
              <label class="bnc-label" for="first_name">Nombres</label>
              <input type="text" class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                     id="first_name" name="first_name" value="<?= old('first_name') ?>" required autocomplete="given-name" minlength="2">
              <?php if (isset($errors['first_name'])): ?><div class="invalid-feedback"><?= e($errors['first_name']) ?></div><?php endif; ?>
            </div>
            <div class="col-12 col-md-6 mb-3">
              <label class="bnc-label" for="last_name">Apellidos</label>
              <input type="text" class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                     id="last_name" name="last_name" value="<?= old('last_name') ?>" required autocomplete="family-name" minlength="2">
              <?php if (isset($errors['last_name'])): ?><div class="invalid-feedback"><?= e($errors['last_name']) ?></div><?php endif; ?>
            </div>
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

          <details class="mb-3 bnc-details-soft<?= (isset($errors['birth_date']) || isset($errors['gender']) || isset($errors['address'])) ? ' open-by-error' : '' ?>"
                   <?= (isset($errors['birth_date']) || isset($errors['gender']) || isset($errors['address']) || old('birth_date') !== '' || old('gender') !== '' || old('address') !== '') ? 'open' : '' ?>>
            <summary class="small text-muted" style="cursor:pointer;user-select:none">
              <i class="bi bi-plus-circle me-1"></i> Datos adicionales (opcionales)
            </summary>
            <div class="row g-3 mt-1">
              <div class="col-12 col-md-6">
                <label class="bnc-label" for="birth_date">Fecha de nacimiento</label>
                <input type="date" class="form-control <?= isset($errors['birth_date']) ? 'is-invalid' : '' ?>"
                       id="birth_date" name="birth_date" value="<?= old('birth_date') ?>" max="<?= date('Y-m-d') ?>">
                <?php if (isset($errors['birth_date'])): ?><div class="invalid-feedback"><?= e($errors['birth_date']) ?></div><?php endif; ?>
              </div>
              <div class="col-12 col-md-6">
                <label class="bnc-label" for="gender">Sexo</label>
                <select class="form-select <?= isset($errors['gender']) ? 'is-invalid' : '' ?>" id="gender" name="gender">
                  <option value="">— Selecciona —</option>
                  <?php foreach (ClientProfile::genderOptions() as $slug => $label): ?>
                    <option value="<?= e($slug) ?>" <?= old('gender') === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (isset($errors['gender'])): ?><div class="invalid-feedback"><?= e($errors['gender']) ?></div><?php endif; ?>
              </div>
              <div class="col-12">
                <label class="bnc-label" for="address">Dirección</label>
                <input type="text" class="form-control <?= isset($errors['address']) ? 'is-invalid' : '' ?>"
                       id="address" name="address" value="<?= old('address') ?>" maxlength="255" placeholder="Calle, número, colonia, ciudad">
                <?php if (isset($errors['address'])): ?><div class="invalid-feedback"><?= e($errors['address']) ?></div><?php endif; ?>
              </div>
            </div>
          </details>

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

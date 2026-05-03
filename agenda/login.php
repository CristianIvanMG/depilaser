<?php
require_once __DIR__ . '/includes/bootstrap.php';

Auth::enforceSessionTimeout();

$botScope = 'login';
$rawNext = $_POST['next'] ?? $_GET['next'] ?? '';
$next = safe_next($rawNext);

if (Auth::check()) {
    if (Auth::isClient() && !Auth::emailVerified()) {
        redirect('verificar-email.php' . ($next ? '?next=' . urlencode($next) : ''));
    }
    redirect($next ?? Auth::defaultLanding(Auth::role()));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');

    $errors = Validator::login($_POST);
    $humanOk = BotProtection::validate($botScope, $_POST);

    if (BotProtection::isLocked($botScope)) {
        $errors['_'] = BotProtection::lockedMessage($botScope);
    } elseif (!$humanOk) {
        $errors['_'] = 'No pudimos validar el acceso. Revisa tus datos e intenta nuevamente.';
    }

    if (!$errors) {
        $user = Auth::attempt($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($user) {
            BotProtection::reset($botScope);
            clear_old();
            $role = $user['role_slug'] ?? '';

            if ($role === Auth::ROLE_CLIENT && (int) ($user['email_verified'] ?? 0) !== 1) {
                redirect('verificar-email.php' . ($next ? '?next=' . urlencode($next) : ''));
            }

            $base = app_base_path();
            $isAdminPath = $next && str_starts_with($next, $base . '/admin/');
            $canAdmin = in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_SUPERADMIN], true);
            $isProfessionalAgenda = $next === $base . '/admin/calendario.php';
            $canProfessionalAgenda = $role === Auth::ROLE_PROFESSIONAL && $isProfessionalAgenda;

            if ($next && (!$isAdminPath || $canAdmin || $canProfessionalAgenda)) {
                redirect($next);
            }
            redirect(Auth::defaultLanding($role));
        }
        $errors['_'] = 'No pudimos iniciar sesión. Revisa tus datos e intenta nuevamente.';
        set_old(['email' => $_POST['email'] ?? '']);
    } else {
        set_old(['email' => $_POST['email'] ?? '']);
    }
}

$botChallenge = BotProtection::challenge($botScope);
$pageTitle = 'Iniciar sesión';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container">
  <div class="bnc-auth-shell">

    <aside class="bnc-auth-aside">
      <h2>Bienvenida de vuelta a BellaNick Clinic</h2>
      <p>Tu agenda online: reserva, consulta y cancela tus citas las 24 horas.</p>
      <ul>
        <li>Disponibilidad en tiempo real</li>
        <li>3 sucursales: Roma Sur, Insurgentes, Querétaro</li>
        <li>Recordatorios automáticos</li>
        <li>Historial completo de tus sesiones</li>
      </ul>
    </aside>

    <div class="bnc-card mx-auto" style="max-width: 460px; width: 100%;">
      <div class="bnc-card-body p-md-5">
        <h1 class="h3 fw-bold mb-1">Iniciar sesión</h1>
        <p class="text-muted small mb-4">Ingresa para gestionar tus citas.</p>

        <?php if (!empty($errors['_'])): ?>
          <div class="alert alert-danger small"><?= e($errors['_']) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= Csrf::input() ?>
          <?php if ($next): ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
          <?php endif; ?>
          <input type="hidden" name="bot_token" value="<?= e($botChallenge['token']) ?>">

          <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
            <label for="company_site">Sitio web</label>
            <input type="text" id="company_site" name="company_site" tabindex="-1" autocomplete="off">
          </div>

          <div class="mb-3">
            <label class="bnc-label" for="email">Correo electrónico</label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   id="email" name="email" value="<?= old('email') ?>" required autofocus autocomplete="email">
            <?php if (isset($errors['email'])): ?>
              <div class="invalid-feedback"><?= e($errors['email']) ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-2">
            <label class="bnc-label d-flex justify-content-between align-items-center" for="password">
              <span>Contraseña</span>
              <a href="<?= url('recuperar-password.php') ?>" class="small text-decoration-none" style="color:var(--bnc-pink)">¿Olvidaste tu contraseña?</a>
            </label>
            <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                   id="password" name="password" required autocomplete="current-password">
            <?php if (isset($errors['password'])): ?>
              <div class="invalid-feedback"><?= e($errors['password']) ?></div>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <label class="bnc-label" for="bot_answer">Verificación</label>
            <div class="p-3 rounded-3" style="background:var(--bnc-pink-bg);border:1px solid var(--bnc-line)">
              <div class="small text-muted mb-2">Confirma que no eres bot para iniciar sesión.</div>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <strong><?= e($botChallenge['question']) ?></strong>
                <input type="text" inputmode="numeric" pattern="[0-9]*"
                       class="form-control form-control-sm"
                       id="bot_answer" name="bot_answer" required autocomplete="off"
                       style="max-width:120px" aria-label="Respuesta de verificación">
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-bnc-primary w-100 mt-3 py-2">Entrar</button>

          <p class="text-center small text-muted mt-4 mb-0">
            ¿Aún no tienes cuenta?
            <a href="<?= url('register.php') ?>" class="fw-bold text-decoration-none" style="color:var(--bnc-pink)">Crea una gratis</a>
          </p>
        </form>
      </div>
    </div>

  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

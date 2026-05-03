<?php
require_once __DIR__ . '/includes/bootstrap.php';

Auth::enforceSessionTimeout();

// `next` saneado: válido solo si pertenece a la app y no apunta a /login|/logout.
// Lo aceptamos por GET (el guard lo añade) o por POST (hidden input para sobrevivir submit).
$rawNext = $_POST['next'] ?? $_GET['next'] ?? '';
$next    = safe_next($rawNext);   // string|null

// Si ya está logueado, redirige a su área (respetando next si es válido)
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
    if (!$errors) {
        $user = Auth::attempt($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($user) {
            clear_old();
            $role = $user['role_slug'] ?? '';

            // Cliente sin verificar correo → primero verificar
            if ($role === Auth::ROLE_CLIENT && (int) ($user['email_verified'] ?? 0) !== 1) {
                redirect('verificar-email.php' . ($next ? '?next=' . urlencode($next) : ''));
            }

            // Si trajimos un next válido y el rol tiene permisos para esa ruta,
            // úsalo. Si no, default landing por rol.
            //
            // Heurística simple: las rutas /agenda/admin/* requieren rol admin/super.
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
        $errors['_'] = 'Correo o contraseña incorrectos. Si fallas 5 veces, tu IP se bloqueará 10 minutos.';
        set_old(['email' => $_POST['email'] ?? '']);
    } else {
        set_old(['email' => $_POST['email'] ?? '']);
    }
}

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

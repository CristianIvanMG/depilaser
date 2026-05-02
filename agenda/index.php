<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Si no hay sesión → al login
if (!Auth::check()) {
    redirect('login.php');
}

// Si es admin → al panel admin
if (Auth::isAdmin()) {
    redirect('admin/');
}

if (!Auth::emailVerified()) {
    redirect('verificar-email.php');
}

// Cliente → dashboard simple
$user = Auth::user();

// Próximas citas
$upcoming = Database::all(
    "SELECT a.id, a.code, a.start_at, a.end_at,
            s.name AS service_name, s.duration_min,
            b.name AS branch_name, b.address AS branch_address,
            st.slug AS status_slug, st.name AS status_name, st.color_hex
     FROM appointments a
     JOIN services s ON s.id = a.service_id
     JOIN branches b ON b.id = a.branch_id
     JOIN appointment_statuses st ON st.id = a.status_id
     WHERE a.user_id = ? AND a.start_at >= NOW() AND st.slug NOT IN ('cancelada','no_asistio')
     ORDER BY a.start_at ASC
     LIMIT 5",
    [$user['id']]
);

// Stats
$totalCitas = (int) (Database::one('SELECT COUNT(*) AS n FROM appointments WHERE user_id = ?', [$user['id']])['n'] ?? 0);
$completadas = (int) (Database::one("SELECT COUNT(*) AS n FROM appointments a JOIN appointment_statuses s ON s.id=a.status_id WHERE a.user_id = ? AND s.slug='atendida'", [$user['id']])['n'] ?? 0);
$proximas = count($upcoming);

$pageTitle = 'Mi cuenta';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container py-4 py-md-5">

  <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
    <div>
      <h1 class="h3 fw-bold mb-1">Hola, <?= e(explode(' ', $user['name'])[0]) ?> 👋</h1>
      <p class="text-muted mb-0">Bienvenida a tu agenda BellaNick. Gestiona tus citas en un solo lugar.</p>
    </div>
    <a href="<?= url('agendar.php') ?>" class="btn btn-bnc-primary ms-md-auto">
      <i class="bi bi-calendar-plus"></i> Agendar nueva cita
    </a>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
      <div class="bnc-stat-card">
        <div class="bnc-stat-icon"><i class="bi bi-calendar-event"></i></div>
        <div>
          <div class="bnc-stat-num"><?= $proximas ?></div>
          <div class="bnc-stat-label">Citas próximas</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="bnc-stat-card">
        <div class="bnc-stat-icon"><i class="bi bi-check2-circle"></i></div>
        <div>
          <div class="bnc-stat-num"><?= $completadas ?></div>
          <div class="bnc-stat-label">Sesiones completadas</div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="bnc-stat-card">
        <div class="bnc-stat-icon"><i class="bi bi-clipboard2-pulse"></i></div>
        <div>
          <div class="bnc-stat-num"><?= $totalCitas ?></div>
          <div class="bnc-stat-label">Total histórico</div>
        </div>
      </div>
    </div>
  </div>

  <div class="bnc-card">
    <div class="bnc-card-header d-flex justify-content-between align-items-center">
      <h2 class="h5 mb-0 fw-bold">Tus próximas citas</h2>
      <a href="<?= url('mis-citas.php') ?>" class="text-decoration-none small fw-bold" style="color:var(--bnc-pink)">Ver todas →</a>
    </div>
    <div class="bnc-card-body">
      <?php if (!$upcoming): ?>
        <div class="text-center py-4">
          <i class="bi bi-calendar-x" style="font-size:48px; color:var(--bnc-pink); opacity:.5"></i>
          <p class="mt-3 mb-3 text-muted">Aún no tienes citas programadas.</p>
          <a href="<?= url('agendar.php') ?>" class="btn btn-bnc-primary">Agendar mi primera cita</a>
        </div>
      <?php else: ?>
        <?php foreach ($upcoming as $a): $ts = strtotime($a['start_at']); ?>
          <div class="bnc-appt">
            <div class="bnc-appt-date">
              <div class="day"><?= (int) date('d', $ts) ?></div>
              <div class="mon"><?= e(['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][(int) date('n', $ts) - 1]) ?></div>
              <div class="time"><?= date('H:i', $ts) ?></div>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 mb-1">
                <strong><?= e($a['service_name']) ?></strong>
                <span class="bnc-status <?= e($a['status_slug']) ?>"><?= e($a['status_name']) ?></span>
              </div>
              <div class="small text-muted">
                <i class="bi bi-geo-alt"></i> <?= e($a['branch_name']) ?> · <?= (int) $a['duration_min'] ?> min
              </div>
              <div class="small text-muted">Código: <code><?= e($a['code']) ?></code></div>
            </div>
            <div class="align-self-center">
              <a href="<?= url('mis-citas.php#cita-' . (int) $a['id']) ?>" class="btn btn-sm btn-bnc-outline">Detalle</a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!Auth::check()) {
    redirect('login.php');
}
if (Auth::isAdmin()) {
    redirect('admin/');
}
if (!Auth::emailVerified()) {
    redirect('verificar-email.php');
}

$user = Auth::user();
TreatmentProgressService::ensureSchema();

$reviewInvite = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_progress') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $result = TreatmentProgressService::submit(
        (int) $user['id'],
        (int) ($_POST['appointment_id'] ?? 0),
        (string) ($_POST['progress_level'] ?? '')
    );
    if ($result['ok']) {
        flash('success', 'Gracias, tu avance quedó registrado.');
        if (($result['level'] ?? '') === 'high' && !empty($result['review_url'])) {
            $_SESSION['review_invite'] = [
                'url' => $result['review_url'],
                'branch_name' => $result['branch_name'] ?: 'BellaNick Clinic',
            ];
        }
    } else {
        flash('danger', $result['error'] ?? 'No fue posible guardar tu avance.');
    }
    redirect('');
}

if (!empty($_SESSION['review_invite'])) {
    $reviewInvite = $_SESSION['review_invite'];
    unset($_SESSION['review_invite']);
}

$upcoming = Database::all(
    "SELECT a.id, a.code, a.start_at, a.end_at,
            s.name AS service_name, s.duration_min,
            b.name AS branch_name, b.address AS branch_address, b.whatsapp,
            st.slug AS status_slug, st.name AS status_name
     FROM appointments a
     JOIN services s ON s.id = a.service_id
     JOIN branches b ON b.id = a.branch_id
     JOIN appointment_statuses st ON st.id = a.status_id
     WHERE a.user_id = ? AND a.start_at >= NOW() AND st.slug NOT IN ('cancelada','no_asistio')
     ORDER BY a.start_at ASC
     LIMIT 5",
    [$user['id']]
);
$nextAppointment = $upcoming[0] ?? null;

$history = Database::all(
    "SELECT a.id, a.code, a.start_at, a.end_at,
            s.name AS service_name, s.duration_min,
            b.name AS branch_name,
            st.slug AS status_slug, st.name AS status_name
     FROM appointments a
     JOIN services s ON s.id = a.service_id
     JOIN branches b ON b.id = a.branch_id
     JOIN appointment_statuses st ON st.id = a.status_id
     WHERE a.user_id = ?
     ORDER BY a.start_at DESC
     LIMIT 6",
    [$user['id']]
);

$eligibleProgress = TreatmentProgressService::eligibleForUser((int) $user['id'], 4);
$soonProgress = TreatmentProgressService::pendingSoonForUser((int) $user['id'], 2);
$recentProgress = TreatmentProgressService::recentForUser((int) $user['id'], 4);

$totalCitas = (int) (Database::one('SELECT COUNT(*) AS n FROM appointments WHERE user_id = ?', [$user['id']])['n'] ?? 0);
$completadas = (int) (Database::one("SELECT COUNT(*) AS n FROM appointments a JOIN appointment_statuses s ON s.id=a.status_id WHERE a.user_id = ? AND s.slug='atendida'", [$user['id']])['n'] ?? 0);
$proximas = count($upcoming);

$pageTitle = 'Mi cuenta';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container py-4 py-md-5 bnc-client-profile">
  <div class="bnc-profile-hero mb-4">
    <div>
      <span class="bnc-profile-kicker">Tu espacio BellaNick</span>
      <h1>Hola, <?= e(explode(' ', $user['name'])[0]) ?></h1>
      <p>Agenda, revisa tu próxima visita y comparte tu avance cuando sea momento.</p>
    </div>
    <a href="<?= url('agendar.php') ?>" class="btn btn-bnc-primary">
      <i class="bi bi-calendar-plus"></i> Nueva cita
    </a>
  </div>

  <?php if ($reviewInvite): ?>
    <div class="bnc-review-invite mb-4">
      <div>
        <strong>Qué gusto saber que vas muy bien.</strong>
        <p class="mb-0">Tu experiencia puede ayudar a otras personas a sentirse seguras al elegirnos.</p>
      </div>
      <a class="btn btn-bnc-outline" href="<?= e($reviewInvite['url']) ?>" target="_blank" rel="noopener">
        <i class="bi bi-star-fill"></i> Dejar reseña
      </a>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
      <div class="bnc-stat-card"><div class="bnc-stat-icon"><i class="bi bi-calendar-heart"></i></div><div><div class="bnc-stat-num"><?= $proximas ?></div><div class="bnc-stat-label">Próximas</div></div></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="bnc-stat-card"><div class="bnc-stat-icon"><i class="bi bi-check2-circle"></i></div><div><div class="bnc-stat-num"><?= $completadas ?></div><div class="bnc-stat-label">Atendidas</div></div></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="bnc-stat-card"><div class="bnc-stat-icon"><i class="bi bi-clipboard2-pulse"></i></div><div><div class="bnc-stat-num"><?= $totalCitas ?></div><div class="bnc-stat-label">Historial</div></div></div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-xl-7">
      <div class="bnc-card bnc-next-card mb-4">
        <div class="bnc-card-header d-flex align-items-center justify-content-between">
          <h2 class="h6 fw-bold mb-0">Tu próxima cita</h2>
          <a href="<?= url('mis-citas.php') ?>" class="small fw-bold text-decoration-none" style="color:var(--bnc-pink)">Ver todas</a>
        </div>
        <div class="bnc-card-body">
          <?php if (!$nextAppointment): ?>
            <div class="bnc-empty-state">
              <i class="bi bi-calendar-plus"></i>
              <strong>No tienes cita programada</strong>
              <span>Elige sucursal, servicio y horario en pocos pasos.</span>
              <a href="<?= url('agendar.php') ?>" class="btn btn-bnc-primary btn-sm mt-2">Agendar ahora</a>
            </div>
          <?php else: $ts = strtotime($nextAppointment['start_at']); ?>
            <div class="bnc-next-appt">
              <div class="bnc-big-date">
                <span><?= (int) date('d', $ts) ?></span>
                <small><?= e(['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][(int) date('n', $ts) - 1]) ?></small>
              </div>
              <div class="flex-grow-1">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                  <h3 class="h5 fw-bold mb-0"><?= e($nextAppointment['service_name']) ?></h3>
                  <span class="bnc-status <?= e($nextAppointment['status_slug']) ?>"><?= e($nextAppointment['status_name']) ?></span>
                </div>
                <div class="text-muted small mb-1"><i class="bi bi-clock"></i> <?= date('H:i', $ts) ?> · <?= (int) $nextAppointment['duration_min'] ?> min</div>
                <div class="text-muted small mb-1"><i class="bi bi-geo-alt"></i> <?= e($nextAppointment['branch_name']) ?></div>
                <div class="small">Código: <code><?= e($nextAppointment['code']) ?></code></div>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <a href="<?= url('mis-citas.php#cita-' . (int) $nextAppointment['id']) ?>" class="btn btn-bnc-outline btn-sm">Ver detalle</a>
              <?php if (!empty($nextAppointment['whatsapp'])): ?>
                <a href="https://wa.me/<?= e($nextAppointment['whatsapp']) ?>?text=Hola,%20tengo%20cita%20<?= e($nextAppointment['code']) ?>" class="btn btn-success btn-sm"><i class="bi bi-whatsapp"></i> WhatsApp</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="bnc-card">
        <div class="bnc-card-header d-flex align-items-center justify-content-between">
          <h2 class="h6 fw-bold mb-0">Actividad reciente</h2>
          <a href="<?= url('mis-citas.php') ?>" class="small fw-bold text-decoration-none" style="color:var(--bnc-pink)">Historial completo</a>
        </div>
        <div class="bnc-card-body">
          <?php if (!$history): ?>
            <p class="text-muted small mb-0">Aún no tienes historial de citas.</p>
          <?php else: foreach ($history as $a): $ts = strtotime($a['start_at']); ?>
            <div class="bnc-mini-appt">
              <div class="bnc-mini-date"><?= date('d/m', $ts) ?></div>
              <div class="flex-grow-1">
                <strong><?= e($a['service_name']) ?></strong>
                <div class="small text-muted"><?= e($a['branch_name']) ?> · <?= date('H:i', $ts) ?></div>
              </div>
              <span class="bnc-status <?= e($a['status_slug']) ?>"><?= e($a['status_name']) ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="bnc-card bnc-progress-card">
        <div class="bnc-card-header">
          <h2 class="h6 fw-bold mb-0">Seguimiento de tu tratamiento</h2>
        </div>
        <div class="bnc-card-body">
          <?php if (!$eligibleProgress && !$soonProgress && !$recentProgress): ?>
            <div class="bnc-empty-state py-3">
              <i class="bi bi-heart-pulse"></i>
              <strong>Aún no hay seguimiento disponible</strong>
              <span>Se activa 3 semanas después de una cita atendida.</span>
            </div>
          <?php endif; ?>

          <?php foreach ($eligibleProgress as $item): ?>
            <form method="POST" class="bnc-progress-form mb-3">
              <?= Csrf::input() ?>
              <input type="hidden" name="action" value="save_progress">
              <input type="hidden" name="appointment_id" value="<?= (int) $item['id'] ?>">
              <div class="mb-2">
                <strong><?= e($item['service_name']) ?></strong>
                <div class="small text-muted"><?= e($item['branch_name']) ?> · <?= e(fmt_dt_short($item['start_at'])) ?></div>
              </div>
              <div class="bnc-progress-options">
                <button name="progress_level" value="low" class="btn" type="submit"><i class="bi bi-droplet"></i><span>Bajo</span></button>
                <button name="progress_level" value="medium" class="btn" type="submit"><i class="bi bi-droplet-half"></i><span>Medio</span></button>
                <button name="progress_level" value="high" class="btn" type="submit"><i class="bi bi-stars"></i><span>Alto</span></button>
              </div>
            </form>
          <?php endforeach; ?>

          <?php foreach ($soonProgress as $item): ?>
            <div class="bnc-progress-soon">
              <i class="bi bi-clock-history"></i>
              <div>
                <strong><?= e($item['service_name']) ?></strong>
                <span>Disponible desde <?= e(fmt_dt_short($item['available_at'])) ?></span>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if ($recentProgress): ?>
            <hr>
            <div class="small fw-bold mb-2">Avances registrados</div>
            <?php foreach ($recentProgress as $item): ?>
              <div class="bnc-progress-done">
                <span><?= e(TreatmentProgressService::LEVELS[$item['progress_level']] ?? $item['progress_level']) ?></span>
                <small><?= e($item['service_name']) ?> · <?= e(fmt_dt_short($item['registered_at'])) ?></small>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

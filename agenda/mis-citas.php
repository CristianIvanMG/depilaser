<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();
Auth::requireVerifiedEmail();
$user = Auth::user();
ServiceCatalogService::ensureSchema();

global $CONFIG;
$cancelMinHours = (int) $CONFIG['business']['cancel_min_hours'];

// Cancelar cita (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $apptId = (int) ($_POST['appointment_id'] ?? 0);

    $a = Database::one(
        "SELECT a.*, s.slug AS status_slug FROM appointments a
         JOIN appointment_statuses s ON s.id = a.status_id
         WHERE a.id = ? AND a.user_id = ? LIMIT 1",
        [$apptId, $user['id']]
    );

    if (!$a) {
        flash('danger', 'Cita no encontrada.');
    } elseif (in_array($a['status_slug'], ['cancelada', 'atendida', 'no_asistio'], true)) {
        flash('warning', 'Esta cita ya no se puede cancelar.');
    } elseif (strtotime($a['start_at']) - time() < $cancelMinHours * 3600) {
        flash('warning', "Solo puedes cancelar citas con al menos {$cancelMinHours} h de anticipación. Llama al WhatsApp si necesitas ayuda.");
    } else {
        $cancStatusId = (int) Database::one("SELECT id FROM appointment_statuses WHERE slug = 'cancelada'")['id'];
        Database::exec(
            'UPDATE appointments SET status_id = ?, cancelled_at = NOW(), cancelled_by_user_id = ?, cancel_reason = ? WHERE id = ?',
            [$cancStatusId, $user['id'], $_POST['reason'] ?? null, $apptId]
        );
        Auth::audit('appointment_cancel', 'appointment', $apptId);
        flash('success', 'Cita cancelada. ¡Te esperamos pronto!');
    }
    redirect('mis-citas.php');
}

// Listas
$upcoming = Database::all(
    "SELECT a.*, s.name AS service_name, s.duration_min, " . ServiceCatalogService::priceSql('s') . " AS price_mxn, COALESCE(s.item_type, 'service') AS item_type,
            b.name AS branch_name, b.address AS branch_address, b.whatsapp,
            st.slug AS status_slug, st.name AS status_name, st.color_hex
     FROM appointments a
     JOIN services s ON s.id = a.service_id
     JOIN branches b ON b.id = a.branch_id
     JOIN appointment_statuses st ON st.id = a.status_id
     WHERE a.user_id = ? AND a.start_at >= NOW()
     ORDER BY a.start_at ASC",
    [$user['id']]
);

$past = Database::all(
    "SELECT a.*, s.name AS service_name, s.duration_min, COALESCE(s.item_type, 'service') AS item_type,
            b.name AS branch_name,
            st.slug AS status_slug, st.name AS status_name
     FROM appointments a
     JOIN services s ON s.id = a.service_id
     JOIN branches b ON b.id = a.branch_id
     JOIN appointment_statuses st ON st.id = a.status_id
     WHERE a.user_id = ? AND a.start_at < NOW()
     ORDER BY a.start_at DESC
     LIMIT 30",
    [$user['id']]
);

$pageTitle = 'Mis citas';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container py-4 py-md-5">

  <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
    <div>
      <h1 class="h3 fw-bold mb-1">Mis citas</h1>
      <p class="text-muted mb-0">Gestiona tus reservas y revisa tu historial.</p>
    </div>
    <a href="<?= url('agendar.php') ?>" class="btn btn-bnc-primary ms-md-auto">
      <i class="bi bi-calendar-plus"></i> Nueva cita
    </a>
  </div>

  <!-- PRÓXIMAS -->
  <div class="bnc-card mb-4">
    <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Próximas <span class="badge bg-secondary ms-1"><?= count($upcoming) ?></span></h2></div>
    <div class="bnc-card-body">
      <?php if (!$upcoming): ?>
        <p class="text-muted small mb-0">No tienes citas próximas. <a href="<?= url('agendar.php') ?>" style="color:var(--bnc-pink)">Agenda una</a>.</p>
      <?php else: foreach ($upcoming as $a): $ts = strtotime($a['start_at']); $canCancel = ($ts - time() >= $cancelMinHours * 3600) && !in_array($a['status_slug'], ['cancelada','atendida','no_asistio'], true); ?>
        <div class="bnc-appt" id="cita-<?= (int) $a['id'] ?>">
          <div class="bnc-appt-date">
            <div class="day"><?= (int) date('d', $ts) ?></div>
            <div class="mon"><?= ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][(int) date('n', $ts) - 1] ?></div>
            <div class="time"><?= date('H:i', $ts) ?></div>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
              <strong><?= e($a['service_name']) ?></strong>
              <span class="badge bg-light text-dark border"><?= e(ServiceCatalogService::typeLabel($a['item_type'] ?? 'service')) ?></span>
              <span class="bnc-status <?= e($a['status_slug']) ?>"><?= e($a['status_name']) ?></span>
            </div>
            <div class="small text-muted"><i class="bi bi-geo-alt"></i> <?= e($a['branch_name']) ?> · <?= (int) $a['duration_min'] ?> min · <?= fmt_price((float) $a['price_mxn']) ?></div>
            <div class="small text-muted">Código: <code><?= e($a['code']) ?></code></div>
          </div>
          <div class="d-flex flex-column gap-1 align-self-center">
            <?php if ($canCancel): ?>
              <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal-<?= (int) $a['id'] ?>">Cancelar</button>
            <?php endif; ?>
            <a class="btn btn-sm btn-success" href="https://wa.me/<?= e($a['whatsapp']) ?>?text=Hola,%20tengo%20cita%20<?= e($a['code']) ?>"><i class="bi bi-whatsapp"></i> Reagendar</a>
          </div>
        </div>

        <?php if ($canCancel): ?>
          <!-- Modal cancelación -->
          <div class="modal fade" id="cancelModal-<?= (int) $a['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content" style="border-radius:16px">
                <form method="POST">
                  <?= Csrf::input() ?>
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="appointment_id" value="<?= (int) $a['id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Cancelar cita</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p>¿Confirmas la cancelación de tu cita del <strong><?= e(fmt_dt($a['start_at'])) ?></strong>?</p>
                    <label class="bnc-label">Motivo (opcional)</label>
                    <textarea class="form-control" name="reason" rows="2" maxlength="255"></textarea>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">No, mantener</button>
                    <button type="submit" class="btn btn-danger">Sí, cancelar</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- PASADAS -->
  <div class="bnc-card">
    <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Historial <span class="badge bg-secondary ms-1"><?= count($past) ?></span></h2></div>
    <div class="bnc-card-body">
      <?php if (!$past): ?>
        <p class="text-muted small mb-0">Aún no tienes citas pasadas.</p>
      <?php else: foreach ($past as $a): $ts = strtotime($a['start_at']); ?>
        <div class="bnc-appt">
          <div class="bnc-appt-date" style="background:#f0f0f0; color:#888">
            <div class="day"><?= (int) date('d', $ts) ?></div>
            <div class="mon"><?= ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][(int) date('n', $ts) - 1] ?></div>
            <div class="time"><?= date('H:i', $ts) ?></div>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-1">
              <strong><?= e($a['service_name']) ?></strong>
              <span class="badge bg-light text-dark border"><?= e(ServiceCatalogService::typeLabel($a['item_type'] ?? 'service')) ?></span>
              <span class="bnc-status <?= e($a['status_slug']) ?>"><?= e($a['status_name']) ?></span>
            </div>
            <div class="small text-muted"><i class="bi bi-geo-alt"></i> <?= e($a['branch_name']) ?> · <?= (int) $a['duration_min'] ?> min</div>
            <div class="small text-muted">Código: <code><?= e($a['code']) ?></code></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

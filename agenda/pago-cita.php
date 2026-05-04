<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();
Auth::requireVerifiedEmail();

PaymentService::ensureSchema();
PaymentService::expirePendingPayments();

$user = Auth::user();
$appointmentId = (int) ($_GET['appointment_id'] ?? 0);
$result = (string) ($_GET['result'] ?? '');

$appointment = Database::one(
    "SELECT a.*, st.slug AS status_slug, st.name AS status_name,
            s.name AS service_name, s.price_mxn,
            b.name AS branch_name
     FROM appointments a
     JOIN appointment_statuses st ON st.id = a.status_id
     JOIN services s ON s.id = a.service_id
     JOIN branches b ON b.id = a.branch_id
     WHERE a.id = ? AND a.user_id = ? LIMIT 1",
    [$appointmentId, $user['id']]
);

if (!$appointment) {
    flash('danger', 'No encontramos esa cita en tu cuenta.');
    redirect('mis-citas.php');
}

if ($result === 'failure' && $appointment['payment_status'] !== 'paid') {
    PaymentService::markReturnFailure($appointmentId);
    $appointment['payment_status'] = 'failed';
    $appointment['status_slug'] = 'cancelada';
    $appointment['status_name'] = 'Cancelada';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $checkout = PaymentService::createMercadoPagoCheckout($appointmentId);
    if ($checkout['ok'] && !empty($checkout['redirect_url'])) {
        redirect($checkout['redirect_url']);
    }
    flash('danger', $checkout['error'] ?? 'No fue posible abrir el pago.');
    redirect('pago-cita.php?appointment_id=' . $appointmentId);
}

$payment = PaymentService::paymentForAppointment($appointmentId);
$pageTitle = 'Pago de cita';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<section class="container py-4 py-md-5">
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <div class="bnc-card">
        <div class="bnc-card-header">
          <h1 class="h5 fw-bold mb-0">Pago seguro de tu cita</h1>
        </div>
        <div class="bnc-card-body">
          <div class="bnc-payment-state mb-4 <?= e($appointment['payment_status']) ?>">
            <i class="bi <?= $appointment['payment_status'] === 'paid' ? 'bi-check-circle' : 'bi-shield-lock' ?>"></i>
            <div>
              <strong><?= e(PaymentService::paymentLabel($appointment['payment_status'])) ?></strong>
              <span><?= $appointment['payment_status'] === 'paid' ? 'Tu cita quedó confirmada.' : 'El pago se procesa en Mercado Pago. No guardamos datos de tarjeta.' ?></span>
            </div>
          </div>

          <div class="mb-3"><small class="text-muted text-uppercase">Servicio</small><br><strong><?= e($appointment['service_name']) ?></strong></div>
          <div class="mb-3"><small class="text-muted text-uppercase">Sucursal</small><br><strong><?= e($appointment['branch_name']) ?></strong></div>
          <div class="mb-3"><small class="text-muted text-uppercase">Fecha y hora</small><br><strong><?= e(fmt_dt($appointment['start_at'])) ?></strong></div>
          <div class="mb-4"><small class="text-muted text-uppercase">Monto a pagar</small><br><strong class="h4" style="color:var(--bnc-pink)"><?= fmt_price((float) $appointment['payment_amount_mxn']) ?></strong></div>

          <?php if ($appointment['payment_status'] === 'paid'): ?>
            <a href="<?= url('mis-citas.php') ?>" class="btn btn-bnc-primary w-100">Ver mis citas</a>
          <?php elseif (in_array($appointment['payment_status'], ['failed','expired','cancelled'], true) || $appointment['status_slug'] === 'cancelada'): ?>
            <div class="alert alert-warning">Este horario ya fue liberado. Puedes elegir una nueva fecha para agendar.</div>
            <a href="<?= url('agendar.php') ?>" class="btn btn-bnc-primary w-100">Elegir nuevo horario</a>
          <?php else: ?>
            <form method="POST">
              <?= Csrf::input() ?>
              <input type="hidden" name="action" value="pay">
              <button class="btn btn-bnc-primary w-100 py-2" type="submit">
                <i class="bi bi-lock"></i> Pagar con Mercado Pago
              </button>
            </form>
            <?php if (!empty($appointment['payment_expires_at'])): ?>
              <p class="small text-muted text-center mt-3 mb-0">Tu horario se conserva hasta <?= e(date('H:i', strtotime($appointment['payment_expires_at']))) ?>.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

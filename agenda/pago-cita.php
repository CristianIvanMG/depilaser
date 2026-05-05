<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();
Auth::requireVerifiedEmail();

PaymentService::ensureSchema();

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

$returnWarning = '';
$returnSync = null;
if ($appointment['payment_status'] !== 'paid' && in_array($result, ['success', 'pending'], true)) {
    $returnSync = PaymentService::syncMercadoPagoReturn($appointmentId, $_GET);
    if (!empty($returnSync['paid'])) {
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
    } elseif (empty($returnSync['pending'])) {
        error_log('[pago-cita return sync] ' . ($returnSync['error'] ?? 'No fue posible sincronizar Mercado Pago.'));
    }
}
if ($result === 'failure' && $appointment['payment_status'] !== 'paid') {
    $returnWarning = 'Mercado Pago no pudo completar el intento de pago. Tu cita sigue pendiente mientras el horario esté reservado.';
}

if ($result === 'success' && $appointment['payment_status'] !== 'paid' && $returnWarning === '') {
    $returnWarning = 'Mercado Pago recibio el pago, pero la confirmacion final todavia no llega. Estamos validandolo; si ya fue aprobado, tu cita se actualizara en unos momentos.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $checkout = PaymentService::createMercadoPagoCheckout($appointmentId);
    if ($checkout['ok'] && !empty($checkout['redirect_url'])) {
        redirect($checkout['redirect_url']);
    }
    flash('warning', 'No pudimos abrir Mercado Pago en este momento. Tu cita sigue pendiente mientras el horario esté reservado.');
    redirect('pago-cita.php?appointment_id=' . $appointmentId);
}

$payment = PaymentService::paymentForAppointment($appointmentId);
$paymentStatus = (string) ($appointment['payment_status'] ?? 'not_required');
$mercadoPagoReady = PaymentService::mercadoPagoConfigured($appointmentId);
$isReleased = in_array($paymentStatus, ['failed', 'expired', 'cancelled'], true) || $appointment['status_slug'] === 'cancelada';
$statusCopy = match ($paymentStatus) {
    'paid' => [
        'icon' => 'bi-check-circle',
        'title' => 'Pago recibido',
        'body' => 'Tu cita quedó confirmada. Te esperamos en BellaNick.',
    ],
    'expired' => [
        'icon' => 'bi-clock-history',
        'title' => 'Tiempo de pago agotado',
        'body' => 'La reserva temporal terminó porque el pago no se completó dentro del tiempo disponible.',
    ],
    'failed' => [
        'icon' => 'bi-exclamation-circle',
        'title' => 'Pago no completado',
        'body' => 'Mercado Pago no confirmó el cobro. Para proteger la agenda, la reserva temporal se canceló.',
    ],
    'cancelled' => [
        'icon' => 'bi-x-circle',
        'title' => 'Pago cancelado',
        'body' => 'La reserva fue cancelada y el horario volvió a quedar disponible.',
    ],
    'pending' => $mercadoPagoReady
        ? [
            'icon' => 'bi-shield-lock',
            'title' => 'Pago pendiente',
            'body' => 'Completa el pago en Mercado Pago para confirmar tu cita. No guardamos datos de tarjeta.',
        ]
        : [
            'icon' => 'bi-exclamation-circle',
            'title' => 'Pago en línea no disponible',
            'body' => 'Tu cita requiere pago anticipado, pero la pasarela de Mercado Pago todavía no está activa.',
        ],
    default => [
        'icon' => 'bi-info-circle',
        'title' => 'Pago no requerido',
        'body' => 'Esta cita no requiere pago anticipado.',
    ],
};

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
          <div class="bnc-payment-state mb-4 <?= e($paymentStatus) ?>">
            <i class="bi <?= e($statusCopy['icon']) ?>"></i>
            <div>
              <strong><?= e($statusCopy['title']) ?></strong>
              <span><?= e($statusCopy['body']) ?></span>
            </div>
          </div>
          <?php if ($returnWarning): ?>
            <div class="alert alert-warning"><?= e($returnWarning) ?></div>
          <?php endif; ?>

          <?php if ($paymentStatus === 'paid'): ?>
            <div class="bnc-payment-policy mb-4">
              <i class="bi bi-heart"></i>
              <div>
                <strong>Tu horario ya esta apartado para ti</strong>
                <span>Si necesitas cambiar o cancelar, avisanos con anticipacion para poder liberar el espacio a otra clienta. Si no asistes o cancelas fuera de tiempo, el anticipo puede aplicarse como cargo por reserva.</span>
              </div>
            </div>
          <?php endif; ?>

          <div class="mb-3"><small class="text-muted text-uppercase">Servicio</small><br><strong><?= e($appointment['service_name']) ?></strong></div>
          <div class="mb-3"><small class="text-muted text-uppercase">Sucursal</small><br><strong><?= e($appointment['branch_name']) ?></strong></div>
          <div class="mb-3"><small class="text-muted text-uppercase">Fecha y hora</small><br><strong><?= e(fmt_dt($appointment['start_at'])) ?></strong></div>
          <div class="mb-4"><small class="text-muted text-uppercase">Monto a pagar</small><br><strong class="h4" style="color:var(--bnc-pink)"><?= fmt_price((float) $appointment['payment_amount_mxn']) ?></strong></div>

          <?php if ($paymentStatus === 'paid'): ?>
            <a href="<?= url('mis-citas.php') ?>" class="btn btn-bnc-primary w-100">Ver mis citas</a>
          <?php elseif ($isReleased): ?>
            <div class="alert alert-warning">
              Esta cita ya no está reservada. Si todavía quieres ese servicio, elige un nuevo horario disponible y una forma de pago válida.
            </div>
            <a href="<?= url('agendar.php') ?>" class="btn btn-bnc-primary w-100">Elegir horario y forma de pago</a>
          <?php else: ?>
            <?php if ($mercadoPagoReady): ?>
              <form method="POST">
                <?= Csrf::input() ?>
                <input type="hidden" name="action" value="pay">
                <button class="btn btn-bnc-primary w-100 py-2" type="submit">
                  <i class="bi bi-lock"></i> Pagar con Mercado Pago
                </button>
              </form>
            <?php else: ?>
              <div class="alert alert-warning">
                Por ahora no podemos procesar el pago en línea. Para continuar, elige un nuevo horario cuando esté habilitada una forma de pago o contacta a la clínica.
              </div>
              <a href="<?= url('agendar.php') ?>" class="btn btn-bnc-primary w-100">Elegir horario y forma de pago</a>
            <?php endif; ?>
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

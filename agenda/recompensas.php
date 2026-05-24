<?php
require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();
if (!Auth::isClient()) {
    redirect(Auth::isAdmin() ? 'admin/' : 'login.php');
}
if (!Auth::emailVerified()) {
    redirect('verificar-email.php');
}

$user = Auth::user();
RewardsService::ensureSchema();
$token = RewardsService::qrTokenForUser($user);
$progress = RewardsService::progressForClient((int) $user['id']);
$rewards = RewardsService::rewardsForClient((int) $user['id'], 12);
$visits = RewardsService::attendancesForClient((int) $user['id'], 12);

$pageTitle = 'Mis recompensas';
require __DIR__ . '/includes/layouts/header_client.php';
?>

<style>
  .bnc-wallet-hero {
    background: linear-gradient(145deg, #17051d 0%, #7e1c5a 52%, #de3c94 100%);
    border-radius: 28px;
    color: #fff;
    overflow: hidden;
    padding: clamp(1.3rem, 4vw, 2.4rem);
    position: relative;
  }
  .bnc-wallet-hero::after {
    background: radial-gradient(circle, rgba(255,255,255,.22), transparent 65%);
    content: "";
    height: 260px;
    position: absolute;
    right: -90px;
    top: -80px;
    width: 260px;
  }
  .bnc-qr-card {
    background: rgba(255,255,255,.96);
    border-radius: 24px;
    box-shadow: 0 28px 80px rgba(12, 5, 18, .18);
    color: #15051a;
    padding: 1.2rem;
    text-align: center;
  }
  .bnc-qr-box {
    align-items: center;
    background: #fff;
    border: 1px solid #f2d6e5;
    border-radius: 20px;
    display: flex;
    justify-content: center;
    margin: 0 auto 1rem;
    min-height: 260px;
    width: min(100%, 280px);
  }
  .bnc-reward-ring {
    background: conic-gradient(#16a34a <?= (int) $progress['percent'] ?>%, rgba(255,255,255,.2) 0);
    border-radius: 50%;
    display: grid;
    height: 132px;
    place-items: center;
    width: 132px;
  }
  .bnc-reward-ring > div {
    background: #fff;
    border-radius: 50%;
    color: #15051a;
    display: grid;
    height: 104px;
    place-items: center;
    text-align: center;
    width: 104px;
  }
  .bnc-reward-card {
    background: #fff;
    border: 1px solid #f2d6e5;
    border-radius: 18px;
    padding: 1rem;
  }
  .bnc-reward-card.pending {
    border-color: #bbf7d0;
    box-shadow: 0 16px 40px rgba(22, 163, 74, .1);
  }
</style>

<section class="container py-4 py-md-5">
  <div class="bnc-wallet-hero mb-4">
    <div class="row g-4 align-items-center position-relative">
      <div class="col-lg-7">
        <span class="text-uppercase small fw-bold opacity-75">BellaNick Rewards</span>
        <h1 class="fw-bold mt-2 mb-3">Tu QR y recompensas</h1>
        <p class="opacity-75 mb-4">Presenta tu QR en recepcion cada vez que asistas. Tus visitas se acumulan automaticamente para promociones de cliente frecuente.</p>
        <div class="d-flex flex-wrap gap-3 align-items-center">
          <div class="bnc-reward-ring">
            <div>
              <strong class="fs-4"><?= (int) $progress['current'] ?>/<?= (int) $progress['required'] ?></strong>
              <small>visitas</small>
            </div>
          </div>
          <div>
            <h2 class="h5 fw-bold mb-1"><?= (int) $progress['remaining'] ?> visita(s) para tu siguiente recompensa</h2>
            <div class="small opacity-75"><?= e($progress['config']['description'] ?? 'Promocion de cliente frecuente') ?></div>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="bnc-qr-card">
          <div id="clientQr" class="bnc-qr-box" data-token="<?= e($token) ?>">
            <div class="text-muted small">Generando QR...</div>
          </div>
          <strong><?= e($user['name']) ?></strong>
          <div class="small text-muted">QR dinamico firmado, valido por 12 horas.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="bnc-card h-100">
        <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Recompensas disponibles</h2></div>
        <div class="bnc-card-body d-grid gap-3">
          <?php if (!$rewards): ?>
            <div class="bnc-empty-state">
              <i class="bi bi-gift"></i>
              <strong>Aun no tienes recompensas</strong>
              <span>Sigue asistiendo y escaneando tu QR para desbloquear beneficios.</span>
            </div>
          <?php else: foreach ($rewards as $reward): ?>
            <div class="bnc-reward-card <?= $reward['status'] === 'pendiente' ? 'pending' : '' ?>">
              <div class="d-flex gap-3 align-items-start">
                <div class="bnc-stat-icon"><i class="bi bi-gift-fill"></i></div>
                <div class="flex-grow-1">
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                    <strong><?= e($reward['type']) ?></strong>
                    <span class="badge <?= $reward['status'] === 'pendiente' ? 'bg-success' : 'bg-secondary' ?>"><?= e($reward['status']) ?></span>
                  </div>
                  <p class="mb-1 text-muted small"><?= e($reward['description']) ?></p>
                  <small class="text-muted">
                    Generada <?= e(fmt_dt_short($reward['generated_at'])) ?>
                    <?= $reward['expires_at'] ? ' · vence ' . e(fmt_dt_short($reward['expires_at'])) : '' ?>
                  </small>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="bnc-card h-100">
        <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Historial de visitas QR</h2></div>
        <div class="bnc-card-body">
          <?php if (!$visits): ?>
            <p class="text-muted small mb-0">Todavia no hay visitas registradas con QR.</p>
          <?php else: foreach ($visits as $visit): ?>
            <div class="bnc-mini-appt">
              <div class="bnc-mini-date"><i class="bi bi-qr-code-scan"></i></div>
              <div class="flex-grow-1">
                <strong><?= e(fmt_dt_short($visit['scanned_at'])) ?></strong>
                <div class="small text-muted"><?= e($visit['branch_name'] ?: 'BellaNick') ?> · <?= e($visit['admin_name']) ?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const box = document.getElementById('clientQr');
    if (!box || !window.QRCode) return;
    const token = box.dataset.token || '';
    box.innerHTML = '';
    new QRCode(box, {
      text: token,
      width: 236,
      height: 236,
      colorDark: '#15051a',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  });
</script>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

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
  .bnc-scan-card {
    background: rgba(255,255,255,.96);
    border-radius: 24px;
    box-shadow: 0 28px 80px rgba(12, 5, 18, .18);
    color: #15051a;
    padding: 1.2rem;
  }
  .bnc-scan-mode {
    background: #fff;
    border: 1px solid #f2d6e5;
    border-radius: 18px;
    display: flex;
    gap: .75rem;
    padding: .85rem;
  }
  .bnc-scan-mode i {
    align-items: center;
    background: #fff1f7;
    border-radius: 14px;
    color: #de3c94;
    display: inline-flex;
    flex: 0 0 42px;
    height: 42px;
    justify-content: center;
    width: 42px;
  }
  .bnc-branch-reader {
    align-items: center;
    background: #fff;
    border: 1px solid #f2d6e5;
    border-radius: 20px;
    display: grid;
    min-height: 260px;
    overflow: hidden;
    place-items: center;
    text-align: center;
  }
  .bnc-branch-reader video,
  .bnc-branch-reader canvas {
    border-radius: 20px;
  }
  .bnc-step-list {
    counter-reset: reward-step;
    display: grid;
    gap: .65rem;
    margin: 0;
    padding: 0;
  }
  .bnc-step-list li {
    align-items: center;
    display: flex;
    gap: .6rem;
    list-style: none;
  }
  .bnc-step-list li::before {
    align-items: center;
    background: #de3c94;
    border-radius: 999px;
    color: #fff;
    content: counter(reward-step);
    counter-increment: reward-step;
    display: inline-flex;
    flex: 0 0 28px;
    font-size: .85rem;
    font-weight: 700;
    height: 28px;
    justify-content: center;
    width: 28px;
  }
  .bnc-scan-result {
    border-radius: 16px;
    display: none;
    padding: .85rem 1rem;
  }
  .bnc-scan-result.show {
    display: block;
  }
  .bnc-scan-result.ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
  }
  .bnc-scan-result.warn {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
  }
  .bnc-scan-result.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
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
  @media (max-width: 575.98px) {
    .bnc-wallet-hero {
      border-radius: 0 0 26px 26px;
      margin-left: calc(var(--bs-gutter-x) * -.5);
      margin-right: calc(var(--bs-gutter-x) * -.5);
    }
    .bnc-reward-ring {
      height: 112px;
      width: 112px;
    }
    .bnc-reward-ring > div {
      height: 88px;
      width: 88px;
    }
    .bnc-scan-card {
      border-radius: 20px;
      padding: 1rem;
    }
    .bnc-branch-reader {
      min-height: 230px;
    }
  }
</style>

<section class="container py-4 py-md-5">
  <div class="bnc-wallet-hero mb-4">
    <div class="row g-4 align-items-center position-relative">
      <div class="col-lg-7">
        <span class="text-uppercase small fw-bold opacity-75">BellaNick Rewards</span>
        <h1 class="fw-bold mt-2 mb-3">Escanea en recepcion y suma visitas</h1>
        <p class="opacity-75 mb-4">Este es el camino principal: tu escaneas el QR impreso de la sucursal. Tu visita se suma sola y tu recompensa aparece cuando completes la meta.</p>
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
        <div class="bnc-scan-card">
          <h2 class="h5 fw-bold mb-3">Registrar mi visita</h2>
          <div class="bnc-scan-mode mb-3">
            <i class="bi bi-phone"></i>
            <div>
              <strong>Usa tu celular</strong>
              <div class="small text-muted">Abre esta pantalla en recepcion y escanea el QR de la sucursal.</div>
            </div>
          </div>
          <ol class="bnc-step-list small mb-3">
            <li>Busca el QR impreso en recepcion.</li>
            <li>Toca escanear y permite usar la camara.</li>
            <li>Apunta al QR. Listo, tu visita se guarda sola.</li>
          </ol>
          <div id="branchQrReader" class="bnc-branch-reader mb-3">
            <div class="p-3 text-muted small">
              <i class="bi bi-qr-code-scan d-block fs-1 mb-2"></i>
              Toca el boton para abrir la camara.
            </div>
          </div>
          <div id="branchScanResult" class="bnc-scan-result mb-3"></div>
          <button type="button" id="startBranchScanBtn" class="btn btn-bnc-primary w-100">
            <i class="bi bi-qr-code-scan"></i> Escanear QR de sucursal
          </button>
          <a href="<?= url('perfil.php') ?>" class="btn btn-link w-100 mt-2 text-decoration-none">
            Ver mi QR personal
          </a>
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
              <span>Sigue asistiendo y escaneando el QR de recepcion para desbloquear beneficios.</span>
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
                <div class="small text-muted"><?= e($visit['branch_name'] ?: 'BellaNick') ?> - <?= e($visit['admin_name']) ?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>window.Html5Qrcode || document.write('<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"><\/script>');</script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const startBtn = document.getElementById('startBranchScanBtn');
    const resultBox = document.getElementById('branchScanResult');
    const readerEl = document.getElementById('branchQrReader');
    let scanner = null;
    let busy = false;

    const showResult = (type, message) => {
      if (!resultBox) return;
      resultBox.className = `bnc-scan-result show ${type}`;
      resultBox.textContent = message;
    };

    const stopScanner = async () => {
      if (scanner) {
        try {
          await scanner.stop();
        } catch (error) {
          // La libreria lanza error si ya estaba detenido.
        }
      }
    };

    const submitScan = async (token) => {
      if (busy) return;
      busy = true;
      if (startBtn) startBtn.disabled = true;
      await stopScanner();
      showResult('warn', 'Validando tu visita...');
      try {
        const response = await fetch('<?= url('api/cliente-checkin-sucursal.json.php') ?>', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            token,
            '<?= Csrf::FIELD ?>': '<?= e(Csrf::token()) ?>'
          })
        });
        const data = await response.json();
        if (!data.ok) {
          showResult(response.status === 409 ? 'warn' : 'error', data.error || 'No se pudo registrar la visita.');
          return;
        }
        const branch = data.branch?.name ? ` en ${data.branch.name}` : '';
        const reward = data.reward ? ' Nueva recompensa generada.' : '';
        showResult('ok', `Visita registrada${branch}. Progreso: ${data.progress.current}/${data.progress.required}.${reward}`);
        window.setTimeout(() => window.location.reload(), 1800);
      } catch (error) {
        showResult('error', 'No se pudo conectar con el servidor. Intenta de nuevo.');
      } finally {
        busy = false;
        if (startBtn) startBtn.disabled = false;
      }
    };

    if (!startBtn || !readerEl || !window.Html5Qrcode) {
      showResult('error', 'El escaner no pudo cargar. Actualiza la pagina e intenta de nuevo.');
      return;
    }

    startBtn.addEventListener('click', async () => {
      showResult('warn', 'Apunta tu camara al QR de recepcion.');
      scanner = scanner || new Html5Qrcode('branchQrReader');
      try {
        await scanner.start(
          {facingMode: 'environment'},
          {fps: 10, qrbox: {width: 230, height: 230}},
          (decodedText) => submitScan(decodedText)
        );
      } catch (error) {
        showResult('error', 'No se pudo abrir la camara. Revisa permisos del navegador.');
      }
    });
  });
</script>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>

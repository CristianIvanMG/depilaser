<?php
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireLogin();
if (!Auth::isAdmin() && !Auth::isProfessional()) {
    http_response_code(403);
    die('Acceso restringido.');
}

RewardsService::ensureSchema();
$branches = Database::all('SELECT id, name FROM branches WHERE active = 1 ORDER BY display_order, name');

$pageTitle = 'Escanear QR';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<style>
  .bnc-scan-shell {
    max-width: 980px;
    margin: 0 auto;
  }
  .bnc-scan-hero {
    background: linear-gradient(135deg, #18051f 0%, #8a1f63 58%, #de3c94 100%);
    border-radius: 22px;
    color: #fff;
    overflow: hidden;
    padding: clamp(1.25rem, 4vw, 2rem);
    position: relative;
  }
  .bnc-scan-hero::after {
    background: radial-gradient(circle, rgba(255,255,255,.25), transparent 62%);
    content: "";
    height: 220px;
    position: absolute;
    right: -80px;
    top: -70px;
    width: 220px;
  }
  .bnc-scanner-card {
    background: #fff;
    border: 1px solid #f2d6e5;
    border-radius: 20px;
    box-shadow: 0 24px 60px rgba(58, 12, 43, .12);
    overflow: hidden;
  }
  #qrReader {
    background: #120717;
    min-height: 330px;
  }
  #qrReader video {
    object-fit: cover;
  }
  .bnc-scan-result {
    border-radius: 18px;
    padding: 1rem;
  }
  .bnc-scan-result.success {
    background: #ecfdf3;
    border: 1px solid #bbf7d0;
    color: #14532d;
  }
  .bnc-scan-result.error {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #881337;
  }
  .bnc-progress-soft {
    background: rgba(22, 163, 74, .14);
    border-radius: 999px;
    height: 10px;
    overflow: hidden;
  }
  .bnc-progress-soft span {
    background: #16a34a;
    display: block;
    height: 100%;
  }
</style>

<div class="bnc-scan-shell">
  <div class="bnc-scan-hero mb-4">
    <div class="position-relative">
      <span class="text-uppercase small fw-bold opacity-75">Fidelizacion BellaNick</span>
      <h2 class="fw-bold mt-2 mb-2">Escaneo de asistencia por QR</h2>
      <p class="mb-0 opacity-75">Abre esta pantalla desde el celular del administrador, apunta al QR del cliente y registra la visita en segundos.</p>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="bnc-scanner-card">
        <div class="p-3 border-bottom d-flex flex-wrap gap-2 align-items-center">
          <div class="me-auto">
            <strong>Camara</strong>
            <div class="small text-muted">El escaneo se detiene unos segundos cuando se registra una asistencia.</div>
          </div>
          <button type="button" class="btn btn-bnc-outline btn-sm" id="restartScanBtn"><i class="bi bi-camera-video"></i> Reiniciar</button>
        </div>
        <div id="qrReader"></div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="bnc-card mb-3">
        <div class="bnc-card-header"><h3 class="h6 fw-bold mb-0">Sucursal</h3></div>
        <div class="bnc-card-body">
          <select id="branchId" class="form-select">
            <option value="">Sin sucursal especifica</option>
            <?php foreach ($branches as $branch): ?>
              <option value="<?= (int) $branch['id'] ?>"><?= e($branch['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="scanResult" class="bnc-scan-result border bg-white">
        <div class="text-muted small">Esperando escaneo...</div>
        <strong>Acerca el QR del cliente a la camara.</strong>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const resultBox = document.getElementById('scanResult');
    const branchId = document.getElementById('branchId');
    const restartBtn = document.getElementById('restartScanBtn');
    const scanner = new Html5Qrcode('qrReader');
    let busy = false;

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    }

    function renderResult(type, html) {
      resultBox.className = 'bnc-scan-result ' + type;
      resultBox.innerHTML = html;
    }

    async function startScanner() {
      try {
        await scanner.start(
          { facingMode: 'environment' },
          { fps: 10, qrbox: { width: 260, height: 260 }, aspectRatio: 1 },
          onScanSuccess
        );
      } catch (error) {
        renderResult('error', `<strong>No se pudo abrir la camara.</strong><div class="small mt-1">${escapeHtml(error?.message || error)}</div>`);
      }
    }

    async function pauseScanner() {
      try {
        if (scanner.getState && scanner.getState() === 2) await scanner.pause(true);
      } catch (error) {}
    }

    async function resumeScanner() {
      try {
        if (scanner.getState && scanner.getState() === 3) await scanner.resume();
      } catch (error) {}
    }

    async function onScanSuccess(decodedText) {
      if (busy) return;
      busy = true;
      await pauseScanner();
      renderResult('border bg-white', '<div class="spinner-border spinner-border-sm me-2"></div> Validando QR...');
      try {
        const response = await fetch('<?= url('api/qr-scan.json.php') ?>', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ token: decodedText, branch_id: branchId.value, <?= json_encode(Csrf::FIELD) ?>: <?= json_encode(Csrf::token()) ?> })
        });
        const body = await response.json();
        if (!body.ok) {
          renderResult('error', `<strong>${escapeHtml(body.error || 'QR no valido.')}</strong>${body.last_scan ? `<div class="small mt-1">Ultimo escaneo: ${escapeHtml(body.last_scan)}</div>` : ''}`);
        } else {
          const p = body.progress || {};
          const reward = body.reward ? '<div class="alert alert-success small mt-3 mb-0"><i class="bi bi-gift-fill"></i> Recompensa generada automaticamente.</div>' : '';
          renderResult('success', `
            <div class="d-flex align-items-center gap-3">
              <div class="bnc-avatar" style="background:#16a34a">${escapeHtml((body.client?.name || 'C').slice(0,1))}</div>
              <div>
                <div class="small text-uppercase fw-bold opacity-75">Cliente reconocido</div>
                <strong>${escapeHtml(body.client?.name || 'Cliente')}</strong>
                <div class="small">${escapeHtml(body.client?.phone || body.client?.email || '')}</div>
              </div>
            </div>
            <div class="mt-3">
              <div class="d-flex justify-content-between small fw-bold mb-1"><span>Progreso</span><span>${p.current || 0}/${p.required || 0}</span></div>
              <div class="bnc-progress-soft"><span style="width:${Math.min(100, Number(p.percent || 0))}%"></span></div>
            </div>
            ${reward}
          `);
        }
      } catch (error) {
        renderResult('error', `<strong>No fue posible registrar la asistencia.</strong><div class="small mt-1">${escapeHtml(error?.message || error)}</div>`);
      } finally {
        window.setTimeout(async () => {
          busy = false;
          await resumeScanner();
        }, 1800);
      }
    }

    restartBtn.addEventListener('click', async () => {
      busy = false;
      await resumeScanner();
      renderResult('border bg-white', '<div class="text-muted small">Escaner activo.</div><strong>Acerca el QR del cliente.</strong>');
    });

    startScanner();
  });
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

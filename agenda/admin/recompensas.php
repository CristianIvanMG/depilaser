<?php
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireLogin();
if (!Auth::isAdmin()) {
    http_response_code(403);
    die('Acceso restringido.');
}

RewardsService::ensureSchema();
$admin = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $clientId = (int) ($_POST['client_id'] ?? 0);

    try {
        if ($action === 'save_config') {
            RewardsService::saveConfig($_POST);
            flash('success', 'Configuracion de recompensas actualizada.');
        } elseif ($action === 'reset_counter' && $clientId > 0) {
            RewardsService::resetCounter($clientId, (int) $admin['id'], (string) ($_POST['reason'] ?? ''));
            flash('success', 'Contador reiniciado correctamente.');
        } elseif ($action === 'adjust_counter' && $clientId > 0) {
            RewardsService::adjustCounter($clientId, (int) ($_POST['delta'] ?? 0), (int) $admin['id'], (string) ($_POST['reason'] ?? ''));
            flash('success', 'Contador ajustado correctamente.');
        } elseif ($action === 'force_reward' && $clientId > 0) {
            RewardsService::forceReward($clientId, (int) $admin['id']);
            flash('success', 'Recompensa generada manualmente.');
        } elseif ($action === 'delete_attendance') {
            RewardsService::deleteAttendance((int) ($_POST['attendance_id'] ?? 0));
            flash('success', 'Registro de asistencia eliminado.');
        } elseif ($action === 'reward_status') {
            RewardsService::updateRewardStatus((int) ($_POST['reward_id'] ?? 0), (string) ($_POST['status'] ?? ''));
            flash('success', 'Estado de recompensa actualizado.');
        }
    } catch (Throwable $e) {
        flash('danger', 'No fue posible completar la accion: ' . $e->getMessage());
    }
    redirect('admin/recompensas.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$config = RewardsService::activeConfig();
$clients = RewardsService::dashboardClients($q, 80, $config);
$recent = RewardsService::recentAttendances(60);
$recentRewards = RewardsService::recentRewards(40);
$pendingRewards = 0;
$todayScans = 0;
$totalScans = 0;
try {
    $pendingRewards = (int) (Database::one("SELECT COUNT(*) AS n FROM client_rewards WHERE status = 'pendiente'")['n'] ?? 0);
    $todayScans = (int) (Database::one("SELECT COUNT(*) AS n FROM attendance_logs WHERE scanned_at >= CURDATE() AND scanned_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)")['n'] ?? 0);
    $totalScans = (int) (Database::one("SELECT COUNT(*) AS n FROM attendance_logs")['n'] ?? 0);
} catch (Throwable $e) {
    error_log('[admin-recompensas-counts] ' . $e->getMessage());
    flash('warning', 'El modulo de recompensas esta cargando con datos parciales. Revisa que la migracion de recompensas este completa.');
}
$branchQrRows = [];
$activeBranches = Database::all('SELECT id, name FROM branches WHERE active = 1 ORDER BY display_order, name');
foreach ($activeBranches as $branch) {
    $branchQrRows[] = [
        'id' => (int) $branch['id'],
        'name' => (string) $branch['name'],
        'token' => RewardsService::qrTokenForBranch($branch),
    ];
}

$pageTitle = 'Recompensas';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<style>
  .bnc-reward-hero {
    background: linear-gradient(135deg, #fff 0%, #fff5fa 48%, #f7fffb 100%);
    border: 1px solid #f2d6e5;
    border-radius: 22px;
    box-shadow: 0 24px 70px rgba(58, 12, 43, .08);
    padding: clamp(1.25rem, 3vw, 2rem);
  }
  .bnc-reward-kpi {
    background: #fff;
    border: 1px solid #f2d6e5;
    border-radius: 18px;
    padding: 1rem;
  }
  .bnc-reward-kpi strong {
    color: #15051a;
    display: block;
    font-size: 1.7rem;
    line-height: 1;
  }
  .bnc-progress-track {
    background: #f3f4f6;
    border-radius: 999px;
    height: 9px;
    overflow: hidden;
  }
  .bnc-progress-track span {
    background: linear-gradient(90deg, #de3c94, #16a34a);
    display: block;
    height: 100%;
  }
  .bnc-action-grid {
    display: grid;
    gap: .4rem;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    min-width: min(100%, 280px);
  }
  .bnc-counter-adjust {
    display: grid;
    gap: .4rem;
    grid-column: 1 / -1;
    grid-template-columns: minmax(76px, 92px) minmax(110px, 1fr);
  }
  .bnc-counter-adjust input {
    min-width: 76px;
    text-align: center;
  }
  .bnc-reward-status-form {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    justify-content: flex-end;
  }
  .bnc-reward-status-form select {
    min-width: 132px;
    width: auto;
  }
  .bnc-branch-qr-card {
    border: 1px solid #f2d6e5;
    border-radius: 18px;
    padding: 1rem;
  }
  .bnc-branch-qr-box {
    align-items: center;
    background: #fff;
    border: 1px dashed #e8b6d2;
    border-radius: 16px;
    display: flex;
    justify-content: center;
    min-height: 170px;
    padding: .75rem;
  }
  @media (max-width: 992px) {
    .bnc-reward-status-form {
      justify-content: stretch;
    }
    .bnc-reward-status-form select,
    .bnc-reward-status-form .btn,
    .bnc-reward-status-form .bnc-reward-appointment {
      flex: 1 1 140px;
    }
  }
  @media (max-width: 768px) {
    .bnc-action-grid {
      grid-template-columns: 1fr;
    }
    .bnc-counter-adjust {
      grid-template-columns: 1fr;
    }
  }
  @media print {
    body * {
      visibility: hidden;
    }
    .bnc-print-qr-area,
    .bnc-print-qr-area * {
      visibility: visible;
    }
    .bnc-print-qr-area {
      left: 0;
      position: absolute;
      top: 0;
      width: 100%;
    }
    .bnc-no-print {
      display: none !important;
    }
  }
</style>

<div class="bnc-reward-hero mb-4">
  <div class="row g-3 align-items-center">
    <div class="col-lg-7">
      <span class="text-uppercase small fw-bold" style="color:var(--bnc-pink)">Sistema de fidelizacion</span>
      <h2 class="fw-bold mt-2 mb-2">Recompensas por asistencia real</h2>
      <p class="text-muted mb-0">Controla visitas QR, progreso de clientes y promociones frecuentes desde una vista administrativa.</p>
    </div>
    <div class="col-lg-5">
      <div class="row g-2">
        <div class="col-4"><div class="bnc-reward-kpi"><strong><?= number_format($todayScans) ?></strong><small>Hoy</small></div></div>
        <div class="col-4"><div class="bnc-reward-kpi"><strong><?= number_format($pendingRewards) ?></strong><small>Pendientes</small></div></div>
        <div class="col-4"><div class="bnc-reward-kpi"><strong><?= number_format($totalScans) ?></strong><small>Escaneos</small></div></div>
      </div>
    </div>

  </div>
</div>

<div class="row g-4">
  <div class="col-xl-8">
    <div class="bnc-card mb-4">
      <div class="bnc-card-header d-flex flex-wrap gap-2 align-items-center">
        <h3 class="h6 fw-bold mb-0 me-auto">Progreso de clientes</h3>
        <form class="d-flex gap-2" method="GET">
          <input name="q" class="form-control form-control-sm" value="<?= e($q) ?>" placeholder="Buscar cliente">
          <button class="btn btn-bnc-primary btn-sm"><i class="bi bi-search"></i></button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Cliente</th>
              <th>Progreso</th>
              <th>Recompensas</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$clients): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">No hay clientes para mostrar.</td></tr>
            <?php else: foreach ($clients as $client): $p = $client['progress']; ?>
              <tr>
                <td>
                  <strong><?= e($client['name']) ?></strong>
                  <div class="small text-muted"><?= e($client['phone'] ?: $client['email']) ?></div>
                </td>
                <td style="min-width:190px">
                  <div class="d-flex justify-content-between small fw-bold mb-1">
                    <span><?= (int) $p['current'] ?>/<?= (int) $p['required'] ?></span>
                    <span><?= (int) $p['remaining'] ?> faltan</span>
                  </div>
                  <div class="bnc-progress-track"><span style="width:<?= (int) $p['percent'] ?>%"></span></div>
                </td>
                <td>
                  <span class="badge bg-success"><?= (int) $client['pending_rewards'] ?> pendiente(s)</span>
                  <div class="small text-muted"><?= (int) $client['total_attendances'] ?> asistencia(s)</div>
                </td>
                <td class="text-end">
                  <div class="bnc-action-grid">
                    <form method="POST">
                      <?= Csrf::input() ?>
                      <input type="hidden" name="action" value="force_reward">
                      <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                      <button class="btn btn-success btn-sm w-100" onclick="return confirm('Generar recompensa manual para este cliente?')"><i class="bi bi-gift"></i> Forzar</button>
                    </form>
                    <form method="POST">
                      <?= Csrf::input() ?>
                      <input type="hidden" name="action" value="reset_counter">
                      <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                      <button class="btn btn-outline-secondary btn-sm w-100" onclick="return confirm('Reiniciar contador de este cliente?')"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
                    </form>
                    <form method="POST" class="bnc-counter-adjust">
                      <?= Csrf::input() ?>
                      <input type="hidden" name="action" value="adjust_counter">
                      <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                      <input type="number" name="delta" class="form-control form-control-sm" value="1">
                      <button class="btn btn-bnc-outline btn-sm flex-grow-1">Ajustar</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="bnc-card mb-4">
      <div class="bnc-card-header"><h3 class="h6 fw-bold mb-0">Recompensas generadas</h3></div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Cliente</th><th>Recompensa</th><th>Vigencia</th><th>Estado</th><th class="text-end">Accion</th></tr></thead>
          <tbody>
            <?php if (!$recentRewards): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">Aun no hay recompensas generadas.</td></tr>
            <?php else: foreach ($recentRewards as $reward): ?>
              <tr>
                <td><strong><?= e($reward['client_name']) ?></strong><div class="small text-muted"><?= e($reward['client_phone']) ?></div></td>
                <td><strong><?= e($reward['type']) ?></strong><div class="small text-muted"><?= e($reward['description']) ?></div></td>
                <td><?= $reward['expires_at'] ? e(fmt_dt_short($reward['expires_at'])) : '<span class="text-muted">Sin vencimiento</span>' ?></td>
                <td><span class="badge <?= $reward['status'] === 'pendiente' ? 'bg-success' : ($reward['status'] === 'usado' ? 'bg-secondary' : 'bg-danger') ?>"><?= e($reward['status']) ?></span></td>
                <td class="text-end">
                  <form method="POST" class="bnc-reward-status-form">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="action" value="reward_status">
                    <input type="hidden" name="reward_id" value="<?= (int) $reward['id'] ?>">
                    <?php if ($reward['status'] === 'pendiente'): ?>
                      <a class="btn btn-success btn-sm bnc-reward-appointment" href="<?= url('admin/cita-form.php?reward_id=' . (int) $reward['id']) ?>">
                        <i class="bi bi-calendar-plus"></i> Agendar
                      </a>
                    <?php endif; ?>
                    <select name="status" class="form-select form-select-sm">
                      <option value="pendiente" <?= $reward['status'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                      <option value="usado" <?= $reward['status'] === 'usado' ? 'selected' : '' ?>>Usado</option>
                      <option value="cancelado" <?= $reward['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                    </select>
                    <button class="btn btn-bnc-outline btn-sm">Guardar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="bnc-card">
      <div class="bnc-card-header"><h3 class="h6 fw-bold mb-0">Historial de escaneos</h3></div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Fecha</th><th>Cliente</th><th>Sucursal</th><th>Registro</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($recent as $scan): ?>
              <tr>
                <td><?= e(fmt_dt_short($scan['scanned_at'])) ?></td>
                <td><strong><?= e($scan['client_name']) ?></strong><div class="small text-muted"><?= e($scan['client_phone']) ?></div></td>
                <td><?= e($scan['branch_name'] ?: 'Sin sucursal') ?></td>
                <td><?= e($scan['admin_name']) ?></td>
                <td class="text-end">
                  <form method="POST">
                    <?= Csrf::input() ?>
                    <input type="hidden" name="action" value="delete_attendance">
                    <input type="hidden" name="attendance_id" value="<?= (int) $scan['id'] ?>">
                    <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Eliminar este registro de asistencia?')"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="bnc-card">
      <div class="bnc-card-header"><h3 class="h6 fw-bold mb-0">Regla activa</h3></div>
      <div class="bnc-card-body">
        <form method="POST" class="d-grid gap-3">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="save_config">
          <div>
            <label class="bnc-label">Nombre</label>
            <input name="name" class="form-control" value="<?= e($config['name'] ?? 'Cliente frecuente') ?>">
          </div>
          <div>
            <label class="bnc-label">Asistencias requeridas</label>
            <input type="number" min="1" name="attendances_required" class="form-control" value="<?= (int) ($config['attendances_required'] ?? 10) ?>">
          </div>
          <div>
            <label class="bnc-label">Tipo de promocion</label>
            <input name="promotion_type" class="form-control" value="<?= e($config['promotion_type'] ?? 'cliente_frecuente') ?>">
          </div>
          <div>
            <label class="bnc-label">Descripcion visible</label>
            <textarea name="description" class="form-control" rows="4"><?= e($config['description'] ?? '') ?></textarea>
          </div>
          <div>
            <label class="bnc-label">Vigencia en dias</label>
            <input type="number" min="0" name="validity_days" class="form-control" value="<?= (int) ($config['validity_days'] ?? 60) ?>">
          </div>
          <label class="form-check">
            <input type="checkbox" class="form-check-input" name="auto_reset" value="1" <?= !empty($config['auto_reset']) ? 'checked' : '' ?>>
            <span class="form-check-label">Reiniciar contador automaticamente al generar recompensa</span>
          </label>
          <button class="btn btn-bnc-primary"><i class="bi bi-sliders"></i> Guardar configuracion</button>
        </form>
      </div>
    </div>

    <div class="bnc-card mt-4 bnc-print-qr-area">
      <div class="bnc-card-header d-flex flex-wrap gap-2 align-items-center">
        <div class="me-auto">
          <h3 class="h6 fw-bold mb-0">QR de sucursales</h3>
          <div class="small text-muted">Imprime estos codigos y colocalos en recepcion.</div>
        </div>
        <button type="button" class="btn btn-bnc-outline btn-sm bnc-no-print" onclick="window.print()">
          <i class="bi bi-printer"></i> Imprimir
        </button>
      </div>
      <div class="bnc-card-body d-grid gap-3">
        <?php if (!$branchQrRows): ?>
          <p class="text-muted small mb-0">No hay sucursales activas para generar QR.</p>
        <?php else: foreach ($branchQrRows as $branchQr): ?>
          <div class="bnc-branch-qr-card">
            <div class="bnc-branch-qr-box mb-2" data-branch-qr="<?= e($branchQr['token']) ?>">
              <span class="text-muted small">Generando QR...</span>
            </div>
            <strong><?= e($branchQr['name']) ?></strong>
            <div class="small text-muted">El cliente lo escanea desde su perfil para sumar una visita presencial.</div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const boxes = Array.from(document.querySelectorAll('[data-branch-qr]'));
    if (!boxes.length) return;

    const renderQr = () => {
      if (!window.QRCode) return false;
      boxes.forEach((box) => {
        const token = box.dataset.branchQr || '';
        box.innerHTML = '';
        new QRCode(box, {
          text: token,
          width: 150,
          height: 150,
          colorDark: '#15051a',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      });
      return true;
    };

    const loadScript = (src, onload, onerror) => {
      const script = document.createElement('script');
      script.src = src;
      script.async = true;
      script.onload = onload;
      script.onerror = onerror;
      document.body.appendChild(script);
    };

    if (renderQr()) return;
    loadScript(
      'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js',
      renderQr,
      () => loadScript('https://unpkg.com/qrcodejs@1.0.0/qrcode.min.js', renderQr)
    );
  });
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

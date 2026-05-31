<?php
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireAdmin();
AppSettingsService::ensureSchema();
SmsService::ensureSchema();
RewardsService::ensureSchema();

$admin = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check($_POST[Csrf::FIELD] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_sms_settings') {
            AppSettingsService::saveSmsSettings($_POST, (int) $admin['id']);
            flash('success', 'Configuracion de SMS actualizada.');
        } elseif ($action === 'add_sms_purchase') {
            AppSettingsService::addSmsPurchase((int) ($_POST['purchase_quantity'] ?? 0), (int) $admin['id'], (string) ($_POST['purchase_note'] ?? ''));
            flash('success', 'Compra de SMS registrada y saldo actualizado.');
        }
    } catch (Throwable $e) {
        flash('danger', 'No fue posible guardar la configuracion: ' . $e->getMessage());
    }
    redirect('admin/configuraciones.php');
}

$sms = AppSettingsService::smsSettings();
$smsStats = AppSettingsService::smsStats();
$smsLogs = AppSettingsService::recentSmsInventoryLogs(18);
$smsApi = SmsService::configStatus();
$rewardConfig = RewardsService::activeConfig();

$totalPurchased = max(0, (int) $sms['total_purchased']);
$remaining = max(0, (int) $sms['remaining']);
$used = max(0, (int) $sms['used']);
$percentRemaining = $totalPurchased > 0 ? max(0, min(100, (int) round(($remaining / $totalPurchased) * 100))) : 0;
$percentUsed = $totalPurchased > 0 ? 100 - $percentRemaining : 0;

$pageTitle = 'Configuraciones';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<style>
  .bnc-config-hero {
    background: linear-gradient(135deg, #ffffff 0%, #fff4fa 52%, #f5fffb 100%);
    border: 1px solid #f1d4e3;
    border-radius: 22px;
    box-shadow: 0 24px 70px rgba(58, 12, 43, .08);
    padding: clamp(1.2rem, 3vw, 2rem);
  }
  .bnc-config-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
  .bnc-config-metric {
    background: #fff;
    border: 1px solid #f0d7e4;
    border-radius: 18px;
    padding: 1rem;
  }
  .bnc-config-metric span {
    color: #6b6071;
    display: block;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
  }
  .bnc-config-metric strong {
    color: #17051c;
    display: block;
    font-size: clamp(1.6rem, 3vw, 2.2rem);
    line-height: 1;
    margin-top: .35rem;
  }
  .bnc-sms-ring {
    --value: 0;
    align-items: center;
    aspect-ratio: 1 / 1;
    background: conic-gradient(#16a34a calc(var(--value) * 1%), #f2e7ee 0);
    border-radius: 50%;
    display: grid;
    justify-content: center;
    margin-inline: auto;
    max-width: 210px;
    padding: 14px;
    width: 100%;
  }
  .bnc-sms-ring-inner {
    align-items: center;
    background: #fff;
    border-radius: 50%;
    display: grid;
    height: 100%;
    justify-content: center;
    text-align: center;
    width: 100%;
  }
  .bnc-sms-ring-inner strong {
    color: #15051a;
    display: block;
    font-size: 2rem;
    line-height: 1;
  }
  .bnc-config-card {
    background: #fff;
    border: 1px solid #f0d7e4;
    border-radius: 18px;
    box-shadow: 0 18px 46px rgba(58, 12, 43, .06);
    overflow: hidden;
  }
  .bnc-config-card-header {
    align-items: center;
    background: #fff8fc;
    border-bottom: 1px solid #f0d7e4;
    display: flex;
    gap: .75rem;
    justify-content: space-between;
    padding: 1rem 1.15rem;
  }
  .bnc-config-card-body {
    padding: 1.15rem;
  }
  .bnc-config-switch {
    align-items: center;
    border: 1px solid #efd3e2;
    border-radius: 16px;
    display: flex;
    gap: .75rem;
    padding: .85rem;
  }
  .bnc-config-table {
    margin: 0;
  }
  .bnc-config-table td {
    vertical-align: middle;
  }
  .bnc-config-tabs {
    background: #fff;
    border: 1px solid #f0d7e4;
    border-radius: 18px;
    box-shadow: 0 16px 42px rgba(58, 12, 43, .06);
    display: grid;
    gap: .55rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-bottom: 1rem;
    padding: .55rem;
  }
  .bnc-config-tab {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 14px;
    color: #6b6071;
    display: flex;
    gap: .65rem;
    justify-content: center;
    min-height: 52px;
    padding: .7rem .9rem;
    text-align: left;
  }
  .bnc-config-tab i {
    font-size: 1.15rem;
  }
  .bnc-config-tab strong {
    color: inherit;
    display: block;
    font-size: .92rem;
    line-height: 1.1;
  }
  .bnc-config-tab small {
    display: block;
    font-size: .72rem;
    opacity: .76;
  }
  .bnc-config-tab.active {
    background: linear-gradient(135deg, #de3c94, #94246e);
    box-shadow: 0 12px 28px rgba(198, 61, 138, .26);
    color: #fff;
  }
  .bnc-config-empty {
    background: linear-gradient(135deg, #fff 0%, #fff8fc 100%);
    border: 1px dashed #e8b6d2;
    border-radius: 18px;
    padding: 1.25rem;
  }
  [data-config-section][hidden] {
    display: none !important;
  }
  @media (max-width: 1100px) {
    .bnc-config-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 640px) {
    .bnc-config-grid { grid-template-columns: 1fr; }
    .bnc-config-card-header { align-items: flex-start; flex-direction: column; }
    .bnc-config-tabs { grid-template-columns: 1fr; }
    .bnc-config-tab { justify-content: flex-start; }
  }
</style>

<div class="bnc-config-hero mb-4">
  <div class="d-flex flex-column flex-lg-row gap-4 align-items-lg-center">
    <div class="flex-grow-1">
      <div class="text-uppercase fw-bold small text-muted mb-2">Centro de control</div>
      <h2 class="h4 fw-bold mb-2">Configuraciones operativas</h2>
      <p class="text-muted mb-0">
        Administra saldos SMS, recordatorios automaticos y reglas clave sin tocar archivos tecnicos.
      </p>
    </div>
    <div class="bnc-config-grid flex-grow-1">
      <div class="bnc-config-metric">
        <span>SMS disponibles</span>
        <strong><?= number_format($remaining) ?></strong>
      </div>
      <div class="bnc-config-metric">
        <span>Comprados</span>
        <strong><?= number_format($totalPurchased) ?></strong>
      </div>
      <div class="bnc-config-metric">
        <span>Enviados hoy</span>
        <strong><?= number_format((int) $smsStats['sent_today']) ?></strong>
      </div>
      <div class="bnc-config-metric">
        <span>Correos respaldo hoy</span>
        <strong><?= number_format((int) $smsStats['fallback_today']) ?></strong>
      </div>
    </div>
  </div>
</div>

<div class="bnc-config-tabs" role="tablist" aria-label="Secciones de configuracion">
  <button type="button" class="bnc-config-tab active" data-config-tab="sms">
    <i class="bi bi-chat-dots"></i>
    <span><strong>Mensajes</strong><small>SMS, correo y recordatorios</small></span>
  </button>
  <button type="button" class="bnc-config-tab" data-config-tab="rewards">
    <i class="bi bi-gift"></i>
    <span><strong>Recompensas</strong><small>Regla activa y accesos</small></span>
  </button>
  <button type="button" class="bnc-config-tab" data-config-tab="system">
    <i class="bi bi-sliders"></i>
    <span><strong>Sistema</strong><small>Base para nuevas opciones</small></span>
  </button>
</div>

<div class="row g-4" data-config-section="sms">
  <div class="col-xl-4">
    <div class="bnc-config-card h-100">
      <div class="bnc-config-card-header">
        <div>
          <h3 class="h6 fw-bold mb-0">Saldo visual SMS</h3>
          <small class="text-muted">Control interno de los mensajes comprados.</small>
        </div>
        <span class="badge <?= !empty($sms['is_low']) ? 'text-bg-warning' : 'text-bg-success' ?>">
          <?= !empty($sms['is_low']) ? 'Saldo bajo' : 'Saludable' ?>
        </span>
      </div>
      <div class="bnc-config-card-body text-center">
        <div class="bnc-sms-ring mb-3" style="--value: <?= $percentRemaining ?>">
          <div class="bnc-sms-ring-inner">
            <div>
              <strong><?= $percentRemaining ?>%</strong>
              <span class="text-muted small">disponible</span>
            </div>
          </div>
        </div>
        <div class="d-flex justify-content-between text-muted small">
          <span><?= number_format($used) ?> usados</span>
          <span><?= number_format($remaining) ?> restantes</span>
        </div>
        <div class="progress mt-2" style="height:9px">
          <div class="progress-bar bg-success" style="width: <?= $percentRemaining ?>%"></div>
          <div class="progress-bar bg-secondary-subtle" style="width: <?= $percentUsed ?>%"></div>
        </div>
        <p class="small text-muted mt-3 mb-0">
          El saldo se descuenta solo cuando SMS Masivos acepta un envio real. No descuenta en sandbox ni en errores.
        </p>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="bnc-config-card">
      <div class="bnc-config-card-header">
        <div>
          <h3 class="h6 fw-bold mb-0">Configuracion de SMS</h3>
          <small class="text-muted">Inventario, automatizacion y mensaje de recordatorio.</small>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/sms-prueba.php') ?>">
          <i class="bi bi-chat-dots"></i> Probar SMS
        </a>
      </div>
      <div class="bnc-config-card-body">
        <form method="POST" class="row g-3">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="save_sms_settings">

          <div class="col-md-6">
            <label class="bnc-config-switch">
              <input type="checkbox" class="form-check-input m-0" name="sms_inventory_enabled" value="1" <?= !empty($sms['inventory_enabled']) ? 'checked' : '' ?>>
              <span>
                <strong>Controlar saldo local</strong>
                <span class="d-block small text-muted">Bloquea envios reales si el saldo visual llega a cero.</span>
              </span>
            </label>
          </div>
          <div class="col-md-6">
            <label class="bnc-config-switch">
              <input type="checkbox" class="form-check-input m-0" name="sms_reminders_enabled" value="1" <?= !empty($sms['reminders_enabled']) ? 'checked' : '' ?>>
              <span>
                <strong>Recordatorios automaticos</strong>
                <span class="d-block small text-muted">Permite que el cron envie SMS de citas del dia.</span>
              </span>
            </label>
          </div>

          <div class="col-md-4">
            <label class="bnc-label">SMS comprados acumulados</label>
            <input type="number" min="0" name="sms_total_purchased" class="form-control" value="<?= (int) $sms['total_purchased'] ?>">
          </div>
          <div class="col-md-4">
            <label class="bnc-label">SMS disponibles</label>
            <input type="number" min="0" name="sms_remaining" class="form-control" value="<?= (int) $sms['remaining'] ?>">
          </div>
          <div class="col-md-4">
            <label class="bnc-label">Alerta de saldo bajo</label>
            <input type="number" min="0" name="sms_low_balance_threshold" class="form-control" value="<?= (int) $sms['low_balance_threshold'] ?>">
          </div>
          <div class="col-md-6">
            <label class="bnc-label">Enviar recordatorio minutos antes</label>
            <input type="number" min="15" max="1440" name="sms_reminder_lead_minutes" class="form-control" value="<?= (int) $sms['reminder_lead_minutes'] ?>">
          </div>
          <div class="col-md-6">
            <label class="bnc-label">Ventana de tolerancia del cron</label>
            <input type="number" min="5" max="180" name="sms_reminder_window_minutes" class="form-control" value="<?= (int) $sms['reminder_window_minutes'] ?>">
          </div>
          <div class="col-12">
            <label class="bnc-label">Plantilla del SMS</label>
            <textarea name="sms_message_template" rows="3" maxlength="155" class="form-control"><?= e((string) $sms['message_template']) ?></textarea>
            <div class="form-text">Variables: {nombre}, {hora}, {sucursal}, {codigo}. Se limpia a texto sin acentos para SMS.</div>
          </div>
          <div class="col-12 d-grid d-md-flex justify-content-md-end">
            <button class="btn btn-bnc-primary">
              <i class="bi bi-save"></i> Guardar configuracion
            </button>
          </div>
        </form>

        <hr>

        <form method="POST" class="row g-3 align-items-end">
          <?= Csrf::input() ?>
          <input type="hidden" name="action" value="add_sms_purchase">
          <div class="col-md-4">
            <label class="bnc-label">Registrar compra nueva</label>
            <input type="number" min="1" name="purchase_quantity" class="form-control" placeholder="500">
          </div>
          <div class="col-md-5">
            <label class="bnc-label">Nota</label>
            <input type="text" name="purchase_note" class="form-control" placeholder="Compra paquete 500 SMS">
          </div>
          <div class="col-md-3 d-grid">
            <button class="btn btn-outline-success">
              <i class="bi bi-plus-circle"></i> Sumar saldo
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mt-1" data-config-mixed-row>
  <div class="col-xl-6" data-config-section="sms">
    <div class="bnc-config-card h-100">
      <div class="bnc-config-card-header">
        <div>
          <h3 class="h6 fw-bold mb-0">Estado tecnico SMS</h3>
          <small class="text-muted">Lectura segura de secrets y API.</small>
        </div>
      </div>
      <div class="bnc-config-card-body">
        <div class="row g-3">
          <div class="col-sm-6"><span class="text-muted small">API habilitada</span><div class="fw-bold"><?= !empty($smsApi['enabled']) ? 'Si' : 'No' ?></div></div>
          <div class="col-sm-6"><span class="text-muted small">API key</span><div class="fw-bold"><?= !empty($smsApi['has_apikey']) ? e((string) $smsApi['apikey_preview']) : 'No detectada' ?></div></div>
          <div class="col-sm-6"><span class="text-muted small">Sandbox</span><div class="fw-bold"><?= !empty($smsApi['sandbox']) ? 'Si' : 'No, envia real' ?></div></div>
          <div class="col-sm-6"><span class="text-muted small">SMS enviados total</span><div class="fw-bold"><?= number_format((int) $smsStats['sent_total']) ?></div></div>
          <div class="col-sm-6"><span class="text-muted small">Correos de respaldo</span><div class="fw-bold"><?= number_format((int) $smsStats['fallback_total']) ?></div></div>
          <div class="col-12">
            <div class="alert alert-info mb-0 small">
              Si no hay saldo SMS, el telefono no tiene formato valido de Mexico o SMS Masivos rechaza el envio, el sistema manda el recordatorio por correo de respaldo.
            </div>
          </div>
          <div class="col-12"><span class="text-muted small">Archivo cargado</span><div class="small text-break"><?= e((string) $smsApi['config_path']) ?></div></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-6" data-config-section="rewards" hidden>
    <div class="bnc-config-card h-100">
      <div class="bnc-config-card-header">
        <div>
          <h3 class="h6 fw-bold mb-0">Recompensas</h3>
          <small class="text-muted">Resumen de regla activa.</small>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/recompensas.php') ?>">
          <i class="bi bi-gift"></i> Administrar
        </a>
      </div>
      <div class="bnc-config-card-body">
        <div class="row g-3">
          <div class="col-sm-6"><span class="text-muted small">Regla activa</span><div class="fw-bold"><?= e((string) ($rewardConfig['name'] ?? 'Cliente frecuente')) ?></div></div>
          <div class="col-sm-6"><span class="text-muted small">Asistencias requeridas</span><div class="fw-bold"><?= (int) ($rewardConfig['attendances_required'] ?? 10) ?></div></div>
          <div class="col-sm-6"><span class="text-muted small">Vigencia</span><div class="fw-bold"><?= (int) ($rewardConfig['validity_days'] ?? 60) ?> dias</div></div>
          <div class="col-sm-6"><span class="text-muted small">Reset automatico</span><div class="fw-bold"><?= !empty($rewardConfig['auto_reset']) ? 'Si' : 'No' ?></div></div>
        </div>
        <p class="small text-muted mt-3 mb-0">
          Las reglas finas de recompensas se mantienen en su modulo para no duplicar logica.
        </p>
      </div>
    </div>
  </div>
</div>

<div class="bnc-config-card mt-4" data-config-section="sms">
  <div class="bnc-config-card-header">
    <div>
      <h3 class="h6 fw-bold mb-0">Movimientos de saldo SMS</h3>
      <small class="text-muted">Ultimos descuentos y compras registradas.</small>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table bnc-config-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Movimiento</th>
          <th>Cambio</th>
          <th>Saldo despues</th>
          <th>Referencia</th>
          <th>Nota</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$smsLogs): ?>
          <tr><td colspan="6" class="text-muted">Aun no hay movimientos de saldo.</td></tr>
        <?php endif; ?>
        <?php foreach ($smsLogs as $log): ?>
          <tr>
            <td><?= e(date('d/m/Y H:i', strtotime((string) $log['created_at']))) ?></td>
            <td><span class="badge <?= $log['action'] === 'purchase' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= e((string) $log['action']) ?></span></td>
            <td class="<?= (int) $log['delta'] >= 0 ? 'text-success' : 'text-danger' ?> fw-bold"><?= (int) $log['delta'] ?></td>
            <td><?= number_format((int) $log['remaining_after']) ?></td>
            <td class="small text-muted"><?= e((string) ($log['reference'] ?? '')) ?></td>
            <td class="small"><?= e((string) ($log['note'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-4 mt-1" data-config-section="system" hidden>
  <div class="col-xl-4">
    <div class="bnc-config-empty h-100">
      <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-envelope-check text-success"></i>
        <h3 class="h6 fw-bold mb-0">Correos</h3>
      </div>
      <p class="text-muted small mb-0">Los correos transaccionales ya usan texto plano y la configuracion SMTP central del sistema.</p>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="bnc-config-empty h-100">
      <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-credit-card text-primary"></i>
        <h3 class="h6 fw-bold mb-0">Pagos</h3>
      </div>
      <p class="text-muted small mb-0">La configuracion de servicios con pago en linea se mantiene en el modulo Pagos para evitar duplicar controles.</p>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="bnc-config-empty h-100">
      <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-shield-check text-warning"></i>
        <h3 class="h6 fw-bold mb-0">Seguridad</h3>
      </div>
      <p class="text-muted small mb-0">Este espacio queda preparado para futuras reglas globales, permisos y controles operativos.</p>
    </div>
  </div>
</div>

<script>
  document.querySelectorAll('[data-config-tab]').forEach((tab) => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.configTab;
      document.querySelectorAll('[data-config-tab]').forEach((btn) => {
        btn.classList.toggle('active', btn === tab);
      });
      document.querySelectorAll('[data-config-section]').forEach((section) => {
        section.hidden = section.dataset.configSection !== target;
      });
      document.querySelectorAll('[data-config-mixed-row]').forEach((row) => {
        row.hidden = !Array.from(row.children).some((child) => !child.hidden);
      });
    });
  });
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

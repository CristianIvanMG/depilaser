<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$filters = ReportService::filters($_GET);
$branches = ReportService::branches();
$professionals = ReportService::professionals();
$data = ReportService::dashboard($filters);
$kpis = $data['kpis'];
$pageTitle = 'Reportes';
require __DIR__ . '/../includes/layouts/header_admin.php';

$chartData = [
    'appointmentsByMonth' => $data['appointmentsByMonth'],
    'appointmentsByBranch' => $data['appointmentsByBranch'],
    'appointmentsByStatus' => $data['appointmentsByStatus'],
    'occupancyByBranch' => $data['occupancyByBranch'],
    'appointmentsByProfessional' => $data['appointmentsByProfessional'],
    'demandByHour' => $data['demandByHour'],
    'revenueByMonth' => $data['revenueByMonth'],
    'revenueByBranch' => $data['revenueByBranch'],
    'newClientsByMonth' => $data['newClientsByMonth'],
];
$query = http_build_query($filters);
?>

<div class="bnc-report-hero mb-4">
  <div>
    <div class="bnc-report-eyebrow">Inteligencia operativa</div>
    <h2>Reportes ejecutivos</h2>
    <p>Monitorea citas, ocupación, ingresos, clientes nuevos y desempeño del equipo con datos reales de la agenda.</p>
  </div>
  <a class="btn btn-bnc-primary" href="<?= url('admin/reportes-pdf.php?' . $query) ?>" target="_blank" rel="noopener">
    <i class="bi bi-file-earmark-pdf"></i> Generar PDF
  </a>
</div>

<form method="GET" class="bnc-card mb-4">
  <div class="bnc-card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-3">
        <label class="bnc-label">Desde</label>
        <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="bnc-label">Hasta</label>
        <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="bnc-label">Sucursal</label>
        <select name="branch_id" class="form-select">
          <option value="0">Todas las sucursales</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) $filters['branch_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="bnc-label">Profesional</label>
        <select name="professional_id" class="form-select">
          <option value="0">Todos los profesionales</option>
          <?php foreach ($professionals as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) $filters['professional_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="bnc-label">Tipo de PDF</label>
        <select name="report_type" class="form-select">
          <option value="general" <?= $filters['report_type'] === 'general' ? 'selected' : '' ?>>Reporte ejecutivo general</option>
          <option value="citas_sucursal" <?= $filters['report_type'] === 'citas_sucursal' ? 'selected' : '' ?>>Citas por sucursal y mes</option>
          <option value="ingresos" <?= $filters['report_type'] === 'ingresos' ? 'selected' : '' ?>>Ingresos por sucursal y mes</option>
          <option value="asistencia" <?= $filters['report_type'] === 'asistencia' ? 'selected' : '' ?>>Atendidas vs no asistidas</option>
          <option value="ocupacion" <?= $filters['report_type'] === 'ocupacion' ? 'selected' : '' ?>>Ocupación de cabinas</option>
        </select>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <button class="btn btn-bnc-primary" type="submit"><i class="bi bi-funnel"></i> Aplicar filtros</button>
        <a class="btn btn-bnc-outline" href="<?= url('admin/reportes.php') ?>">Limpiar</a>
      </div>
    </div>
  </div>
</form>

<div class="row g-3 mb-4">
  <div class="col-12 col-md-6 col-xl-3">
    <div class="bnc-kpi"><div class="bnc-kpi-icon"><i class="bi bi-calendar-check"></i></div>
      <div><div class="bnc-kpi-num"><?= number_format($kpis['total']) ?></div><div class="bnc-kpi-label">Citas del periodo</div></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="bnc-kpi"><div class="bnc-kpi-icon"><i class="bi bi-cash-stack"></i></div>
      <div><div class="bnc-kpi-num">$<?= number_format($kpis['revenue'], 0, '.', ',') ?></div><div class="bnc-kpi-label">Ingresos atendidos</div></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="bnc-kpi"><div class="bnc-kpi-icon"><i class="bi bi-person-plus"></i></div>
      <div><div class="bnc-kpi-num"><?= number_format($kpis['new_clients']) ?></div><div class="bnc-kpi-label">Clientes nuevos</div></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="bnc-kpi"><div class="bnc-kpi-icon"><i class="bi bi-speedometer"></i></div>
      <div><div class="bnc-kpi-num"><?= number_format($kpis['occupancy_pct'], 1) ?>%</div><div class="bnc-kpi-label">Ocupación promedio</div></div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-12 col-xl-7">
    <div class="bnc-card bnc-report-chart"><div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Citas por mes</h2></div><div class="bnc-card-body"><canvas id="appointmentsByMonth"></canvas></div></div>
  </div>
  <div class="col-12 col-xl-5">
    <div class="bnc-card bnc-report-chart"><div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Citas por estado</h2></div><div class="bnc-card-body"><canvas id="appointmentsByStatus"></canvas></div></div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="bnc-card bnc-report-chart"><div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Ingresos por sucursal</h2></div><div class="bnc-card-body"><canvas id="revenueByBranch"></canvas></div></div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="bnc-card bnc-report-chart"><div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Ocupación de cabinas</h2></div><div class="bnc-card-body"><canvas id="occupancyByBranch"></canvas></div></div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="bnc-card bnc-report-chart"><div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Demanda por horario</h2></div><div class="bnc-card-body"><canvas id="demandByHour"></canvas></div></div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="bnc-card bnc-report-chart"><div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Clientes nuevos</h2></div><div class="bnc-card-body"><canvas id="newClientsByMonth"></canvas></div></div>
  </div>
</div>

<div class="row g-4 mt-1">
  <div class="col-12 col-xl-6">
    <div class="bnc-card">
      <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Desempeño por profesional</h2></div>
      <div class="table-responsive">
        <table class="bnc-table mb-0">
          <thead><tr><th>Profesional</th><th>Citas</th><th>Atendidas</th><th>No asistió</th><th>Ingreso</th></tr></thead>
          <tbody>
          <?php if (!$data['professionalPerformance']): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">Sin datos para este periodo.</td></tr>
          <?php else: foreach ($data['professionalPerformance'] as $r): ?>
            <tr><td><?= e($r['professional']) ?></td><td><?= (int) $r['total_appointments'] ?></td><td><?= (int) $r['attended'] ?></td><td><?= (int) $r['no_show'] ?></td><td>$<?= number_format((float) $r['revenue'], 0, '.', ',') ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-12 col-xl-6">
    <div class="bnc-card">
      <div class="bnc-card-header"><h2 class="h6 fw-bold mb-0">Citas creadas y venta por responsable</h2></div>
      <div class="table-responsive">
        <table class="bnc-table mb-0">
          <thead><tr><th>Responsable</th><th>Creadas</th><th>Atendidas</th><th>Ingreso</th></tr></thead>
          <tbody>
          <?php $created = ReportService::createdByPerformance($filters); ?>
          <?php if (!$created): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Sin datos para este periodo.</td></tr>
          <?php else: foreach ($created as $r): ?>
            <tr><td><?= e($r['creator']) ?></td><td><?= (int) $r['created_count'] ?></td><td><?= (int) $r['converted_attended'] ?></td><td>$<?= number_format((float) $r['revenue'], 0, '.', ',') ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const reportData = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const bncPalette = ['#d63b93', '#a4276c', '#6aa5ff', '#6dbb82', '#d7a83e', '#9aa0a6', '#df7d88', '#4d3a4b'];
const baseOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { labels: { boxWidth: 12, font: { family: 'Plus Jakarta Sans' } } } },
  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
};
function rows(name) { return reportData[name] || []; }
function labels(name, key = 'label') { return rows(name).map(r => r[key]); }
function values(name, key = 'value') { return rows(name).map(r => Number(r[key] || 0)); }
function chart(id, type, labelsData, data, label, options = {}) {
  const el = document.getElementById(id);
  if (!el) return;
  new Chart(el, {
    type,
    data: { labels: labelsData, datasets: [{ label, data, backgroundColor: bncPalette, borderColor: '#d63b93', tension: .35, borderWidth: 2 }] },
    options: Object.assign({}, baseOptions, options)
  });
}
chart('appointmentsByMonth', 'line', labels('appointmentsByMonth'), values('appointmentsByMonth'), 'Citas');
chart('appointmentsByStatus', 'doughnut', labels('appointmentsByStatus'), values('appointmentsByStatus'), 'Citas', { scales: {} });
chart('revenueByBranch', 'bar', labels('revenueByBranch'), values('revenueByBranch'), 'Ingresos');
chart('occupancyByBranch', 'bar', labels('occupancyByBranch'), rows('occupancyByBranch').map(r => Number(r.occupancy_pct || 0)), 'Ocupación %');
chart('demandByHour', 'bar', labels('demandByHour'), values('demandByHour'), 'Citas');
chart('newClientsByMonth', 'line', labels('newClientsByMonth'), values('newClientsByMonth'), 'Clientes nuevos');
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

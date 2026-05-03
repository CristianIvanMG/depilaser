<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

header('Content-Type: text/html; charset=UTF-8');

$filters = ReportService::filters($_GET);
$branches = ReportService::branches();
$professionals = ReportService::professionals();
$data = ReportService::dashboard($filters);
$createdPerformance = ReportService::createdByPerformance($filters);
$kpis = $data['kpis'];

$branchName = 'Todas las sucursales';
foreach ($branches as $branch) {
    if ((int) $branch['id'] === (int) $filters['branch_id']) {
        $branchName = $branch['name'];
        break;
    }
}

$professionalName = 'Todos los profesionales';
foreach ($professionals as $professional) {
    if ((int) $professional['id'] === (int) $filters['professional_id']) {
        $professionalName = $professional['name'];
        break;
    }
}

$reportTitles = [
    'general' => 'Reporte ejecutivo general',
    'citas_sucursal' => 'Reporte de citas por sucursal y mes',
    'ingresos' => 'Reporte de ingresos por sucursal y mes',
    'asistencia' => 'Reporte de citas atendidas vs no asistidas',
    'ocupacion' => 'Reporte de ocupación de cabinas',
];
$reportTitle = $reportTitles[$filters['report_type']] ?? $reportTitles['general'];

function bnc_report_should_show(string $section, array $filters): bool
{
    return $filters['report_type'] === 'general' || $filters['report_type'] === $section;
}

function bnc_money(float $amount): string
{
    return '$' . number_format($amount, 0, '.', ',') . ' MXN';
}
?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title><?= e($reportTitle) ?> · BellaNick Clinic</title>
  <style>
    :root {
      --ink: #21051f;
      --muted: #6f6070;
      --pink: #d63b93;
      --pink-deep: #9e2367;
      --line: #ead6e2;
      --soft: #fff6fb;
      --paper: #ffffff;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: #f6f3f8;
      color: var(--ink);
      font-family: "Plus Jakarta Sans", Arial, sans-serif;
      font-size: 13px;
      line-height: 1.45;
    }
    .page {
      max-width: 1080px;
      margin: 24px auto;
      background: var(--paper);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: 0 24px 60px rgba(42, 16, 41, .12);
      overflow: hidden;
    }
    .toolbar {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      max-width: 1080px;
      margin: 20px auto 0;
    }
    .btn {
      border: 1.5px solid var(--line);
      border-radius: 999px;
      color: var(--ink);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 800;
      padding: 10px 16px;
      text-decoration: none;
    }
    .btn-primary {
      background: linear-gradient(135deg, #e94aa3, #a4276c);
      border-color: transparent;
      color: #fff;
    }
    header {
      background: linear-gradient(135deg, #2a1029 0%, #3b1739 58%, #f8e7f1 100%);
      color: #fff;
      padding: 34px 38px;
    }
    .brand {
      align-items: center;
      display: flex;
      gap: 14px;
      margin-bottom: 26px;
    }
    .brand-mark {
      align-items: center;
      background: linear-gradient(135deg, #e94aa3, #a4276c);
      border-radius: 14px;
      display: inline-flex;
      font-size: 24px;
      font-weight: 900;
      height: 54px;
      justify-content: center;
      width: 54px;
    }
    .brand strong { display: block; font-size: 22px; line-height: 1; }
    .brand span:last-child { color: #ffd8ec; font-size: 12px; font-weight: 800; letter-spacing: 1.6px; text-transform: uppercase; }
    h1 {
      font-size: 30px;
      line-height: 1.15;
      margin: 0 0 8px;
    }
    .subtitle { color: #f6dceb; margin: 0; max-width: 760px; }
    .filters {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      padding: 22px 38px;
      background: var(--soft);
      border-bottom: 1px solid var(--line);
    }
    .filter {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 12px 14px;
    }
    .label {
      color: var(--pink-deep);
      display: block;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: .7px;
      margin-bottom: 4px;
      text-transform: uppercase;
    }
    .content { padding: 30px 38px 38px; }
    .kpis {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 24px;
    }
    .kpi {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
    }
    .kpi-value { font-size: 24px; font-weight: 900; line-height: 1; }
    .kpi-title { color: var(--muted); font-size: 11px; font-weight: 800; margin-top: 8px; text-transform: uppercase; }
    section {
      border: 1px solid var(--line);
      border-radius: 14px;
      margin-top: 18px;
      overflow: hidden;
      page-break-inside: avoid;
    }
    h2 {
      background: var(--soft);
      border-bottom: 1px solid var(--line);
      font-size: 15px;
      margin: 0;
      padding: 14px 16px;
    }
    .section-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
      padding: 16px;
    }
    table {
      border-collapse: collapse;
      width: 100%;
    }
    th {
      color: var(--pink-deep);
      font-size: 10px;
      font-weight: 900;
      letter-spacing: .5px;
      padding: 9px 8px;
      text-align: left;
      text-transform: uppercase;
    }
    td {
      border-top: 1px solid #f0e3eb;
      padding: 10px 8px;
      vertical-align: top;
    }
    .empty {
      color: var(--muted);
      padding: 18px;
      text-align: center;
    }
    .note {
      color: var(--muted);
      font-size: 11px;
      margin-top: 18px;
    }
    @media print {
      body { background: #fff; }
      .toolbar { display: none; }
      .page {
        border: 0;
        border-radius: 0;
        box-shadow: none;
        margin: 0;
        max-width: none;
      }
      header { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    }
    @media (max-width: 760px) {
      .filters,
      .kpis,
      .section-grid { grid-template-columns: 1fr; }
      .toolbar { margin: 14px; }
      .page { border-radius: 0; margin: 0; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <a class="btn" href="<?= url('admin/reportes.php?' . http_build_query($filters)) ?>">Volver a reportes</a>
    <button class="btn btn-primary" type="button" onclick="window.print()">Descargar PDF</button>
  </div>

  <article class="page">
    <header>
      <div class="brand">
        <span class="brand-mark">B</span>
        <div><strong>BellaNick Clinic</strong><span>Reporte administrativo</span></div>
      </div>
      <h1><?= e($reportTitle) ?></h1>
      <p class="subtitle">Resumen ejecutivo para evaluar operación, demanda, ocupación e ingresos del periodo seleccionado.</p>
    </header>

    <div class="filters">
      <div class="filter"><span class="label">Periodo</span><?= e($filters['from']) ?> al <?= e($filters['to']) ?></div>
      <div class="filter"><span class="label">Sucursal</span><?= e($branchName) ?></div>
      <div class="filter"><span class="label">Profesional</span><?= e($professionalName) ?></div>
      <div class="filter"><span class="label">Generado</span><?= e(date('Y-m-d H:i')) ?></div>
    </div>

    <div class="content">
      <div class="kpis">
        <div class="kpi"><div class="kpi-value"><?= number_format((int) $kpis['total']) ?></div><div class="kpi-title">Citas del periodo</div></div>
        <div class="kpi"><div class="kpi-value"><?= bnc_money((float) $kpis['revenue']) ?></div><div class="kpi-title">Ingresos atendidos</div></div>
        <div class="kpi"><div class="kpi-value"><?= number_format((int) $kpis['new_clients']) ?></div><div class="kpi-title">Clientes nuevos</div></div>
        <div class="kpi"><div class="kpi-value"><?= number_format((float) $kpis['occupancy_pct'], 1) ?>%</div><div class="kpi-title">Ocupación promedio</div></div>
      </div>

      <?php if (bnc_report_should_show('citas_sucursal', $filters)): ?>
      <section>
        <h2>Citas por sucursal y mes</h2>
        <div class="section-grid">
          <table>
            <thead><tr><th>Sucursal</th><th>Citas</th></tr></thead>
            <tbody>
              <?php if (!$data['appointmentsByBranch']): ?><tr><td colspan="2" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($data['appointmentsByBranch'] as $row): ?>
                <tr><td><?= e($row['label']) ?></td><td><?= number_format((int) $row['value']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <table>
            <thead><tr><th>Mes</th><th>Citas</th></tr></thead>
            <tbody>
              <?php if (!$data['appointmentsByMonth']): ?><tr><td colspan="2" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($data['appointmentsByMonth'] as $row): ?>
                <tr><td><?= e($row['label']) ?></td><td><?= number_format((int) $row['value']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if (bnc_report_should_show('ingresos', $filters)): ?>
      <section>
        <h2>Ingresos por sucursal y mes</h2>
        <div class="section-grid">
          <table>
            <thead><tr><th>Sucursal</th><th>Ingresos</th></tr></thead>
            <tbody>
              <?php if (!$data['revenueByBranch']): ?><tr><td colspan="2" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($data['revenueByBranch'] as $row): ?>
                <tr><td><?= e($row['label']) ?></td><td><?= bnc_money((float) $row['value']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <table>
            <thead><tr><th>Mes</th><th>Ingresos</th></tr></thead>
            <tbody>
              <?php if (!$data['revenueByMonth']): ?><tr><td colspan="2" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($data['revenueByMonth'] as $row): ?>
                <tr><td><?= e($row['label']) ?></td><td><?= bnc_money((float) $row['value']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if (bnc_report_should_show('asistencia', $filters)): ?>
      <section>
        <h2>Citas atendidas vs no asistidas</h2>
        <div class="section-grid">
          <table>
            <thead><tr><th>Estado</th><th>Citas</th></tr></thead>
            <tbody>
              <?php if (!$data['appointmentsByStatus']): ?><tr><td colspan="2" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($data['appointmentsByStatus'] as $row): ?>
                <tr><td><?= e($row['label']) ?></td><td><?= number_format((int) $row['value']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <table>
            <thead><tr><th>Indicador</th><th>Total</th></tr></thead>
            <tbody>
              <tr><td>Atendidas</td><td><?= number_format((int) $kpis['attended']) ?></td></tr>
              <tr><td>No asistió</td><td><?= number_format((int) $kpis['no_show']) ?></td></tr>
              <tr><td>Canceladas</td><td><?= number_format((int) $kpis['cancelled']) ?></td></tr>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if (bnc_report_should_show('ocupacion', $filters)): ?>
      <section>
        <h2>Ocupación de cabinas</h2>
        <div class="section-grid">
          <table>
            <thead><tr><th>Sucursal</th><th>Citas activas</th><th>Minutos ocupados</th><th>Ocupación</th></tr></thead>
            <tbody>
              <?php if (!$data['occupancyByBranch']): ?><tr><td colspan="4" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($data['occupancyByBranch'] as $row): ?>
                <tr>
                  <td><?= e($row['label']) ?></td>
                  <td><?= number_format((int) $row['appointments']) ?></td>
                  <td><?= number_format((int) $row['booked_minutes']) ?></td>
                  <td><?= number_format((float) $row['occupancy_pct'], 1) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <table>
            <thead><tr><th>Horario</th><th>Citas</th><th>Efectivas</th></tr></thead>
            <tbody>
              <?php if (!$data['demandByHour']): ?><tr><td colspan="3" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($data['demandByHour'] as $row): ?>
                <tr><td><?= e($row['label']) ?></td><td><?= number_format((int) $row['value']) ?></td><td><?= number_format((int) $row['effective']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($filters['report_type'] === 'general'): ?>
      <section>
        <h2>Desempeño comercial y operativo</h2>
        <div class="section-grid">
          <table>
            <thead><tr><th>Profesional</th><th>Citas</th><th>Atendidas</th><th>Ingreso</th></tr></thead>
            <tbody>
              <?php if (!$data['professionalPerformance']): ?><tr><td colspan="4" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($data['professionalPerformance'] as $row): ?>
                <tr><td><?= e($row['professional']) ?></td><td><?= number_format((int) $row['total_appointments']) ?></td><td><?= number_format((int) $row['attended']) ?></td><td><?= bnc_money((float) $row['revenue']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <table>
            <thead><tr><th>Responsable</th><th>Creadas</th><th>Atendidas</th><th>Ingreso</th></tr></thead>
            <tbody>
              <?php if (!$createdPerformance): ?><tr><td colspan="4" class="empty">Sin datos para este periodo.</td></tr><?php endif; ?>
              <?php foreach ($createdPerformance as $row): ?>
                <tr><td><?= e($row['creator']) ?></td><td><?= number_format((int) $row['created_count']) ?></td><td><?= number_format((int) $row['converted_attended']) ?></td><td><?= bnc_money((float) $row['revenue']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <p class="note">Los ingresos se calculan con citas atendidas y el precio registrado del servicio. La ocupación usa minutos agendados activos contra horarios configurados y capacidad de cabinas por sucursal.</p>
    </div>
  </article>
</body>
</html>

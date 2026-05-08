<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
ServiceCatalogService::ensureSchema();

// KPIs
$today = date('Y-m-d');
$kpiHoy = (int) (Database::one(
    "SELECT COUNT(*) AS n FROM appointments a
     JOIN appointment_statuses s ON s.id = a.status_id
     WHERE DATE(a.start_at) = ? AND s.slug NOT IN ('cancelada')",
    [$today]
)['n'] ?? 0);

$kpiSemana = (int) (Database::one(
    "SELECT COUNT(*) AS n FROM appointments a
     JOIN appointment_statuses s ON s.id = a.status_id
     WHERE YEARWEEK(a.start_at,1) = YEARWEEK(CURDATE(),1)
       AND s.slug NOT IN ('cancelada')"
)['n'] ?? 0);

$kpiClientes = (int) (Database::one(
    "SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='cliente' AND u.active=1"
)['n'] ?? 0);

$kpiIngresoMes = (float) (Database::one(
    "SELECT COALESCE(SUM(" . ServiceCatalogService::priceSql('s') . "),0) AS t
     FROM appointments a
     JOIN services s ON s.id = a.service_id
     JOIN appointment_statuses st ON st.id = a.status_id
     WHERE st.slug = 'atendida'
       AND YEAR(a.start_at) = YEAR(CURDATE())
       AND MONTH(a.start_at) = MONTH(CURDATE())"
)['t'] ?? 0);

// Citas de hoy
$hoy = Database::all(
    "SELECT a.id, a.code, a.start_at, a.end_at,
            u.name AS client_name, u.phone AS client_phone,
            s.name AS service_name,
            b.name AS branch_name,
            st.slug AS status_slug, st.name AS status_name, st.color_hex
     FROM appointments a
     JOIN users u ON u.id = a.user_id
     JOIN services s ON s.id = a.service_id
     JOIN branches b ON b.id = a.branch_id
     JOIN appointment_statuses st ON st.id = a.status_id
     WHERE DATE(a.start_at) = ?
     ORDER BY a.start_at ASC",
    [$today]
);

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="row g-3 mb-4">
  <div class="col-12 col-md-6 col-xl-3">
    <div class="bnc-kpi"><div class="bnc-kpi-icon"><i class="bi bi-calendar-day"></i></div>
      <div><div class="bnc-kpi-num"><?= $kpiHoy ?></div><div class="bnc-kpi-label">Citas hoy</div></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="bnc-kpi"><div class="bnc-kpi-icon"><i class="bi bi-calendar-week"></i></div>
      <div><div class="bnc-kpi-num"><?= $kpiSemana ?></div><div class="bnc-kpi-label">Citas esta semana</div></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="bnc-kpi"><div class="bnc-kpi-icon"><i class="bi bi-people-fill"></i></div>
      <div><div class="bnc-kpi-num"><?= $kpiClientes ?></div><div class="bnc-kpi-label">Clientes activos</div></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="bnc-kpi"><div class="bnc-kpi-icon"><i class="bi bi-cash-stack"></i></div>
      <div><div class="bnc-kpi-num">$<?= number_format($kpiIngresoMes, 0, '.', ',') ?></div><div class="bnc-kpi-label">Ingreso mes (atendidas)</div></div>
    </div>
  </div>
</div>

<div class="bnc-card">
  <div class="bnc-card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 fw-bold mb-0">Agenda de hoy · <?= e(fmt_dt(date('Y-m-d') . ' 00:00:00', false)) ?></h2>
    <a href="<?= url('admin/calendario.php') ?>" class="btn btn-sm btn-bnc-outline">Ver calendario completo →</a>
  </div>
  <div class="table-responsive">
    <table class="bnc-table mb-0">
      <thead>
        <tr><th>Hora</th><th>Cliente</th><th>Servicio</th><th>Sucursal</th><th>Estado</th><th>Código</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$hoy): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Sin citas para hoy.</td></tr>
        <?php else: foreach ($hoy as $a): ?>
          <tr>
            <td class="fw-bold"><?= date('H:i', strtotime($a['start_at'])) ?></td>
            <td><?= e($a['client_name']) ?><br><small class="text-muted"><?= e($a['client_phone']) ?></small></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= e($a['branch_name']) ?></td>
            <td><span class="bnc-status <?= e($a['status_slug']) ?>"><?= e($a['status_name']) ?></span></td>
            <td><code><?= e($a['code']) ?></code></td>
            <td class="text-end"><a class="btn btn-sm btn-bnc-outline" href="<?= url('admin/cita-form.php?id=' . (int) $a['id']) ?>">Editar</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

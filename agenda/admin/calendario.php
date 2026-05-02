<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$branches = Database::all('SELECT id, name FROM branches WHERE active=1 ORDER BY display_order, name');
$pageTitle = 'Calendario';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="bnc-card">
  <div class="bnc-card-header d-flex flex-wrap gap-2 align-items-center">
    <h2 class="h6 fw-bold mb-0 me-auto">Calendario general</h2>
    <select id="branchFilter" class="form-select form-select-sm" style="width:auto">
      <option value="">Todas las sucursales</option>
      <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <a href="<?= url('admin/cita-form.php') ?>" class="btn btn-sm btn-bnc-primary"><i class="bi bi-plus-lg"></i> Nueva cita</a>
  </div>
  <div class="bnc-card-body">
    <div id="calendar"></div>
  </div>
</div>

<!-- Modal detalle -->
<div class="modal fade" id="apptModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px">
      <div class="modal-header"><h5 class="modal-title">Detalle de cita</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="apptModalBody">Cargando…</div>
      <div class="modal-footer">
        <a href="#" id="apptEditLink" class="btn btn-bnc-primary">Editar</a>
      </div>
    </div>
  </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/locales/es.global.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('calendar');
    const branchFilter = document.getElementById('branchFilter');

    const cal = new FullCalendar.Calendar(el, {
      locale: 'es',
      initialView: 'timeGridWeek',
      headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
      slotMinTime: '08:00:00',
      slotMaxTime: '21:00:00',
      nowIndicator: true,
      height: 'auto',
      events: function (info, success, failure) {
        const u = new URL('<?= url('api/calendario.json.php') ?>', window.location.origin);
        u.searchParams.set('from', info.startStr.slice(0, 10));
        u.searchParams.set('to',   info.endStr.slice(0, 10));
        if (branchFilter.value) u.searchParams.set('branch', branchFilter.value);
        fetch(u).then(r => r.json()).then(d => success(d.events || [])).catch(failure);
      },
      eventClick: function (info) {
        const a = info.event.extendedProps;
        document.getElementById('apptModalBody').innerHTML = `
          <div class="mb-2"><strong>Cliente:</strong> ${a.client}<br><small class="text-muted">${a.phone || ''}</small></div>
          <div class="mb-2"><strong>Servicio:</strong> ${a.service}</div>
          <div class="mb-2"><strong>Sucursal:</strong> ${a.branch}</div>
          <div class="mb-2"><strong>Cuándo:</strong> ${a.when}</div>
          <div class="mb-2"><strong>Estado:</strong> <span class="badge" style="background:${info.event.backgroundColor}">${a.status}</span></div>
          <div class="mb-2"><strong>Código:</strong> <code>${a.code}</code></div>
          ${a.notes_client ? `<hr><strong>Nota cliente:</strong> ${a.notes_client}` : ''}
          ${a.notes_admin ? `<hr><strong>Nota interna:</strong> ${a.notes_admin}` : ''}
        `;
        document.getElementById('apptEditLink').href = '<?= url('admin/cita-form.php') ?>?id=' + info.event.id;
        new bootstrap.Modal(document.getElementById('apptModal')).show();
      }
    });
    cal.render();
    branchFilter.addEventListener('change', () => cal.refetchEvents());
  });
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

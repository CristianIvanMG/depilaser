<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();

$branches = Database::all('SELECT id, name FROM branches WHERE active=1 ORDER BY display_order, name');
$pageTitle = 'Calendario';
require __DIR__ . '/../includes/layouts/header_admin.php';
?>

<div class="bnc-card bnc-calendar-card">
  <div class="bnc-card-header d-flex flex-wrap gap-2 align-items-center">
    <div class="me-auto">
      <h2 class="h6 fw-bold mb-1">Calendario general</h2>
      <div class="bnc-calendar-legend" aria-label="Estados de cita">
        <span><i class="bnc-dot bnc-dot-programada"></i> Programada</span>
        <span><i class="bnc-dot bnc-dot-confirmada"></i> Confirmada</span>
        <span><i class="bnc-dot bnc-dot-atendida"></i> Atendida</span>
        <span><i class="bnc-dot bnc-dot-cancelada"></i> Cancelada</span>
        <span><i class="bnc-dot bnc-dot-no-asistio"></i> No asistió</span>
      </div>
    </div>
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
      <div class="modal-body" id="apptModalBody">Cargando...</div>
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

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        }[char];
      });
    }

    function nl2br(value) {
      return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function calendarHeight() {
      return Math.max(620, window.innerHeight - 250);
    }

    const cal = new FullCalendar.Calendar(el, {
      locale: 'es',
      firstDay: 1,
      initialView: 'timeGridWeek',
      headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
      buttonText: {
        today: 'Hoy',
        month: 'Mes',
        week: 'Semana',
        day: 'Día',
        list: 'Agenda'
      },
      allDayText: 'Todo el día',
      noEventsText: 'Sin citas para mostrar',
      moreLinkText: function (n) {
        return '+' + n + ' citas';
      },
      slotMinTime: '08:00:00',
      slotMaxTime: '21:00:00',
      slotDuration: '00:15:00',
      slotLabelInterval: '01:00',
      slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
      eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
      nowIndicator: true,
      navLinks: true,
      stickyHeaderDates: true,
      expandRows: true,
      height: calendarHeight(),
      dayMaxEventRows: 6,
      eventMaxStack: 4,
      slotEventOverlap: false,
      eventMinHeight: 42,
      eventShortHeight: 38,
      views: {
        dayGridMonth: { dayMaxEventRows: 5 },
        timeGridWeek: { eventMaxStack: 4 },
        timeGridDay: { eventMaxStack: 8 },
        listWeek: {
          listDayFormat: { weekday: 'long', day: 'numeric', month: 'long' },
          listDaySideFormat: false
        }
      },
      events: function (info, success, failure) {
        const u = new URL('<?= url('api/calendario.json.php') ?>', window.location.origin);
        u.searchParams.set('from', info.startStr.slice(0, 10));
        u.searchParams.set('to', info.endStr.slice(0, 10));
        if (branchFilter.value) u.searchParams.set('branch', branchFilter.value);
        fetch(u)
          .then(r => r.json())
          .then(d => success(d.events || []))
          .catch(failure);
      },
      eventContent: function (arg) {
        const a = arg.event.extendedProps;
        const start = a.start_time || '';
        const end = a.end_time || '';
        return {
          html: `
            <div class="bnc-fc-event-inner" title="${escapeHtml(start + ' ' + a.client + ' - ' + a.service)}">
              <div class="bnc-fc-event-time">${escapeHtml(start)}${end ? ' - ' + escapeHtml(end) : ''}</div>
              <div class="bnc-fc-event-client">${escapeHtml(a.client)}</div>
              <div class="bnc-fc-event-service">${escapeHtml(a.service)}</div>
            </div>
          `
        };
      },
      eventClick: function (info) {
        const a = info.event.extendedProps;
        const statusClass = 'bnc-status-' + String(a.status_slug || 'programada').replace(/_/g, '-');
        document.getElementById('apptModalBody').innerHTML = `
          <div class="bnc-appt-detail">
            <div>
              <span class="bnc-detail-label">Cliente</span>
              <strong>${escapeHtml(a.client)}</strong>
              ${a.phone ? `<small class="text-muted d-block">${escapeHtml(a.phone)}</small>` : ''}
            </div>
            <div>
              <span class="bnc-detail-label">Servicio</span>
              <strong>${escapeHtml(a.service)}</strong>
            </div>
            <div>
              <span class="bnc-detail-label">Sucursal</span>
              <strong>${escapeHtml(a.branch)}</strong>
            </div>
            <div>
              <span class="bnc-detail-label">Profesional</span>
              <strong>${a.professional ? escapeHtml(a.professional) : '<span class="text-muted">Sin asignar</span>'}</strong>
            </div>
            <div>
              <span class="bnc-detail-label">Fecha y hora</span>
              <strong>${escapeHtml(a.when)}</strong>
            </div>
            <div>
              <span class="bnc-detail-label">Estado</span>
              <span class="bnc-status-pill ${statusClass}">${escapeHtml(a.status)}</span>
            </div>
            <div>
              <span class="bnc-detail-label">Código</span>
              <code>${escapeHtml(a.code)}</code>
            </div>
            ${a.notes_client ? `<div class="bnc-detail-note"><span class="bnc-detail-label">Nota cliente</span>${nl2br(a.notes_client)}</div>` : ''}
            ${a.notes_admin ? `<div class="bnc-detail-note"><span class="bnc-detail-label">Nota interna</span>${nl2br(a.notes_admin)}</div>` : ''}
          </div>
        `;
        document.getElementById('apptEditLink').href = '<?= url('admin/cita-form.php') ?>?id=' + info.event.id;
        new bootstrap.Modal(document.getElementById('apptModal')).show();
      }
    });
    cal.render();
    branchFilter.addEventListener('change', () => cal.refetchEvents());
    window.addEventListener('resize', () => cal.setOption('height', calendarHeight()));
  });
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

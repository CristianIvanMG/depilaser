<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();
if (!Auth::isAdmin() && !Auth::isProfessional()) {
    http_response_code(403);
    die('Acceso restringido.');
}

$canManageCalendar = Auth::isAdmin();
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
    <button type="button" id="calendarRefreshBtn" class="btn btn-sm btn-bnc-outline" title="Recargar calendario">
      <i class="bi bi-arrow-clockwise"></i> Recargar
    </button>
    <?php if ($canManageCalendar): ?>
      <a href="<?= url('admin/cita-form.php') ?>" class="btn btn-sm btn-bnc-primary"><i class="bi bi-plus-lg"></i> Nueva cita</a>
    <?php endif; ?>
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
      <div class="modal-footer flex-column align-items-stretch gap-2">
        <?php if ($canManageCalendar): ?>
          <div id="apptQuickActions" class="d-flex flex-wrap gap-2 justify-content-end w-100"></div>
        <?php endif; ?>
        <div class="d-flex justify-content-between w-100 pt-2">
          <?php if ($canManageCalendar): ?>
            <a href="#" id="apptEditLink" class="btn btn-light btn-sm"><i class="bi bi-pencil"></i> Editar</a>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
        </div>
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
    const refreshBtn = document.getElementById('calendarRefreshBtn');
    const canManageCalendar = <?= $canManageCalendar ? 'true' : 'false' ?>;

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
        const id = info.event.id;
        const statusClass = 'bnc-status-' + String(a.status_slug || 'programada').replace(/_/g, '-');
        const isPaid = !!a.is_paid || a.payment_status === 'paid';
        const canMarkPaid = canManageCalendar && a.status_slug === 'atendida' && !isPaid;
        const paymentMeta = isPaid
          ? [a.paid_method || a.paid_provider || 'manual', a.paid_reference ? 'Ref. ' + a.paid_reference : '', a.paid_at || ''].filter(Boolean).join(' · ')
          : (a.status_slug === 'atendida' ? 'Puedes registrar el pago cuando se confirme en recepcion.' : 'Se habilita cuando la cita esta Atendida.');
        document.getElementById('apptModalBody').innerHTML = `
          <div class="bnc-appt-detail" data-appt-id="${id}" data-status-slug="${escapeHtml(a.status_slug || '')}">
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
              <span class="bnc-status-pill ${statusClass}" data-status-pill>${escapeHtml(a.status)}</span>
            </div>
            <div>
              <span class="bnc-detail-label">Pago</span>
              <div class="form-check form-check-inline m-0">
                <input class="form-check-input bnc-payment-toggle" type="radio" name="paid-calendar-${id}" value="1" data-id="${id}" data-paid="${isPaid ? '1' : '0'}" ${isPaid ? 'checked' : ''} ${canMarkPaid ? '' : 'disabled'}>
                <label class="form-check-label ${isPaid ? 'text-success fw-semibold' : 'text-muted'}">${isPaid ? 'Pagada' : 'No pagada'}</label>
              </div>
              <small class="text-muted d-block">${escapeHtml(paymentMeta)}</small>
            </div>
            <div>
              <span class="bnc-detail-label">Código</span>
              <code>${escapeHtml(a.code)}</code>
            </div>
            ${a.receipt_folio ? `<div><span class="bnc-detail-label">Folio recibo</span><code>${escapeHtml(a.receipt_folio)}</code> ${a.receipt_sent ? '<span class="badge bg-success ms-1"><i class=\"bi bi-envelope-check\"></i> Enviado</span>' : ''}</div>` : ''}
            ${a.notes_client ? `<div class="bnc-detail-note"><span class="bnc-detail-label">Nota cliente</span>${nl2br(a.notes_client)}</div>` : ''}
            ${a.notes_admin ? `<div class="bnc-detail-note"><span class="bnc-detail-label">Nota interna</span>${nl2br(a.notes_admin)}</div>` : ''}
          </div>
        `;
        const editLink = document.getElementById('apptEditLink');
        if (editLink) {
          const isFinal = ['atendida', 'cancelada', 'no_asistio'].includes(a.status_slug || '');
          editLink.href = isFinal ? '#' : '<?= url('admin/cita-form.php') ?>?id=' + id;
          editLink.classList.toggle('disabled', isFinal);
          editLink.setAttribute('aria-disabled', isFinal ? 'true' : 'false');
          editLink.title = isFinal ? 'Cita en estado final; no permite edicion.' : 'Editar cita';
        }
        const quickActions = document.getElementById('apptQuickActions');
        if (quickActions) quickActions.innerHTML = canManageCalendar ? buildQuickActions(id, a) : '';
        new bootstrap.Modal(document.getElementById('apptModal')).show();
      }
    });
    cal.render();
    branchFilter.addEventListener('change', () => cal.refetchEvents());
    refreshBtn?.addEventListener('click', function () {
      if (refreshBtn.dataset.busy === '1') return;
      refreshBtn.dataset.busy = '1';
      const original = refreshBtn.innerHTML;
      refreshBtn.disabled = true;
      refreshBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Recargando';
      cal.refetchEvents();
      window.setTimeout(function () {
        refreshBtn.innerHTML = original;
        refreshBtn.disabled = false;
        refreshBtn.dataset.busy = '';
      }, 600);
    });
    window.addEventListener('resize', () => cal.setOption('height', calendarHeight()));

    // ─── Botones contextuales según estado ───
    const ALLOWED = {
      programada: ['confirmada', 'cancelada'],
      confirmada: ['atendida', 'no_asistio', 'cancelada'],
      atendida:   [],
      cancelada:  [],
      no_asistio: []
    };
    const RECIBO_URL = <?= json_encode(url('api/cita-recibo.php')) ?>;

    function canCloseOutAppointment(a) {
      const startRaw = a.start_at || '';
      const endRaw = a.end_at || '';
      const start = startRaw ? new Date(startRaw.replace(' ', 'T')) : null;
      if (!start || Number.isNaN(start.getTime())) return false;
      const end = endRaw ? new Date(endRaw.replace(' ', 'T')) : start;
      if (Number.isNaN(end.getTime())) return false;
      const now = new Date();
      return now >= end;
    }

    function buildQuickActions(id, a) {
      const slug = a.status_slug || 'programada';
      let list = ALLOWED[slug] || [];
      if (!canCloseOutAppointment(a)) {
        list = list.filter(status => !['atendida', 'no_asistio'].includes(status));
      }
      let html = '';
      if (list.includes('confirmada')) {
        html += `<button type="button" class="btn btn-success btn-sm bnc-transition" data-id="${id}" data-to="confirmada" data-confirm="¿Confirmar la cita?"><i class="bi bi-check2-circle"></i> Confirmar</button>`;
      }
      if (list.includes('atendida')) {
        html += `<button type="button" class="btn btn-success btn-sm bnc-transition" data-id="${id}" data-to="atendida" data-confirm="¿Estas seguro de marcar esta reserva como Atendida? Despues de confirmarlo, el estado ya no podra modificarse." data-send-email="ask"><i class="bi bi-clipboard2-check"></i> Atendida</button>`;
      }
      if (list.includes('no_asistio')) {
        html += `<button type="button" class="btn btn-warning btn-sm bnc-transition" data-id="${id}" data-to="no_asistio" data-confirm="¿Marcar como NO asistió? Se enviará un correo empático al cliente." data-send-email="auto"><i class="bi bi-person-x"></i> No asistió</button>`;
      }
      if (list.includes('cancelada')) {
        html += `<button type="button" class="btn btn-outline-danger btn-sm bnc-transition" data-id="${id}" data-to="cancelada" data-prompt-reason="1" data-send-email="auto"><i class="bi bi-calendar-x"></i> Cancelar</button>`;
      }
      if (slug === 'confirmada' && !canCloseOutAppointment(a)) {
        html += '<small class="text-muted me-auto">Atendida y No asistio se habilitan cuando la hora de inicio y fin ya pasaron.</small>';
      }
      if (slug === 'atendida') {
        html += `<a class="btn btn-success btn-sm" href="${RECIBO_URL}?id=${id}" target="_blank" rel="noopener"><i class="bi bi-receipt"></i> Ver recibo</a>`;
        const sent = !!a.receipt_sent;
        html += `<button type="button" class="btn btn-outline-success btn-sm bnc-send-receipt" data-id="${id}" data-already="${sent ? '1' : '0'}"><i class="bi bi-envelope-paper${sent ? '-fill' : ''}"></i> ${sent ? 'Reenviar recibo' : 'Enviar recibo'}</button>`;
      }
      if (!html) html = '<small class="text-muted me-auto">Cita en estado final - sin acciones rápidas.</small>';
      return html;
    }

    // ─── Wiring de botones rápidos (transición + recibo) ───
    const csrfToken = <?= json_encode(Csrf::token()) ?>;
    const csrfField = <?= json_encode(Csrf::FIELD) ?>;
    const ENDPOINTS = {
      transition:  <?= json_encode(url('api/cita-estado.json.php')) ?>,
      sendReceipt: <?= json_encode(url('api/cita-recibo-enviar.json.php')) ?>,
      payment: <?= json_encode(url('api/cita-pago.json.php')) ?>
    };

    function toast(type, msg) {
      const wrap = document.createElement('div');
      wrap.className = 'position-fixed top-0 end-0 p-3';
      wrap.style.zIndex = 1090;
      wrap.innerHTML = `<div class="toast align-items-center text-bg-${type} border-0 show" role="alert">
        <div class="d-flex"><div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
      document.body.appendChild(wrap);
      setTimeout(() => wrap.remove(), 4500);
    }

    async function postJson(url, data) {
      const fd = new FormData();
      Object.entries(data).forEach(([k, v]) => { if (v !== null && v !== undefined) fd.append(k, v); });
      fd.append(csrfField, csrfToken);
      const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
      let json = null; try { json = await r.json(); } catch(e) { json = { ok:false, error:'Respuesta no válida.' }; }
      return json;
    }

    document.body.addEventListener('click', async function (ev) {
      const p = ev.target.closest('.bnc-payment-toggle');
      const t = ev.target.closest('.bnc-transition');
      const r = ev.target.closest('.bnc-send-receipt');
      if (p) {
        if (p.disabled || p.dataset.paid === '1') return;
        ev.preventDefault();
        if (p.dataset.busy === '1') return;
        if (!window.confirm('Confirmar que esta cita ya fue pagada? Se enviara el recibo al cliente.')) {
          p.checked = false;
          return;
        }
        p.dataset.busy = '1';
        p.disabled = true;
        const body = await postJson(ENDPOINTS.payment, {
          appointment_id: p.dataset.id,
          paid: '1',
          method: 'manual',
          send_receipt: '1'
        });
        p.dataset.busy = '';
        if (!body.ok) {
          p.checked = false;
          p.disabled = false;
          toast('danger', body.error || 'No fue posible registrar el pago.');
          return;
        }
        if (body.receipt_warning) toast('warning', 'Pago registrado, pero el recibo no pudo enviarse: ' + body.receipt_warning);
        else if (body.receipt_sent) toast('success', 'Pago registrado y recibo enviado al cliente.');
        else toast('success', 'Pago registrado correctamente.');
        bootstrap.Modal.getInstance(document.getElementById('apptModal'))?.hide();
        cal.refetchEvents();
        return;
      }
      if (t) {
        ev.preventDefault();
        if (t.dataset.busy === '1') return;
        const sendMode = t.dataset.sendEmail || '';
        let reason = null;
        if (t.dataset.promptReason === '1') {
          reason = window.prompt('Motivo (opcional):', '');
          if (reason === null) return;
          reason = reason.trim();
        }
        if (t.dataset.confirm && !window.confirm(t.dataset.confirm)) return;
        let sendEmail = '';
        if (sendMode === 'auto') sendEmail = '1';
        if (sendMode === 'ask') sendEmail = window.confirm('¿Enviar recibo por correo al cliente ahora?') ? '1' : '';
        t.dataset.busy = '1';
        const orig = t.innerHTML;
        t.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; t.disabled = true;
        const body = await postJson(ENDPOINTS.transition, {
          appointment_id: t.dataset.id, to: t.dataset.to, reason: reason || '', send_email: sendEmail
        });
        t.innerHTML = orig; t.disabled = false; t.dataset.busy = '';
        if (!body.ok) { toast('danger', body.error || 'No fue posible cambiar el estado.'); return; }
        if (body.waitlist?.ok) toast('success', 'Estado actualizado. Se promovió automáticamente a un cliente de la lista de espera.');
        else if (body.receipt_warning) toast('warning', 'Estado cambiado, recibo no enviado: ' + body.receipt_warning);
        else if (body.status_email_warning) toast('warning', 'Estado cambiado, notificación no enviada: ' + body.status_email_warning);
        else if (body.receipt_sent) toast('success', 'Cita marcada como Atendida. Recibo enviado al cliente.');
        else if (body.status_email_sent) toast('success', 'Estado actualizado y notificación enviada al cliente.');
        else toast('success', 'Estado actualizado a “' + (body.status?.name || t.dataset.to) + '”.');
        // Cierra modal y refresca eventos
        bootstrap.Modal.getInstance(document.getElementById('apptModal'))?.hide();
        cal.refetchEvents();
        return;
      }
      if (r) {
        ev.preventDefault();
        if (r.dataset.busy === '1') return;
        const already = r.dataset.already === '1';
        const ok = window.confirm(already ? 'Este recibo ya fue enviado. ¿Reenviar al cliente?' : '¿Enviar recibo al correo del cliente?');
        if (!ok) return;
        r.dataset.busy = '1';
        const orig = r.innerHTML;
        r.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; r.disabled = true;
        const body = await postJson(ENDPOINTS.sendReceipt, {
          appointment_id: r.dataset.id, force: already ? '1' : ''
        });
        r.innerHTML = orig; r.disabled = false; r.dataset.busy = '';
        if (body.ok) { toast('success', 'Recibo enviado.'); r.dataset.already = '1'; cal.refetchEvents(); }
        else { toast('danger', body.error || 'No fue posible enviar el recibo.'); }
        return;
      }
    });
  });
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>

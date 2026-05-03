<?php
declare(strict_types=1);

/**
 * ReceiptService — genera la nota de venta / recibo profesional para citas
 * en estado ATENDIDA y los correos asociados (recibo + correo empático
 * para canceladas / no asistidas).
 *
 * - render(int $appointmentId): string         → HTML imprimible del recibo
 * - emailReceipt(int $appointmentId): array    → envía recibo + reseñas Google
 * - emailEmpathy(int $appointmentId): array    → envía correo empático
 * - hydrate(int $appointmentId): ?array        → carga datos para plantillas
 *
 * No depende de PHPMailer; usa mail() con cabeceras HTML correctas.
 * Si tu hosting tiene SMTP, puedes reemplazar self::send() sin tocar el resto.
 */
final class ReceiptService
{
    /** Datos completos de la cita + relaciones. */
    public static function hydrate(int $appointmentId): ?array
    {
        $row = Database::one(
            "SELECT a.id, a.code, a.start_at, a.end_at, a.notes_admin, a.notes_client,
                    a.status_id, a.receipt_folio, a.receipt_sent, a.receipt_sent_at,
                    a.attended_at, a.confirmed_at, a.cancelled_at, a.cancel_reason,
                    a.empathy_email_sent, a.empathy_email_sent_at,
                    u.name AS client_name, u.email AS client_email, u.phone AS client_phone,
                    s.name AS service_name, s.duration_min, s.price_mxn,
                    b.name AS branch_name, b.address AS branch_address, b.city AS branch_city,
                    b.state AS branch_state, b.phone AS branch_phone, b.email AS branch_email,
                    b.gmaps_url,
                    pr.name AS professional_name,
                    st.slug AS status_slug, st.name AS status_name
             FROM appointments a
             JOIN users u ON u.id = a.user_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             JOIN appointment_statuses st ON st.id = a.status_id
             LEFT JOIN users pr ON pr.id = a.professional_id
             WHERE a.id = ? LIMIT 1",
            [$appointmentId]
        );
        return $row ?: null;
    }

    /** URL preferida para reseñas Google de la sucursal. */
    public static function googleReviewsUrl(array $data): string
    {
        if (!empty($data['gmaps_url'])) {
            // Si ya es de maps, agregamos sufijo de reviews; si no, devolvemos tal cual.
            return $data['gmaps_url'];
        }
        $q = urlencode(($data['branch_name'] ?? 'BellaNick Clinic') . ' ' . ($data['branch_city'] ?? ''));
        return 'https://www.google.com/search?q=' . $q . '&hl=es#lrd=,1';
    }

    /** HTML completo (página) del recibo / nota de venta. */
    public static function render(int $appointmentId): string
    {
        $d = self::hydrate($appointmentId);
        if (!$d) {
            return '<!DOCTYPE html><meta charset="utf-8"><title>Recibo</title><body><p>Recibo no encontrado.</p></body>';
        }
        $folio       = $d['receipt_folio'] ?: '—';
        $price       = isset($d['price_mxn']) ? '$' . number_format((float) $d['price_mxn'], 2, '.', ',') . ' MXN' : '—';
        $issued      = $d['attended_at'] ?: ($d['updated_at'] ?? date('Y-m-d H:i:s'));
        $issuedHuman = fmt_dt($issued);
        $when        = fmt_dt($d['start_at']);
        $duration    = (int) $d['duration_min'] . ' min';
        $branchAddr  = trim(($d['branch_address'] ?? '') . ', ' . ($d['branch_city'] ?? '') . ', ' . ($d['branch_state'] ?? ''), ', ');
        $clinicEmail = defined('APP_NAME') ? ($d['branch_email'] ?: 'contacto@bellanickclinic.com') : ($d['branch_email'] ?: '');
        $reviewsUrl  = self::googleReviewsUrl($d);
        $isPaid      = ($d['status_slug'] === 'atendida');

        ob_start(); ?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<title>Recibo <?= e($folio) ?> — BellaNick Clinic</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:'Plus Jakarta Sans','Segoe UI',Arial,sans-serif;background:#f4f1ee;color:#2b1d1d;padding:32px 16px}
  .receipt{max-width:780px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 12px 40px rgba(170,60,120,.12);overflow:hidden}
  .receipt-header{background:linear-gradient(135deg,#d63b93 0%,#a4276c 100%);color:#fff;padding:28px 36px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px}
  .brand{display:flex;align-items:center;gap:14px}
  .brand-mark{width:46px;height:46px;border-radius:14px;background:#fff;color:#d63b93;display:grid;place-items:center;font-weight:800;font-size:22px}
  .brand-name{font-size:20px;font-weight:800;letter-spacing:.4px}
  .brand-sub{font-size:12px;letter-spacing:2px;text-transform:uppercase;opacity:.85}
  .folio-card{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);padding:10px 16px;border-radius:12px;text-align:right}
  .folio-card .lbl{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;opacity:.85}
  .folio-card .val{font-size:17px;font-weight:800;letter-spacing:.5px}
  .receipt-body{padding:32px 36px}
  .meta-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px 28px;margin-bottom:28px}
  .meta-item .lbl{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#9b6f86;margin-bottom:4px}
  .meta-item .val{font-weight:700;color:#2b1d1d}
  table.detail{width:100%;border-collapse:collapse;margin-top:12px}
  table.detail th, table.detail td{padding:14px 16px;text-align:left;font-size:14px}
  table.detail thead th{background:#fff5f9;color:#9b3070;text-transform:uppercase;font-size:11px;letter-spacing:1.5px;border-bottom:2px solid #f1d8e7}
  table.detail tbody td{border-bottom:1px solid #f1d8e7}
  table.detail .qty{width:80px;text-align:center}
  table.detail .price{width:140px;text-align:right;font-weight:700}
  .totals{display:flex;justify-content:flex-end;margin-top:18px}
  .totals-table{min-width:280px}
  .totals-table .row{display:flex;justify-content:space-between;padding:6px 0;font-size:14px}
  .totals-table .row.total{border-top:2px solid #2b1d1d;margin-top:6px;padding-top:10px;font-size:18px;font-weight:800}
  .stamp{display:inline-block;border:2px solid #198754;color:#198754;padding:6px 14px;border-radius:999px;font-weight:700;font-size:12px;letter-spacing:1.5px;text-transform:uppercase}
  .stamp.pending{border-color:#a4276c;color:#a4276c}
  .footer-note{margin-top:28px;padding-top:18px;border-top:1px dashed #e3c8d6;color:#6b4860;font-size:12.5px;line-height:1.6}
  .signature{margin-top:30px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:24px}
  .signature .line{flex:1;min-width:220px;border-top:1px solid #2b1d1d;padding-top:6px;text-align:center;font-size:12px;color:#6b4860;text-transform:uppercase;letter-spacing:1.5px}
  .reviews{margin:30px 36px;padding:22px;border-radius:14px;background:linear-gradient(135deg,#fff5f9,#fde6f0);text-align:center}
  .reviews h3{margin:0 0 6px;color:#a4276c;font-size:16px}
  .reviews p{margin:0 0 14px;font-size:13.5px;color:#6b4860}
  .reviews a{display:inline-block;background:#d63b93;color:#fff;padding:10px 22px;border-radius:999px;text-decoration:none;font-weight:700;letter-spacing:.5px;font-size:14px}
  .reviews .stars{font-size:22px;letter-spacing:6px;color:#f5b400;margin-bottom:6px}
  @media print {
    body{background:#fff;padding:0}
    .receipt{box-shadow:none;border-radius:0}
    .no-print{display:none!important}
  }
  @media (max-width:560px){
    .meta-grid{grid-template-columns:1fr}
    .receipt-body{padding:22px 20px}
    .receipt-header{padding:20px}
  }
</style>
</head>
<body>
  <div class="no-print" style="max-width:780px;margin:0 auto 12px;text-align:right">
    <button onclick="window.print()" style="background:#d63b93;color:#fff;border:0;padding:10px 18px;border-radius:999px;font-weight:700;cursor:pointer">Imprimir / Guardar PDF</button>
  </div>
  <div class="receipt">
    <header class="receipt-header">
      <div class="brand">
        <span class="brand-mark">B</span>
        <div>
          <div class="brand-sub">BellaNick Clinic</div>
          <div class="brand-name">Nota de servicio</div>
        </div>
      </div>
      <div class="folio-card">
        <div class="lbl">Folio</div>
        <div class="val"><?= e($folio) ?></div>
      </div>
    </header>

    <div class="receipt-body">
      <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:22px">
        <div>
          <div style="font-weight:800;font-size:16px;color:#2b1d1d"><?= e($d['branch_name']) ?></div>
          <div style="color:#6b4860;font-size:13px;line-height:1.55">
            <?= e($branchAddr) ?: '' ?><br>
            <?= e($d['branch_phone'] ?? '') ?> · <?= e($clinicEmail) ?>
          </div>
        </div>
        <div style="text-align:right">
          <span class="stamp <?= $isPaid ? '' : 'pending' ?>"><?= $isPaid ? 'Servicio realizado' : 'En proceso' ?></span>
          <div style="margin-top:8px;font-size:12px;color:#9b6f86">Emitido: <?= e($issuedHuman) ?></div>
        </div>
      </div>

      <div class="meta-grid">
        <div class="meta-item">
          <div class="lbl">Cliente</div>
          <div class="val"><?= e($d['client_name']) ?></div>
          <div style="font-size:12.5px;color:#6b4860;margin-top:2px">
            <?= e($d['client_email']) ?> <?= $d['client_phone'] ? ' · ' . e($d['client_phone']) : '' ?>
          </div>
        </div>
        <div class="meta-item">
          <div class="lbl">Cita</div>
          <div class="val"><?= e($d['code']) ?></div>
          <div style="font-size:12.5px;color:#6b4860;margin-top:2px"><?= e($when) ?></div>
        </div>
        <div class="meta-item">
          <div class="lbl">Profesional</div>
          <div class="val"><?= e($d['professional_name'] ?: 'No asignado') ?></div>
        </div>
        <div class="meta-item">
          <div class="lbl">Duración</div>
          <div class="val"><?= e($duration) ?></div>
        </div>
      </div>

      <table class="detail">
        <thead>
          <tr><th>Servicio</th><th class="qty">Cant.</th><th class="price">Importe</th></tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <div style="font-weight:700;color:#2b1d1d"><?= e($d['service_name']) ?></div>
              <div style="font-size:12px;color:#6b4860;margin-top:2px"><?= e($d['branch_name']) ?> · <?= e($duration) ?></div>
            </td>
            <td class="qty">1</td>
            <td class="price"><?= e($price) ?></td>
          </tr>
        </tbody>
      </table>

      <div class="totals">
        <div class="totals-table">
          <div class="row"><span>Subtotal</span><span><?= e($price) ?></span></div>
          <div class="row total"><span>Total</span><span><?= e($price) ?></span></div>
        </div>
      </div>

      <?php if (!empty($d['notes_admin'])): ?>
        <div class="footer-note"><strong>Notas del profesional:</strong><br><?= nl2br(e($d['notes_admin'])) ?></div>
      <?php endif; ?>

      <div class="signature">
        <div class="line">Firma del profesional</div>
        <div class="line">Firma de conformidad</div>
      </div>

      <div class="footer-note">
        Este documento es un comprobante interno de servicio. Para facturación CFDI con tus datos fiscales, escríbenos a <?= e($clinicEmail) ?>.
      </div>
    </div>

    <div class="reviews">
      <div class="stars">★ ★ ★ ★ ★</div>
      <h3>Tu opinión nos ilumina</h3>
      <p>Si disfrutaste tu sesión, regálanos una reseña en Google. Cada palabra cuenta.</p>
      <a href="<?= e($reviewsUrl) ?>" target="_blank" rel="noopener">Dejar reseña en Google</a>
    </div>
  </div>
</body>
</html>
<?php
        return (string) ob_get_clean();
    }

    /** Versión email‑safe (inline styles) del recibo. */
    public static function renderEmail(int $appointmentId): string
    {
        $d = self::hydrate($appointmentId);
        if (!$d) return '<p>Recibo no disponible.</p>';
        $folio = $d['receipt_folio'] ?: '—';
        $price = isset($d['price_mxn']) ? '$' . number_format((float) $d['price_mxn'], 2, '.', ',') . ' MXN' : '—';
        $when  = fmt_dt($d['start_at']);
        $reviewsUrl = self::googleReviewsUrl($d);
        $branchAddr = trim(($d['branch_address'] ?? '') . ', ' . ($d['branch_city'] ?? '') . ', ' . ($d['branch_state'] ?? ''), ', ');

        $H = function ($s) { return e($s); };
        $html  = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#f4f1ee;padding:24px 12px">';
        $html .= '<div style="max-width:620px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.06)">';
        $html .= '<div style="background:linear-gradient(135deg,#d63b93,#a4276c);color:#fff;padding:22px 28px">';
        $html .= '<div style="font-size:11px;letter-spacing:2px;opacity:.85;text-transform:uppercase">BellaNick Clinic</div>';
        $html .= '<div style="font-size:20px;font-weight:800;margin-top:4px">Gracias por tu visita</div>';
        $html .= '<div style="font-size:13px;margin-top:6px;opacity:.9">Folio: <strong>' . $H($folio) . '</strong></div>';
        $html .= '</div>';
        $html .= '<div style="padding:24px 28px;color:#2b1d1d;font-size:14.5px;line-height:1.6">';
        $html .= '<p>Hola <strong>' . $H($d['client_name']) . '</strong>,</p>';
        $html .= '<p>Te compartimos el comprobante de tu sesión. Esperamos que la disfrutaras tanto como nosotros disfrutamos atenderte.</p>';
        $html .= '<table style="width:100%;border-collapse:collapse;margin:14px 0 4px">';
        $html .= '<tr><td style="padding:8px 0;color:#9b6f86;text-transform:uppercase;letter-spacing:1px;font-size:11px">Servicio</td><td style="padding:8px 0;text-align:right;font-weight:700">' . $H($d['service_name']) . '</td></tr>';
        $html .= '<tr><td style="padding:8px 0;color:#9b6f86;text-transform:uppercase;letter-spacing:1px;font-size:11px">Sucursal</td><td style="padding:8px 0;text-align:right">' . $H($d['branch_name']) . '</td></tr>';
        $html .= '<tr><td style="padding:8px 0;color:#9b6f86;text-transform:uppercase;letter-spacing:1px;font-size:11px">Profesional</td><td style="padding:8px 0;text-align:right">' . $H($d['professional_name'] ?: 'Equipo BellaNick') . '</td></tr>';
        $html .= '<tr><td style="padding:8px 0;color:#9b6f86;text-transform:uppercase;letter-spacing:1px;font-size:11px">Fecha</td><td style="padding:8px 0;text-align:right">' . $H($when) . '</td></tr>';
        $html .= '<tr><td style="padding:8px 0;color:#9b6f86;text-transform:uppercase;letter-spacing:1px;font-size:11px">Cita</td><td style="padding:8px 0;text-align:right"><code>' . $H($d['code']) . '</code></td></tr>';
        $html .= '<tr><td style="padding:14px 0 6px;border-top:2px solid #2b1d1d;font-size:14px;font-weight:800">Total</td><td style="padding:14px 0 6px;border-top:2px solid #2b1d1d;font-size:16px;font-weight:800;text-align:right">' . $H($price) . '</td></tr>';
        $html .= '</table>';
        $html .= '<p style="font-size:12.5px;color:#6b4860;margin-top:14px">' . $H($branchAddr) . '</p>';
        $html .= '</div>';
        // Bloque de reseñas
        $html .= '<div style="background:linear-gradient(135deg,#fff5f9,#fde6f0);padding:24px 28px;text-align:center">';
        $html .= '<div style="font-size:22px;letter-spacing:6px;color:#f5b400;margin-bottom:4px">★ ★ ★ ★ ★</div>';
        $html .= '<div style="font-size:15px;font-weight:800;color:#a4276c;margin-bottom:6px">¿Nos regalas una reseña?</div>';
        $html .= '<div style="font-size:13px;color:#6b4860;margin-bottom:14px">Tu opinión inspira a otras personas a confiar en nosotros.</div>';
        $html .= '<a href="' . $H($reviewsUrl) . '" style="display:inline-block;background:#d63b93;color:#fff;padding:10px 22px;border-radius:999px;text-decoration:none;font-weight:700">Dejar reseña en Google</a>';
        $html .= '</div>';
        $html .= '<div style="padding:16px 28px;font-size:11.5px;color:#9b6f86;text-align:center">Recibiste este correo porque tienes una cita con BellaNick Clinic. ¿Dudas? Responde a este mensaje.</div>';
        $html .= '</div></div>';
        return $html;
    }

    /** HTML del correo empático (cancelada / no asistió). */
    public static function renderEmpathy(int $appointmentId): string
    {
        $d = self::hydrate($appointmentId);
        if (!$d) return '';
        $H = function ($s) { return e($s); };
        $isCancel = ($d['status_slug'] === 'cancelada');
        $title = $isCancel ? 'Lamentamos que no hayamos podido vernos' : 'Te extrañamos en tu cita';
        $when = fmt_dt($d['start_at']);
        $reviewsUrl = self::googleReviewsUrl($d);

        $body  = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#f4f1ee;padding:28px 12px">';
        $body .= '<div style="max-width:620px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.06)">';
        $body .= '<div style="background:linear-gradient(135deg,#7a4a6a,#3d2434);color:#fff;padding:30px 28px">';
        $body .= '<div style="font-size:11px;letter-spacing:2px;opacity:.85;text-transform:uppercase">BellaNick Clinic</div>';
        $body .= '<div style="font-size:22px;font-weight:800;margin-top:8px;line-height:1.3">' . $H($title) . '</div>';
        $body .= '</div>';
        $body .= '<div style="padding:28px;color:#2b1d1d;font-size:15px;line-height:1.75">';
        $body .= '<p>Hola <strong>' . $H($d['client_name']) . '</strong>,</p>';
        if ($isCancel) {
            $body .= '<p>Notamos que tu cita del <strong>' . $H($when) . '</strong> para <strong>' . $H($d['service_name']) . '</strong> fue cancelada. Entendemos que la vida a veces se interpone, y queremos que sepas que aquí siempre te recibiremos con la misma calidez.</p>';
        } else {
            $body .= '<p>Tu cita del <strong>' . $H($when) . '</strong> para <strong>' . $H($d['service_name']) . '</strong> quedó marcada como no asistida. Sabemos que pueden surgir imprevistos, y de corazón esperamos que estés bien.</p>';
        }
        $body .= '<p>Sin embargo, debemos compartir contigo —con toda la delicadeza posible— una nota importante para tu tratamiento:</p>';
        $body .= '<div style="background:#fff5f9;border-left:4px solid #d63b93;padding:16px 18px;border-radius:8px;margin:18px 0">';
        $body .= '<p style="margin:0;font-size:14px;color:#5b3a4d">';
        $body .= 'La <strong>garantía de resultados</strong> de tu plan de depilación láser depende de respetar el calendario clínico. Cuando una sesión no se completa, el folículo retoma su ciclo y la efectividad acumulada se reduce. ';
        $body .= 'Por esta razón, y con mucho respeto, no nos es posible garantizar los avances esperados al haberse interrumpido el tratamiento. ';
        $body .= 'Queremos ser absolutamente transparentes contigo: nuestro compromiso con tu piel es real, pero requiere de tu acompañamiento puntual.';
        $body .= '</p></div>';
        $body .= '<p>Si te gustaría retomar el camino con nosotros, estaremos encantados de diseñar contigo un nuevo plan, ajustado a tu ritmo de vida.</p>';
        $body .= '<div style="text-align:center;margin:26px 0 8px">';
        $body .= '<a href="' . $H(APP_BASE_URL) . '/agendar.php" style="display:inline-block;background:#d63b93;color:#fff;padding:12px 26px;border-radius:999px;text-decoration:none;font-weight:700;font-size:14px">Reagendar con cariño</a>';
        $body .= '</div>';
        $body .= '<p style="margin-top:22px">Con todo nuestro afecto,<br><strong>El equipo BellaNick Clinic</strong></p>';
        $body .= '</div>';
        // Mini bloque reseñas (suave, no invasivo)
        $body .= '<div style="background:#fff5f9;padding:18px 28px;text-align:center;font-size:12.5px;color:#6b4860">';
        $body .= 'Si en visitas anteriores te sentiste cuidada, <a href="' . $H($reviewsUrl) . '" style="color:#a4276c;font-weight:700">comparte tu experiencia en Google</a>. Nos ayudará a seguir mejorando.';
        $body .= '</div>';
        $body .= '<div style="padding:14px 28px;text-align:center;font-size:11px;color:#9b6f86">' . $H($d['branch_name']) . ' · ' . $H($d['branch_phone'] ?? '') . '</div>';
        $body .= '</div></div>';
        return $body;
    }

    /**
     * Envía el recibo por correo al cliente. Marca receipt_sent=1 al éxito.
     * @return array{ok:bool, error?:string}
     */
    public static function emailReceipt(int $appointmentId, bool $force = false): array
    {
        $d = self::hydrate($appointmentId);
        if (!$d) return ['ok' => false, 'error' => 'Cita no encontrada.'];
        if ($d['status_slug'] !== 'atendida') {
            return ['ok' => false, 'error' => 'El recibo solo se envía cuando la cita está atendida.'];
        }
        if (empty($d['client_email'])) {
            return ['ok' => false, 'error' => 'El cliente no tiene correo electrónico registrado.'];
        }
        if ((int) $d['receipt_sent'] === 1 && !$force) {
            return ['ok' => false, 'error' => 'El recibo ya fue enviado anteriormente.'];
        }
        $subject = 'Tu recibo BellaNick · ' . ($d['receipt_folio'] ?: $d['code']);
        $html = self::renderEmail($appointmentId);
        $sent = self::send($d['client_email'], (string) $d['client_name'], $subject, $html);
        if ($sent) {
            Database::exec(
                'UPDATE appointments SET receipt_sent = 1, receipt_sent_at = NOW() WHERE id = ?',
                [$appointmentId]
            );
            Auth::audit('receipt_sent', 'appointment', $appointmentId, [
                'folio' => $d['receipt_folio'],
                'email' => $d['client_email'],
            ]);
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'No fue posible enviar el correo en este momento.'];
    }

    /** Envía correo empático (cancelada / no_asistio). Marca empathy_email_sent. */
    public static function emailEmpathy(int $appointmentId, bool $force = false): array
    {
        $d = self::hydrate($appointmentId);
        if (!$d) return ['ok' => false, 'error' => 'Cita no encontrada.'];
        if (!in_array($d['status_slug'], ['cancelada', 'no_asistio'], true)) {
            return ['ok' => false, 'error' => 'Este correo solo aplica a citas canceladas o no asistidas.'];
        }
        if (empty($d['client_email'])) {
            return ['ok' => false, 'error' => 'El cliente no tiene correo electrónico registrado.'];
        }
        if ((int) ($d['empathy_email_sent'] ?? 0) === 1 && !$force) {
            return ['ok' => false, 'error' => 'El correo empático ya fue enviado.'];
        }
        $subject = $d['status_slug'] === 'cancelada'
            ? 'Te seguimos esperando en BellaNick'
            : 'Te extrañamos hoy · BellaNick Clinic';
        $html = self::renderEmpathy($appointmentId);
        $sent = self::send($d['client_email'], (string) $d['client_name'], $subject, $html);
        if ($sent) {
            Database::exec(
                'UPDATE appointments SET empathy_email_sent = 1, empathy_email_sent_at = NOW() WHERE id = ?',
                [$appointmentId]
            );
            Auth::audit('empathy_sent', 'appointment', $appointmentId, [
                'status' => $d['status_slug'],
                'email'  => $d['client_email'],
            ]);
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'No fue posible enviar el correo en este momento.'];
    }

    /** Envío base usando mail() con cabeceras HTML correctas. */
    private static function send(string $to, string $toName, string $subject, string $html): bool
    {
        $from = defined('APP_NAME') ? APP_NAME : 'BellaNick Clinic';
        $fromEmail = 'no-reply@' . preg_replace('#^https?://#', '', (defined('APP_BASE_URL') ? parse_url(APP_BASE_URL, PHP_URL_HOST) : 'bellanickclinic.com'));

        $headers   = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: =?UTF-8?B?' . base64_encode($from) . '?= <' . $fromEmail . '>';
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'X-Mailer: BellaNickAgenda';

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $toLine = $toName ? ('=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>') : $to;

        try {
            return @mail($toLine, $encodedSubject, $html, implode("\r\n", $headers));
        } catch (\Throwable $e) {
            error_log('[receipt-email] ' . $e->getMessage());
            return false;
        }
    }
}

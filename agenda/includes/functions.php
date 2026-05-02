<?php
declare(strict_types=1);

/* ───────── HELPERS GLOBALES ───────── */

/** Escapa HTML — usar SIEMPRE en salidas */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Atajo URL */
function url(string $path = ''): string
{
    return APP_BASE_URL . '/' . ltrim($path, '/');
}

/** Redirección segura */
function redirect(string $path, int $code = 302): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)), true, $code);
    exit;
}

/** Mensaje flash en sesión */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pop(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Formatea fecha en español: "vie 10 de mayo, 10:30 a.m." */
function fmt_dt(string $datetime, bool $withTime = true): string
{
    $ts = strtotime($datetime);
    if (!$ts) return $datetime;
    $dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $d = $dias[(int) date('w', $ts)];
    $m = $meses[(int) date('n', $ts) - 1];
    $base = sprintf('%s %d de %s', $d, (int) date('j', $ts), $m);
    if ($withTime) {
        $base .= sprintf(', %s', date('g:i a', $ts));
    }
    return $base;
}

/** Formato corto: "10 may 2026, 10:30" */
function fmt_dt_short(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) return $datetime;
    $meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return sprintf('%d %s %d, %s',
        (int) date('j', $ts),
        $meses[(int) date('n', $ts) - 1],
        (int) date('Y', $ts),
        date('H:i', $ts)
    );
}

/** Genera código de cita BNC-YYYY-NNNNN */
function generate_appointment_code(): string
{
    return sprintf('BNC-%d-%05d', (int) date('Y'), random_int(1, 99999));
}

/** Formato precio MXN */
function fmt_price(float $n): string
{
    return '$' . number_format($n, 2, '.', ',') . ' MXN';
}

/** Old() para mantener inputs en formularios tras error */
function old(string $key, $default = ''): string
{
    return e($_SESSION['old'][$key] ?? $default);
}

function set_old(array $data): void
{
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

/** is_active() para marcar nav links */
function is_active(string $needle): string
{
    return str_contains($_SERVER['REQUEST_URI'] ?? '', $needle) ? 'active' : '';
}

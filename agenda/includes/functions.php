<?php
declare(strict_types=1);

/* ───────── HELPERS GLOBALES ───────── */

/** Escapa HTML — usar SIEMPRE en salidas */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Atajo URL — recibe ruta relativa al app (ej. 'admin/'). */
function url(string $path = ''): string
{
    return APP_BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Devuelve el path component de APP_BASE_URL, sin slash final.
 * Ej.: APP_BASE_URL='https://depilasermexico.com/agenda' → '/agenda'.
 * En raíz: '' (cadena vacía).
 *
 * Cacheado por petición para no parsear en cada llamada.
 */
function app_base_path(): string
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $p = parse_url(APP_BASE_URL, PHP_URL_PATH) ?: '';
    return $cache = rtrim($p, '/');
}

/**
 * Redirección segura. Acepta:
 *   - URL absoluta http(s)://...
 *   - Ruta absoluta de servidor que empieza con '/' (ya válida tal cual)
 *   - Ruta relativa al app (la prefija con APP_BASE_URL)
 *
 * Sanea CR/LF para evitar header injection y nunca emite a un destino inválido.
 */
function redirect(string $path, int $code = 302): never
{
    $path = (string) preg_replace('/[\r\n]+/', '', $path);

    if (preg_match('#^https?://#i', $path)) {
        $target = $path;
    } elseif ($path !== '' && $path[0] === '/' && (strlen($path) < 2 || $path[1] !== '/')) {
        // ruta absoluta del servidor (no protocol-relative '//evil.com')
        $target = $path;
    } else {
        // Evita duplicar el base path si alguien pasa "agenda/admin/" en vez de "admin/".
        $base = ltrim(app_base_path(), '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $target = '/' . ltrim($path, '/');
        } else {
            $target = url($path);
        }
    }

    header('Location: ' . $target, true, $code);
    exit;
}

/**
 * Normaliza una ruta interna contra APP_BASE_URL.
 * Acepta "/agenda/admin/", "agenda/admin/" o "admin/" y devuelve "/agenda/admin/".
 */
function normalize_app_path(string $candidate): ?string
{
    $candidate = trim($candidate);
    if ($candidate === ''
        || preg_match('/[\r\n\t\\\\]/', $candidate)
        || str_starts_with($candidate, '//')
        || preg_match('#^[a-z][a-z0-9+.-]*:#i', $candidate)
    ) {
        return null;
    }

    $base = app_base_path();
    $baseTrim = trim($base, '/');

    if ($candidate[0] === '/') {
        $path = $candidate;
    } elseif ($baseTrim !== '' && ($candidate === $baseTrim || str_starts_with($candidate, $baseTrim . '/'))) {
        $path = '/' . $candidate;
    } else {
        $path = rtrim($base, '/') . '/' . ltrim($candidate, '/');
    }

    if ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/')) {
        return null;
    }

    return $path;
}

/** Devuelve true si el path interno apunta a una pagina publica real de la app. */
function app_route_exists(string $path): bool
{
    $parts = parse_url($path);
    if ($parts === false) return false;

    $route = $parts['path'] ?? '';
    $base = app_base_path();
    if ($base !== '' && str_starts_with($route, $base)) {
        $route = substr($route, strlen($base));
    }
    $route = '/' . ltrim($route, '/');

    $candidateDir = realpath(AGENDA_ROOT . $route);
    if ($candidateDir !== false && is_dir($candidateDir)) {
        $route = rtrim($route, '/') . '/index.php';
    } elseif ($route === '/') {
        $route = '/index.php';
    } elseif (str_ends_with($route, '/')) {
        $route .= 'index.php';
    }

    if (!preg_match('#^/[a-zA-Z0-9_./-]+\.php$#', $route)) {
        return false;
    }

    $full = realpath(AGENDA_ROOT . $route);
    return $full !== false
        && str_starts_with($full, realpath(AGENDA_ROOT))
        && is_file($full);
}

/**
 * Valida y normaliza un parámetro `next` (post-login, post-verificación, etc.)
 * para que sea una ruta segura DENTRO de la app:
 *   - debe empezar con '/'
 *   - no protocol-relative '//evil.com'
 *   - no incluir backslashes ni control chars
 *   - debe estar dentro del base path de la app (ej. /agenda)
 *
 * Devuelve la ruta saneada o null si no es segura.
 */
function safe_next($candidate): ?string
{
    if (!is_string($candidate) || $candidate === '') return null;

    // Decodifica una sola vez por si llega URL-encoded en el querystring
    $cand = $candidate;
    if (str_contains($cand, '%')) {
        $decoded = rawurldecode($cand);
        if ($decoded !== false) $cand = $decoded;
    }
    $cand = trim($cand);

    if (preg_match('#^https?://#i', $cand)) {
        $parts = parse_url($cand);
        $app = parse_url(APP_BASE_URL);
        if ($parts === false || $app === false || strcasecmp($parts['host'] ?? '', $app['host'] ?? '') !== 0) {
            return null;
        }
        $appScheme = strtolower($app['scheme'] ?? 'https');
        if (strtolower($parts['scheme'] ?? '') !== $appScheme) {
            return null;
        }
        $cand = ($parts['path'] ?? '/')
              . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '')
              . (isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '');
    }

    // Bloqueos básicos: control chars, backslashes, vacío
    if ($cand === '' || preg_match('/[\r\n\t\\\\]/', $cand)) return null;

    $cand = normalize_app_path($cand);
    if ($cand === null) return null;

    // Solo path + query + fragment (no host, no esquema)
    $parts = parse_url($cand);
    if ($parts === false) return null;
    if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/') return null;

    // Debe vivir dentro del base path de la app
    $base = app_base_path();
    if ($base !== '' && !str_starts_with($path, $base . '/') && $path !== $base) {
        return null;
    }

    // Reconstruye sin user/pass/host
    $out = $path;
    if (isset($parts['query']) && $parts['query'] !== '')     $out .= '?' . $parts['query'];
    if (isset($parts['fragment']) && $parts['fragment'] !== '') $out .= '#' . $parts['fragment'];

    // No re-disparar el login en bucle si el next apunta a /login.php
    if (preg_match('#/login\.php(\?|$)#', $out) || preg_match('#/logout\.php(\?|$)#', $out)) {
        return null;
    }

    if (!app_route_exists($out)) {
        return null;
    }

    return $out;
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

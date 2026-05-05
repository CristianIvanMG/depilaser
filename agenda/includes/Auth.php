<?php
declare(strict_types=1);

/**
 * Auth — login, logout, registro, control de roles.
 */
final class Auth
{
    public const ROLE_SUPERADMIN   = 'superadmin';
    public const ROLE_ADMIN        = 'admin';
    public const ROLE_PROFESSIONAL = 'professional';
    public const ROLE_CLIENT       = 'cliente';

    /** Idle por defecto si no se puede leer de config: 2 horas. */
    private const DEFAULT_IDLE_SEC = 7200;

    /* ───────── ESTADO ───────── */

    public static function check(): bool
    {
        return isset($_SESSION['uid']);
    }

    public static function user(): ?array
    {
        if (!self::check()) return null;
        if (isset($_SESSION['user_cache'])) return $_SESSION['user_cache'];

        $u = Database::one(
            'SELECT u.id, u.role_id, u.name, u.email, u.phone, u.email_verified, u.active, r.slug AS role_slug
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1',
            [$_SESSION['uid']]
        );
        if (!$u || !$u['active']) {
            self::logout();
            return null;
        }
        $u['email_verified'] = (int) ($u['email_verified'] ?? 0);
        $_SESSION['user_cache'] = $u;
        return $u;
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u['role_slug'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return in_array(self::role(), [self::ROLE_ADMIN, self::ROLE_SUPERADMIN], true);
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === self::ROLE_SUPERADMIN;
    }

    public static function isClient(): bool
    {
        return self::role() === self::ROLE_CLIENT;
    }

    public static function isProfessional(): bool
    {
        return self::role() === self::ROLE_PROFESSIONAL;
    }

    public static function emailVerified(): bool
    {
        $u = self::user();
        return !$u || self::isAdmin() || (int) ($u['email_verified'] ?? 0) === 1;
    }

    /* ───────── LOGIN / LOGOUT ───────── */

    /**
     * Intenta autenticar. Retorna el array de usuario si OK, o null si falla.
     * Considera rate limiting.
     */
    public static function attempt(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate limit: 5 intentos en 10 min por IP
        if (self::tooManyAttempts($ip)) {
            return null;
        }

        $u = Database::one(
            'SELECT u.*, r.slug AS role_slug FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.email = ? LIMIT 1',
            [$email]
        );

        $ok = $u && $u['active'] && password_verify($password, $u['password_hash']);

        Database::exec(
            'INSERT INTO login_attempts (ip, email, success) VALUES (?, ?, ?)',
            [$ip, $email, $ok ? 1 : 0]
        );

        if (!$ok) return null;

        // Re-hash si el algoritmo cambió
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Database::exec(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $u['id']]
            );
        }

        // Sesión
        session_regenerate_id(true);
        $_SESSION['uid']        = (int) $u['id'];
        $_SESSION['user_cache'] = [
            'id'        => (int) $u['id'],
            'role_id'   => (int) $u['role_id'],
            'name'      => $u['name'],
            'email'     => $u['email'],
            'phone'     => $u['phone'],
            'email_verified' => (int) ($u['email_verified'] ?? 0),
            'active'    => (int) $u['active'],
            'role_slug' => $u['role_slug'],
        ];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_at']      = time();

        // Limpiar cualquier next o flag obsoleto que haya quedado de sesiones previas
        unset($_SESSION['intended_next']);

        Database::exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
        self::audit('login', 'user', (int) $u['id']);

        return $_SESSION['user_cache'];
    }

    public static function logout(): void
    {
        if (self::check()) {
            self::audit('logout', 'user', (int) $_SESSION['uid']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /* ───────── REGISTRO (cliente) ───────── */

    /**
     * Crea un cliente nuevo. Retorna ['ok'=>bool, 'errors'=>[], 'user_id'=>?int].
     *
     * Acepta los 3 campos opcionales de fase 5 si vienen en $data:
     *   birth_date (Y-m-d), gender (slug ClientProfile), address (texto).
     */
    public static function register(array $data): array
    {
        $errors = Validator::registration($data);

        // Auto-migración fase 5 + validación de campos opcionales
        ClientProfile::ensureSchema();
        $identity = ClientProfile::normalizeName($data);
        $extra = ClientProfile::normalize($data);
        $extraErrors = ClientProfile::validate($extra);
        if ($extraErrors) {
            $errors = array_merge($errors, $extraErrors);
        }

        if ($errors) return ['ok' => false, 'errors' => $errors, 'user_id' => null];

        // ¿Email ya existe?
        if (Database::one('SELECT id FROM users WHERE email = ? LIMIT 1', [strtolower($data['email'])])) {
            return ['ok' => false, 'errors' => ['email' => 'Este correo ya está registrado.'], 'user_id' => null];
        }

        $roleId = (int) Database::one("SELECT id FROM roles WHERE slug = 'cliente' LIMIT 1")['id'];

        EmailVerification::ensureSchema();

        // INSERT dinámico — solo agrega columnas opcionales que ya existan en la tabla
        $frag = ClientProfile::sqlFragment($extra);
        $nameFrag = ClientProfile::nameSqlFragment($identity);
        $nameCols = $nameFrag['cols'] ? ', ' . implode(', ', $nameFrag['cols']) : '';
        $namePh   = $nameFrag['cols'] ? ', ' . implode(', ', array_fill(0, count($nameFrag['cols']), '?')) : '';
        $extraCols = $frag['cols'] ? ', ' . implode(', ', $frag['cols']) : '';
        $extraPh   = $frag['cols'] ? ', ' . $frag['placeholders'] : '';

        $params = [
            $roleId,
            $identity['name'],
            strtolower(trim($data['email'])),
            preg_replace('/\D+/', '', $data['phone'] ?? ''),
            password_hash($data['password'], PASSWORD_DEFAULT),
        ];
        $params = array_merge($params, $nameFrag['values']);
        $params = array_merge($params, $frag['values']);

        Database::exec(
            'INSERT INTO users (role_id, name, email, phone, password_hash, email_verified, active'
                . $nameCols . $extraCols .
            ') VALUES (?, ?, ?, ?, ?, 0, 1' . $namePh . $extraPh . ')',
            $params
        );
        $uid = Database::lastId();
        self::audit('register', 'user', $uid);
        $verification = EmailVerification::issueAndSend($uid, true);

        return ['ok' => true, 'errors' => [], 'user_id' => $uid, 'verification' => $verification];
    }

    /* ───────── GUARDS (proteger páginas) ───────── */

    /** Tope de inactividad en segundos (lee config['session']['lifetime']). */
    public static function idleLimit(): int
    {
        global $CONFIG;
        $sec = (int) ($CONFIG['session']['lifetime'] ?? self::DEFAULT_IDLE_SEC);
        return $sec > 60 ? $sec : self::DEFAULT_IDLE_SEC;
    }

    /**
     * Página por defecto a la que redirigir tras login según el rol.
     * Devuelve URL absoluta lista para `redirect()`.
     */
    public static function defaultLanding(?string $roleSlug): string
    {
        return match ($roleSlug) {
            self::ROLE_SUPERADMIN, self::ROLE_ADMIN => url('admin/'),
            self::ROLE_PROFESSIONAL                 => url('admin/calendario.php'),
            self::ROLE_CLIENT                       => url('index.php'),
            default                                 => url(''),
        };
    }

    /**
     * Caduca la sesión por inactividad: audita, drop de identidad,
     * rota id de sesión y deja un flash claro para mostrar tras el login.
     * NO destruye la cookie — necesitamos la sesión viva para entregar el flash.
     */
    private static function expireSession(): void
    {
        try { self::audit('session_expired', 'user', (int) ($_SESSION['uid'] ?? 0)); } catch (\Throwable $e) {}
        unset(
            $_SESSION['uid'],
            $_SESSION['user_cache'],
            $_SESSION['last_activity'],
            $_SESSION['login_at'],
            $_SESSION['old']
        );
        // ID nuevo para evitar fixation tras la expiración
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        flash('warning', 'Tu sesión expiró por inactividad. Por favor inicia sesión nuevamente.');
    }

    /**
     * Si la sesión sigue viva, comprueba inactividad. Devuelve true si
     * la sesión fue expirada en esta llamada.
     */
    private static function enforceIdleTimeout(): bool
    {
        if (!self::check()) return false;
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($last <= 0) {
            $_SESSION['last_activity'] = time();
            return false;
        }
        if ((time() - $last) > self::idleLimit()) {
            self::expireSession();
            return true;
        }
        $_SESSION['last_activity'] = time();
        return false;
    }

    /** Permite que pantallas públicas como login limpien sesiones viejas. */
    public static function enforceSessionTimeout(): bool
    {
        return self::enforceIdleTimeout();
    }

    /** Construye `?next=` saneado a partir del REQUEST_URI actual. */
    private static function intendedNextParam(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $next = safe_next($uri);
        return $next ? '?next=' . urlencode($next) : '';
    }

    /** Redirección segura al login, conservando next saneado. */
    private static function bounceToLogin(): never
    {
        // Solo capturamos el next en GETs idempotentes — no tiene sentido
        // bouncear un POST (perdería el body) ni un endpoint API.
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $params = $method === 'GET' ? self::intendedNextParam() : '';
        header('Location: ' . url('login.php') . $params, true, 302);
        exit;
    }

    /** Llamar al inicio de páginas que requieren login. */
    public static function requireLogin(): void
    {
        // 1) sesión viva pero inactiva → expirar con flash
        if (self::enforceIdleTimeout()) {
            self::bounceToLogin();
        }
        // 2) nunca logueado → flash neutro y redirección
        if (!self::check()) {
            // Solo añadimos el flash si el usuario INTENTÓ acceder a algo,
            // no en visitas frescas a /login.php (que ya lo manejamos arriba).
            $hasFlash = !empty($_SESSION['flash']);
            if (!$hasFlash) {
                flash('info', 'Necesitas iniciar sesión para continuar.');
            }
            self::bounceToLogin();
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            die('Acceso restringido.');
        }
    }

    public static function requireSuperAdmin(): void
    {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            http_response_code(403);
            die('Acceso restringido (solo super-admin).');
        }
    }

    public static function requireVerifiedEmail(): void
    {
        self::requireLogin();
        if (self::isClient() && !self::emailVerified()) {
            flash('warning', 'Confirma tu correo electrónico para continuar.');
            $next = safe_next($_SERVER['REQUEST_URI'] ?? '');
            redirect('verificar-email.php' . ($next ? '?next=' . urlencode($next) : ''));
        }
    }

    /* ───────── HELPERS ───────── */

    private static function tooManyAttempts(string $ip): bool
    {
        $row = Database::one(
            'SELECT COUNT(*) AS n FROM login_attempts
             WHERE ip = ? AND success = 0 AND created_at > (NOW() - INTERVAL 10 MINUTE)',
            [$ip]
        );
        return ((int) ($row['n'] ?? 0)) >= 5;
    }

    public static function audit(string $action, ?string $entity = null, ?int $entityId = null, ?array $payload = null): void
    {
        try {
            Database::exec(
                'INSERT INTO audit_log (user_id, action, entity_type, entity_id, payload, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $_SESSION['uid'] ?? null,
                    $action,
                    $entity,
                    $entityId,
                    $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]
            );
        } catch (Throwable $e) {
            error_log('[audit] ' . $e->getMessage());
        }
    }
}

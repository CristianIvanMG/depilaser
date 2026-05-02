<?php
declare(strict_types=1);

/**
 * Auth — login, logout, registro, control de roles.
 */
final class Auth
{
    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_ADMIN      = 'admin';
    public const ROLE_CLIENT     = 'cliente';

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
            'SELECT u.id, u.role_id, u.name, u.email, u.phone, u.active, r.slug AS role_slug
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1',
            [$_SESSION['uid']]
        );
        if (!$u || !$u['active']) {
            self::logout();
            return null;
        }
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
            'active'    => (int) $u['active'],
            'role_slug' => $u['role_slug'],
        ];

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
     */
    public static function register(array $data): array
    {
        $errors = Validator::registration($data);
        if ($errors) return ['ok' => false, 'errors' => $errors, 'user_id' => null];

        // ¿Email ya existe?
        if (Database::one('SELECT id FROM users WHERE email = ? LIMIT 1', [strtolower($data['email'])])) {
            return ['ok' => false, 'errors' => ['email' => 'Este correo ya está registrado.'], 'user_id' => null];
        }

        $roleId = (int) Database::one("SELECT id FROM roles WHERE slug = 'cliente' LIMIT 1")['id'];

        Database::exec(
            'INSERT INTO users (role_id, name, email, phone, password_hash, active)
             VALUES (?, ?, ?, ?, ?, 1)',
            [
                $roleId,
                trim($data['name']),
                strtolower(trim($data['email'])),
                preg_replace('/\D+/', '', $data['phone'] ?? ''),
                password_hash($data['password'], PASSWORD_DEFAULT),
            ]
        );
        $uid = Database::lastId();
        self::audit('register', 'user', $uid);

        return ['ok' => true, 'errors' => [], 'user_id' => $uid];
    }

    /* ───────── GUARDS (proteger páginas) ───────── */

    /** Llamar al inicio de páginas que requieren login */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            $_SESSION['flash_error'] = 'Necesitas iniciar sesión para continuar.';
            header('Location: ' . APP_BASE_URL . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
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

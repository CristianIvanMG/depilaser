<?php
declare(strict_types=1);

final class RewardsService
{
    private const TOKEN_TTL_SECONDS = 43200; // 12 horas
    private const DUPLICATE_WINDOW_MINUTES = 30;

    public static function ensureSchema(): void
    {
        Database::exec(
            "CREATE TABLE IF NOT EXISTS reward_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                attendances_required INT NOT NULL DEFAULT 10,
                promotion_type VARCHAR(80) NOT NULL DEFAULT 'cliente_frecuente',
                description TEXT NULL,
                validity_days INT NULL,
                auto_reset TINYINT(1) NOT NULL DEFAULT 1,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_reward_configs_active (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        Database::exec(
            "CREATE TABLE IF NOT EXISTS attendance_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                branch_id INT NULL,
                scanned_by_id INT NOT NULL,
                scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                source VARCHAR(40) NOT NULL DEFAULT 'qr',
                token_hash CHAR(64) NULL,
                notes VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_attendance_client_date (client_id, scanned_at),
                INDEX idx_attendance_branch_date (branch_id, scanned_at),
                INDEX idx_attendance_admin (scanned_by_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        Database::exec(
            "CREATE TABLE IF NOT EXISTS client_rewards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                config_id INT NULL,
                type VARCHAR(80) NOT NULL DEFAULT 'cliente_frecuente',
                description TEXT NOT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                status ENUM('pendiente','usado','cancelado') NOT NULL DEFAULT 'pendiente',
                used_at DATETIME NULL,
                created_by_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client_rewards_client (client_id, status),
                INDEX idx_client_rewards_config (config_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        Database::exec(
            "CREATE TABLE IF NOT EXISTS reward_counter_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                config_id INT NULL,
                reset_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                admin_id INT NOT NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reward_resets_client (client_id, reset_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        Database::exec(
            "CREATE TABLE IF NOT EXISTS reward_counter_adjustments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                config_id INT NULL,
                delta INT NOT NULL,
                reason VARCHAR(255) NULL,
                admin_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reward_adjustments_client (client_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $existing = Database::one('SELECT id FROM reward_configs LIMIT 1');
        if (!$existing) {
            Database::exec(
                "INSERT INTO reward_configs
                 (name, attendances_required, promotion_type, description, validity_days, auto_reset, active)
                 VALUES ('Cliente frecuente', 10, 'cliente_frecuente', 'Promocion especial por asistencia constante registrada con QR.', 60, 1, 1)"
            );
        }
    }

    public static function activeConfig(): ?array
    {
        self::ensureSchema();
        return Database::one('SELECT * FROM reward_configs WHERE active = 1 ORDER BY id DESC LIMIT 1');
    }

    public static function qrPayloadForUser(array $user): array
    {
        $issuedAt = time();
        $base = [
            'cliente_id' => (int) $user['id'],
            'nombre' => (string) ($user['name'] ?? ''),
            'timestamp' => date('c', $issuedAt),
            'iat' => $issuedAt,
        ];
        $base['hash'] = self::signPayload($base);
        return $base;
    }

    public static function qrTokenForUser(array $user): string
    {
        return self::base64UrlEncode(json_encode(self::qrPayloadForUser($user), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function validateToken(string $token): array
    {
        $json = self::base64UrlDecode(trim($token));
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'QR invalido o ilegible.'];
        }

        $clientId = (int) ($payload['cliente_id'] ?? 0);
        $hash = (string) ($payload['hash'] ?? '');
        $iat = (int) ($payload['iat'] ?? 0);
        if ($clientId <= 0 || $hash === '' || $iat <= 0) {
            return ['ok' => false, 'error' => 'El QR no contiene datos completos.'];
        }
        if (abs(time() - $iat) > self::TOKEN_TTL_SECONDS) {
            return ['ok' => false, 'error' => 'El QR expiro. Pide al cliente abrir de nuevo su perfil.'];
        }

        $unsigned = $payload;
        unset($unsigned['hash']);
        if (!hash_equals(self::signPayload($unsigned), $hash)) {
            return ['ok' => false, 'error' => 'La firma del QR no es valida.'];
        }

        $client = self::clientById($clientId);
        if (!$client) {
            return ['ok' => false, 'error' => 'Cliente no encontrado o inactivo.'];
        }

        return ['ok' => true, 'payload' => $payload, 'client' => $client];
    }

    public static function registerAttendance(string $token, int $adminId, ?int $branchId = null): array
    {
        self::ensureSchema();
        $validation = self::validateToken($token);
        if (empty($validation['ok'])) {
            return $validation;
        }

        $client = $validation['client'];
        $clientId = (int) $client['id'];
        $recent = Database::one(
            "SELECT id, scanned_at FROM attendance_logs
             WHERE client_id = ? AND scanned_at >= DATE_SUB(NOW(), INTERVAL " . self::DUPLICATE_WINDOW_MINUTES . " MINUTE)
             ORDER BY scanned_at DESC LIMIT 1",
            [$clientId]
        );
        if ($recent) {
            return [
                'ok' => false,
                'duplicate' => true,
                'error' => 'Este cliente ya fue escaneado recientemente.',
                'client' => $client,
                'last_scan' => $recent['scanned_at'],
            ];
        }

        Database::exec(
            'INSERT INTO attendance_logs (client_id, branch_id, scanned_by_id, token_hash) VALUES (?, ?, ?, ?)',
            [$clientId, $branchId ?: null, $adminId, hash('sha256', $token)]
        );

        $reward = self::maybeGenerateReward($clientId, $adminId);
        return [
            'ok' => true,
            'client' => $client,
            'progress' => self::progressForClient($clientId),
            'reward' => $reward,
            'attendance' => Database::lastId(),
        ];
    }

    public static function progressForClient(int $clientId): array
    {
        self::ensureSchema();
        $config = self::activeConfig();
        if (!$config) {
            return ['config' => null, 'current' => 0, 'required' => 0, 'percent' => 0, 'remaining' => 0];
        }
        $baseline = self::baselineDate($clientId, (int) $config['id']);
        $params = [$clientId];
        $where = 'client_id = ?';
        if ($baseline) {
            $where .= ' AND scanned_at > ?';
            $params[] = $baseline;
        }
        $visits = (int) (Database::one("SELECT COUNT(*) AS n FROM attendance_logs WHERE {$where}", $params)['n'] ?? 0);
        $adjustments = (int) (Database::one(
            'SELECT COALESCE(SUM(delta), 0) AS n FROM reward_counter_adjustments WHERE client_id = ? AND (config_id = ? OR config_id IS NULL)' . ($baseline ? ' AND created_at > ?' : ''),
            $baseline ? [$clientId, (int) $config['id'], $baseline] : [$clientId, (int) $config['id']]
        )['n'] ?? 0);
        $required = max(1, (int) $config['attendances_required']);
        $current = max(0, $visits + $adjustments);

        return [
            'config' => $config,
            'current' => $current,
            'required' => $required,
            'percent' => min(100, (int) round(($current / $required) * 100)),
            'remaining' => max(0, $required - $current),
        ];
    }

    public static function rewardsForClient(int $clientId, int $limit = 10): array
    {
        self::ensureSchema();
        return Database::all(
            'SELECT * FROM client_rewards WHERE client_id = ? ORDER BY generated_at DESC LIMIT ' . max(1, $limit),
            [$clientId]
        );
    }

    public static function attendancesForClient(int $clientId, int $limit = 10): array
    {
        self::ensureSchema();
        return Database::all(
            "SELECT al.*, b.name AS branch_name, u.name AS admin_name
             FROM attendance_logs al
             LEFT JOIN branches b ON b.id = al.branch_id
             JOIN users u ON u.id = al.scanned_by_id
             WHERE al.client_id = ?
             ORDER BY al.scanned_at DESC
             LIMIT " . max(1, $limit),
            [$clientId]
        );
    }

    public static function dashboardClients(string $q = '', int $limit = 80): array
    {
        self::ensureSchema();
        $params = [];
        $where = "r.slug = 'cliente'";
        if ($q !== '') {
            $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $rows = Database::all(
            "SELECT u.id, u.name, u.email, u.phone, u.active,
                    COUNT(DISTINCT al.id) AS total_attendances,
                    COUNT(DISTINCT CASE WHEN cr.status = 'pendiente' THEN cr.id END) AS pending_rewards,
                    COUNT(DISTINCT cr.id) AS total_rewards
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN attendance_logs al ON al.client_id = u.id
             LEFT JOIN client_rewards cr ON cr.client_id = u.id
             WHERE {$where}
             GROUP BY u.id, u.name, u.email, u.phone, u.active
             ORDER BY MAX(al.scanned_at) DESC, u.name ASC
             LIMIT " . max(10, $limit),
            $params
        );
        foreach ($rows as &$row) {
            $row['progress'] = self::progressForClient((int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    public static function recentAttendances(int $limit = 50): array
    {
        self::ensureSchema();
        return Database::all(
            "SELECT al.*, c.name AS client_name, c.phone AS client_phone, b.name AS branch_name, a.name AS admin_name
             FROM attendance_logs al
             JOIN users c ON c.id = al.client_id
             JOIN users a ON a.id = al.scanned_by_id
             LEFT JOIN branches b ON b.id = al.branch_id
             ORDER BY al.scanned_at DESC
             LIMIT " . max(1, $limit)
        );
    }

    public static function saveConfig(array $data): void
    {
        self::ensureSchema();
        Database::exec('UPDATE reward_configs SET active = 0 WHERE active = 1');
        Database::exec(
            "INSERT INTO reward_configs (name, attendances_required, promotion_type, description, validity_days, auto_reset, active)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            [
                trim((string) ($data['name'] ?? 'Cliente frecuente')) ?: 'Cliente frecuente',
                max(1, (int) ($data['attendances_required'] ?? 10)),
                trim((string) ($data['promotion_type'] ?? 'cliente_frecuente')) ?: 'cliente_frecuente',
                trim((string) ($data['description'] ?? 'Promocion especial por asistencia constante registrada con QR.')),
                (int) ($data['validity_days'] ?? 60) ?: null,
                !empty($data['auto_reset']) ? 1 : 0,
            ]
        );
    }

    public static function resetCounter(int $clientId, int $adminId, string $reason = ''): void
    {
        $config = self::activeConfig();
        Database::exec(
            'INSERT INTO reward_counter_resets (client_id, config_id, admin_id, reason) VALUES (?, ?, ?, ?)',
            [$clientId, $config['id'] ?? null, $adminId, $reason ?: 'Reset manual']
        );
    }

    public static function adjustCounter(int $clientId, int $delta, int $adminId, string $reason = ''): void
    {
        $config = self::activeConfig();
        Database::exec(
            'INSERT INTO reward_counter_adjustments (client_id, config_id, delta, admin_id, reason) VALUES (?, ?, ?, ?, ?)',
            [$clientId, $config['id'] ?? null, $delta, $adminId, $reason ?: 'Ajuste manual']
        );
    }

    public static function forceReward(int $clientId, int $adminId): array
    {
        return self::createReward($clientId, $adminId, self::activeConfig());
    }

    public static function deleteAttendance(int $attendanceId): void
    {
        Database::exec('DELETE FROM attendance_logs WHERE id = ?', [$attendanceId]);
    }

    public static function updateRewardStatus(int $rewardId, string $status): void
    {
        if (!in_array($status, ['pendiente', 'usado', 'cancelado'], true)) {
            throw new InvalidArgumentException('Estado de recompensa no valido.');
        }
        $usedSql = $status === 'usado' ? ', used_at = NOW()' : ', used_at = NULL';
        Database::exec(
            "UPDATE client_rewards SET status = ? {$usedSql} WHERE id = ?",
            [$status, $rewardId]
        );
    }

    public static function recentRewards(int $limit = 40): array
    {
        self::ensureSchema();
        return Database::all(
            "SELECT cr.*, u.name AS client_name, u.phone AS client_phone
             FROM client_rewards cr
             JOIN users u ON u.id = cr.client_id
             ORDER BY cr.generated_at DESC
             LIMIT " . max(1, $limit)
        );
    }

    private static function maybeGenerateReward(int $clientId, int $adminId): ?array
    {
        $progress = self::progressForClient($clientId);
        $config = $progress['config'];
        if (!$config || $progress['current'] < $progress['required']) {
            return null;
        }
        $reward = self::createReward($clientId, $adminId, $config);
        if (!empty($config['auto_reset'])) {
            self::resetCounter($clientId, $adminId, 'Reset automatico por recompensa generada');
        }
        return $reward;
    }

    private static function createReward(int $clientId, int $adminId, ?array $config): array
    {
        $description = trim((string) ($config['description'] ?? 'Promocion de cliente frecuente'));
        $validity = (int) ($config['validity_days'] ?? 0);
        $expiresSql = $validity > 0 ? 'DATE_ADD(NOW(), INTERVAL ' . $validity . ' DAY)' : 'NULL';
        Database::exec(
            "INSERT INTO client_rewards (client_id, config_id, type, description, expires_at, created_by_id)
             VALUES (?, ?, ?, ?, {$expiresSql}, ?)",
            [
                $clientId,
                $config['id'] ?? null,
                $config['promotion_type'] ?? 'cliente_frecuente',
                $description,
                $adminId,
            ]
        );
        return Database::one('SELECT * FROM client_rewards WHERE id = ? LIMIT 1', [Database::lastId()]) ?? [];
    }

    private static function baselineDate(int $clientId, int $configId): ?string
    {
        $lastReward = Database::one(
            'SELECT generated_at AS dt FROM client_rewards WHERE client_id = ? AND (config_id = ? OR config_id IS NULL) ORDER BY generated_at DESC LIMIT 1',
            [$clientId, $configId]
        );
        $lastReset = Database::one(
            'SELECT reset_at AS dt FROM reward_counter_resets WHERE client_id = ? AND (config_id = ? OR config_id IS NULL) ORDER BY reset_at DESC LIMIT 1',
            [$clientId, $configId]
        );
        $dates = array_filter([$lastReward['dt'] ?? null, $lastReset['dt'] ?? null]);
        rsort($dates);
        return $dates[0] ?? null;
    }

    private static function clientById(int $clientId): ?array
    {
        return Database::one(
            "SELECT u.id, u.name, u.email, u.phone, u.active
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.active = 1 AND r.slug = 'cliente'
             LIMIT 1",
            [$clientId]
        );
    }

    private static function signPayload(array $payload): string
    {
        unset($payload['hash']);
        ksort($payload);
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::privateKey());
    }

    private static function privateKey(): string
    {
        $config = is_file(AGENDA_ROOT . '/config/config.php') ? require AGENDA_ROOT . '/config/config.php' : [];
        $secret = (string) ($config['security']['qr_secret'] ?? '');
        if ($secret !== '') {
            return $secret;
        }
        return hash('sha256', APP_BASE_URL . '|' . AGENDA_ROOT . '|bellanick-rewards');
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 ? strlen($value) + 4 - strlen($value) % 4 : strlen($value), '=', STR_PAD_RIGHT);
        return base64_decode($padded, true) ?: '';
    }
}

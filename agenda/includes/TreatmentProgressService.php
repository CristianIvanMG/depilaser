<?php
declare(strict_types=1);

final class TreatmentProgressService
{
    public const LEVELS = [
        'low' => 'Bajo',
        'medium' => 'Medio',
        'high' => 'Alto',
    ];

    public static function ensureSchema(): void
    {
        Database::exec(
            "CREATE TABLE IF NOT EXISTS treatment_progress (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                appointment_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                progress_level ENUM('low','medium','high') NOT NULL,
                registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_progress_appointment (appointment_id),
                INDEX idx_progress_user (user_id, registered_at),
                INDEX idx_progress_level (progress_level, registered_at),
                CONSTRAINT fk_progress_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
                CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function eligibleForUser(int $userId, int $limit = 5): array
    {
        self::ensureSchema();

        return Database::all(
            "SELECT a.id, a.code, a.start_at, a.end_at, a.attended_at,
                    s.name AS service_name,
                    b.name AS branch_name, b.gmaps_url,
                    DATE_ADD(COALESCE(a.attended_at, a.end_at, a.start_at), INTERVAL 21 DAY) AS available_at
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             LEFT JOIN treatment_progress tp ON tp.appointment_id = a.id
             WHERE a.user_id = ?
               AND st.slug = 'atendida'
               AND tp.id IS NULL
               AND DATE_ADD(COALESCE(a.attended_at, a.end_at, a.start_at), INTERVAL 21 DAY) <= NOW()
             ORDER BY a.start_at DESC
             LIMIT {$limit}",
            [$userId]
        );
    }

    public static function pendingSoonForUser(int $userId, int $limit = 3): array
    {
        self::ensureSchema();

        return Database::all(
            "SELECT a.id, a.code, a.start_at,
                    s.name AS service_name,
                    b.name AS branch_name,
                    DATE_ADD(COALESCE(a.attended_at, a.end_at, a.start_at), INTERVAL 21 DAY) AS available_at
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             LEFT JOIN treatment_progress tp ON tp.appointment_id = a.id
             WHERE a.user_id = ?
               AND st.slug = 'atendida'
               AND tp.id IS NULL
               AND DATE_ADD(COALESCE(a.attended_at, a.end_at, a.start_at), INTERVAL 21 DAY) > NOW()
             ORDER BY available_at ASC
             LIMIT {$limit}",
            [$userId]
        );
    }

    public static function recentForUser(int $userId, int $limit = 5): array
    {
        self::ensureSchema();

        return Database::all(
            "SELECT tp.*, a.code, a.start_at,
                    s.name AS service_name,
                    b.name AS branch_name, b.gmaps_url
             FROM treatment_progress tp
             JOIN appointments a ON a.id = tp.appointment_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             WHERE tp.user_id = ?
             ORDER BY tp.registered_at DESC
             LIMIT {$limit}",
            [$userId]
        );
    }

    public static function submit(int $userId, int $appointmentId, string $level): array
    {
        self::ensureSchema();

        if (!isset(self::LEVELS[$level])) {
            return ['ok' => false, 'error' => 'Selecciona un nivel de avance válido.'];
        }

        $appointment = Database::one(
            "SELECT a.id, a.user_id, a.code, a.start_at, a.end_at, a.attended_at,
                    st.slug AS status_slug,
                    b.gmaps_url, b.name AS branch_name
             FROM appointments a
             JOIN appointment_statuses st ON st.id = a.status_id
             JOIN branches b ON b.id = a.branch_id
             WHERE a.id = ? AND a.user_id = ? LIMIT 1",
            [$appointmentId, $userId]
        );

        if (!$appointment) {
            return ['ok' => false, 'error' => 'No encontramos esa cita en tu cuenta.'];
        }
        if ($appointment['status_slug'] !== 'atendida') {
            return ['ok' => false, 'error' => 'El seguimiento se activa después de una cita atendida.'];
        }

        $baseDate = (string) ($appointment['attended_at'] ?: $appointment['end_at'] ?: $appointment['start_at']);
        if (strtotime($baseDate . ' +21 days') > time()) {
            return ['ok' => false, 'error' => 'El seguimiento estará disponible 3 semanas después de tu cita.'];
        }
        if (Database::one('SELECT id FROM treatment_progress WHERE appointment_id = ? LIMIT 1', [$appointmentId])) {
            return ['ok' => false, 'error' => 'El avance de esta cita ya fue registrado.'];
        }

        try {
            Database::exec(
                'INSERT INTO treatment_progress (appointment_id, user_id, progress_level) VALUES (?, ?, ?)',
                [$appointmentId, $userId, $level]
            );
            $id = Database::lastId();
            Auth::audit('treatment_progress_create', 'appointment', $appointmentId, [
                'progress_id' => $id,
                'level' => $level,
            ]);

            return [
                'ok' => true,
                'id' => $id,
                'level' => $level,
                'review_url' => $level === 'high' ? (string) ($appointment['gmaps_url'] ?? '') : '',
                'branch_name' => (string) ($appointment['branch_name'] ?? ''),
            ];
        } catch (Throwable $e) {
            error_log('[treatment-progress] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No fue posible guardar tu avance. Intenta de nuevo.'];
        }
    }

    public static function adminRows(array $filters = []): array
    {
        self::ensureSchema();

        $where = ['1=1'];
        $params = [];
        if (!empty($filters['level']) && isset(self::LEVELS[$filters['level']])) {
            $where[] = 'tp.progress_level = ?';
            $params[] = (string) $filters['level'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'a.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'tp.registered_at >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'tp.registered_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = (string) $filters['to'];
        }

        return Database::all(
            "SELECT tp.*, u.name AS client_name, u.email AS client_email, u.phone AS client_phone,
                    a.code, a.start_at,
                    s.name AS service_name,
                    b.name AS branch_name
             FROM treatment_progress tp
             JOIN users u ON u.id = tp.user_id
             JOIN appointments a ON a.id = tp.appointment_id
             JOIN services s ON s.id = a.service_id
             JOIN branches b ON b.id = a.branch_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY tp.registered_at DESC
             LIMIT 300",
            $params
        );
    }
}

<?php
declare(strict_types=1);

/**
 * ClientProfile — utilidades para los campos extendidos del cliente
 * (fecha de nacimiento, sexo, dirección).
 *
 * - ensureSchema(): aplica fase 5 si falta (idempotente)
 * - genderOptions(): lista controlada slug => label
 * - genderLabel(slug): traducción para mostrar
 * - normalize(array $input): saneo de los 3 campos a partir de $_POST
 * - validate(array $clean): errores por campo (solo para los 3 nuevos)
 * - hasColumn(name): atajo para condicionar SELECT/UPDATE
 */
final class ClientProfile
{
    public const GENDERS = [
        'femenino'          => 'Femenino',
        'masculino'         => 'Masculino',
        'no_binario'        => 'No binario',
        'prefiero_no_decir' => 'Prefiero no decir',
    ];

    /** Opciones controladas para selects (slug => label). */
    public static function genderOptions(): array
    {
        return self::GENDERS;
    }

    /** Etiqueta legible o cadena vacía si no aplica. */
    public static function genderLabel(?string $slug): string
    {
        if (!$slug) return '';
        return self::GENDERS[$slug] ?? '';
    }

    /** Auto-migración fase 5. Idempotente. */
    public static function ensureSchema(): void
    {
        self::ensureNameSchema();
        $cols = [
            'birth_date' => 'DATE NULL',
            'gender'     => "ENUM('femenino','masculino','no_binario','prefiero_no_decir') NULL",
            'address'    => 'VARCHAR(255) NULL',
        ];
        foreach ($cols as $name => $def) {
            if (!self::hasColumn($name)) {
                try {
                    Database::exec("ALTER TABLE users ADD COLUMN {$name} {$def}");
                } catch (\Throwable $e) {
                    error_log('[client-profile] no pude crear ' . $name . ': ' . $e->getMessage());
                }
            }
        }
    }

    public static function ensureNameSchema(): void
    {
        $created = false;
        $nameCols = self::nameColumns();
        if (!$nameCols['first']) {
            try {
                Database::exec('ALTER TABLE users ADD COLUMN first_name VARCHAR(120) NULL AFTER name');
                $created = true;
            } catch (\Throwable $e) {
                error_log('[client-profile] no pude crear first_name: ' . $e->getMessage());
            }
        }
        if (!$nameCols['last']) {
            $afterFirst = $nameCols['first'] ?: 'name';
            try {
                Database::exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(160) NULL AFTER {$afterFirst}");
                $created = true;
            } catch (\Throwable $e) {
                error_log('[client-profile] no pude crear last_name: ' . $e->getMessage());
            }
        }
        $nameCols = self::nameColumns();
        $firstCol = $nameCols['first'] ?: 'first_name';
        $lastCol = $nameCols['last'] ?: 'last_name';
        if ($created || $nameCols['first'] || $nameCols['last']) {
            try {
                Database::exec(
                    "UPDATE users
                     SET {$firstCol} = CASE
                            WHEN ({$firstCol} IS NULL OR TRIM({$firstCol}) = '') AND name IS NOT NULL
                            THEN TRIM(SUBSTRING_INDEX(name, ' ', 1))
                            ELSE {$firstCol}
                         END,
                         {$lastCol} = CASE
                            WHEN ({$lastCol} IS NULL OR TRIM({$lastCol}) = '') AND name IS NOT NULL AND LOCATE(' ', TRIM(name)) > 0
                            THEN TRIM(SUBSTRING(TRIM(name), LOCATE(' ', TRIM(name)) + 1))
                            ELSE {$lastCol}
                         END
                     WHERE name IS NOT NULL
                       AND (({$firstCol} IS NULL OR TRIM({$firstCol}) = '') OR ({$lastCol} IS NULL OR TRIM({$lastCol}) = ''))"
                );
            } catch (\Throwable $e) {
                error_log('[client-profile] no pude normalizar nombres: ' . $e->getMessage());
            }
        }
    }

    /** ¿Existe la columna en users? Cachea por petición. */
    public static function hasColumn(string $col): bool
    {
        static $cache = [];
        if (($cache[$col] ?? false) === true) return true;
        try {
            $row = Database::one(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                ['users', $col]
            );
            return $cache[$col] = (bool) $row;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Detecta las columnas de nombre y apellidos disponibles en users.
     * Mantiene compatibilidad con distintas migraciones posibles.
     *
     * @return array{first: ?string, last: ?string}
     */
    public static function nameColumns(): array
    {
        $firstCandidates = ['first_name', 'nombres', 'nombre', 'given_name'];
        $lastCandidates  = ['last_name', 'apellidos', 'apellido', 'family_name'];

        $first = null;
        foreach ($firstCandidates as $candidate) {
            if (self::hasColumn($candidate)) {
                $first = $candidate;
                break;
            }
        }

        $last = null;
        foreach ($lastCandidates as $candidate) {
            if (self::hasColumn($candidate)) {
                $last = $candidate;
                break;
            }
        }

        return ['first' => $first, 'last' => $last];
    }

    /**
     * Normaliza nombres y apellidos, conservando users.name como nombre completo
     * para no romper calendarios, correos, pagos ni reportes existentes.
     */
    public static function normalizeName(array $input): array
    {
        $first = trim((string) ($input['first_name'] ?? $input['client_first_name'] ?? ''));
        $last  = trim((string) ($input['last_name'] ?? $input['client_last_name'] ?? ''));
        $full  = trim((string) ($input['name'] ?? $input['client_name'] ?? ''));

        if ($first === '' && $last === '' && $full !== '') {
            $parts = preg_split('/\s+/', $full) ?: [];
            if (count($parts) > 1) {
                $first = array_shift($parts);
                $last = implode(' ', $parts);
            } else {
                $first = $full;
            }
        }

        $first = preg_replace('/\s+/', ' ', $first) ?? $first;
        $last = preg_replace('/\s+/', ' ', $last) ?? $last;
        $fullName = trim($first . ' ' . $last);

        return [
            'first_name' => $first,
            'last_name' => $last,
            'name' => $fullName !== '' ? $fullName : $full,
        ];
    }

    /**
     * Fragmentos SQL para guardar nombre/apellidos solo si existen las columnas.
     *
     * @return array{set: string, cols: string[], values: list<mixed>}
     */
    public static function nameSqlFragment(array $clean): array
    {
        $nameCols = self::nameColumns();
        $cols = [];
        $vals = [];

        if ($nameCols['first']) {
            $cols[] = $nameCols['first'];
            $vals[] = $clean['first_name'] ?? '';
        }
        if ($nameCols['last']) {
            $cols[] = $nameCols['last'];
            $vals[] = $clean['last_name'] ?? '';
        }

        return [
            'cols' => $cols,
            'set' => $cols ? implode(' = ?, ', $cols) . ' = ?' : '',
            'values' => $vals,
        ];
    }

    /** SELECT compatible para traer nombres/apellidos como aliases estables. */
    public static function selectNamePartsExpr(string $alias = ''): string
    {
        $a = $alias ? rtrim($alias, '.') . '.' : '';
        $nameCols = self::nameColumns();

        $first = $nameCols['first'] ? ($a . $nameCols['first'] . ' AS first_name') : 'NULL AS first_name';
        $last = $nameCols['last'] ? ($a . $nameCols['last'] . ' AS last_name') : 'NULL AS last_name';

        return $first . ', ' . $last;
    }

    /**
     * Normaliza los 3 campos opcionales desde $_POST (o array similar).
     * Devuelve ['birth_date' => string|null, 'gender' => string|null, 'address' => string|null].
     */
    public static function normalize(array $input): array
    {
        $bd = trim((string) ($input['birth_date'] ?? ''));
        $gn = trim((string) ($input['gender'] ?? ''));
        $ad = trim((string) ($input['address'] ?? ''));

        return [
            'birth_date' => $bd !== '' ? $bd : null,
            'gender'     => $gn !== '' ? $gn : null,
            'address'    => $ad !== '' ? mb_substr($ad, 0, 255) : null,
        ];
    }

    /**
     * Valida los 3 campos opcionales. Devuelve errores asociativos.
     * No exige nada — solo bloquea valores inválidos.
     */
    public static function validate(array $clean): array
    {
        $errors = [];
        if (!empty($clean['birth_date'])) {
            $ts = strtotime($clean['birth_date']);
            $today = strtotime('today');
            if (!$ts) {
                $errors['birth_date'] = 'Fecha de nacimiento inválida.';
            } elseif ($ts > $today) {
                $errors['birth_date'] = 'La fecha de nacimiento no puede ser futura.';
            } elseif ($ts < strtotime('1900-01-01')) {
                $errors['birth_date'] = 'Fecha demasiado antigua.';
            }
        }
        if (!empty($clean['gender']) && !array_key_exists($clean['gender'], self::GENDERS)) {
            $errors['gender'] = 'Selecciona una opción válida.';
        }
        if (!empty($clean['address']) && mb_strlen($clean['address']) > 255) {
            $errors['address'] = 'La dirección excede 255 caracteres.';
        }
        return $errors;
    }

    /**
     * Devuelve el fragmento SQL ", birth_date = ?, gender = ?, address = ?" + valores
     * únicamente para las columnas que existen en la tabla users. Útil para
     * INSERT y UPDATE sin romper despliegues anteriores a fase 5.
     *
     * @return array{set: string, cols: string[], placeholders: string, values: list<mixed>}
     */
    public static function sqlFragment(array $clean): array
    {
        $cols = [];
        $vals = [];
        foreach (['birth_date', 'gender', 'address'] as $c) {
            if (self::hasColumn($c)) {
                $cols[] = $c;
                $vals[] = $clean[$c] ?? null;
            }
        }
        return [
            'cols'         => $cols,
            'placeholders' => $cols ? str_repeat('?, ', count($cols) - 1) . '?' : '',
            'set'          => $cols ? implode(' = ?, ', $cols) . ' = ?' : '',
            'values'       => $vals,
        ];
    }

    /** Selecciona estos 3 campos (con SELECT NULL como fallback) para SELECTs cómodos. */
    public static function selectExpr(string $alias = ''): string
    {
        $a = $alias ? rtrim($alias, '.') . '.' : '';
        $parts = [];
        foreach (['birth_date', 'gender', 'address'] as $c) {
            $parts[] = self::hasColumn($c) ? ($a . $c) : ('NULL AS ' . $c);
        }
        return implode(', ', $parts);
    }
}

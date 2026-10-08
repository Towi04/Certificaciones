<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Convenios especiales de partner (p. ej. CNCM): fuera de Bronze/Silver/Gold,
 * con columna de precio propia en products/combos.
 */
final class PartnerSpecialTierService
{
    private const RESERVED = ['a', 'b', 'c'];

    private PDO $pdo;
    private static bool $schemaReady = false;

    /** @var list<array<string,mixed>>|null */
    private static ?array $cache = null;

    public function __construct()
    {
        $this->pdo = Connection::get();
        self::ensureSchema($this->pdo);
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (self::$schemaReady) {
            return;
        }
        $pdo = $pdo ?? Connection::get();
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS partner_special_tiers (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(40) NOT NULL,
                    label VARCHAR(120) NOT NULL,
                    price_column VARCHAR(64) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    sort_order INT NOT NULL DEFAULT 100,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_partner_special_tiers_code (code),
                    UNIQUE KEY uq_partner_special_tiers_col (price_column)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // partners.tier: ENUM → VARCHAR para códigos nuevos.
            $col = $pdo->query("SHOW COLUMNS FROM partners LIKE 'tier'")->fetch(PDO::FETCH_ASSOC);
            $type = strtolower((string) ($col['Type'] ?? ''));
            if (str_contains($type, 'enum')) {
                $pdo->exec("ALTER TABLE partners MODIFY COLUMN tier VARCHAR(40) NOT NULL DEFAULT 'c'");
            }

            $stmt = $pdo->query("SELECT 1 FROM partner_special_tiers WHERE code = 'cncm' LIMIT 1");
            if (!$stmt || !$stmt->fetchColumn()) {
                $pdo->prepare(
                    'INSERT INTO partner_special_tiers (code, label, price_column, is_active, sort_order)
                     VALUES (?,?,?,?,?)'
                )->execute(['cncm', 'CNCM', 'price_cncm', 1, 10]);
            }

            // Asegura columnas de precio de todos los especiales activos.
            $rows = $pdo->query(
                'SELECT code, price_column FROM partner_special_tiers WHERE is_active = 1'
            )->fetchAll() ?: [];
            foreach ($rows as $row) {
                self::ensurePriceColumn($pdo, (string) $row['price_column']);
            }

            self::$schemaReady = true;
            self::$cache = null;
        } catch (\Throwable $e) {
            error_log('[Doceo] partner special tiers schema: ' . $e->getMessage());
        }
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        return $code;
    }

    public static function priceColumnForCode(string $code): string
    {
        $code = self::normalizeCode($code);
        if ($code === 'cncm') {
            return 'price_cncm';
        }

        return 'price_special_' . $code;
    }

    public static function ensurePriceColumn(PDO $pdo, string $column): void
    {
        $column = trim($column);
        if ($column === '' || !preg_match('/^price_[a-z0-9_]{1,48}$/', $column)) {
            throw new \InvalidArgumentException('Columna de precio inválida.');
        }
        foreach (['products', 'combos'] as $table) {
            $cols = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $have = array_fill_keys(array_map('strval', $cols), true);
            if (isset($have[$column])) {
                continue;
            }
            $pdo->exec(
                'ALTER TABLE `' . $table . '`
                 ADD COLUMN `' . $column . '` DECIMAL(12,2) NULL AFTER price_cncm'
            );
        }
    }

    /**
     * @return list<array{id:int,code:string,label:string,price_column:string,is_active:int,sort_order:int}>
     */
    public function all(bool $activeOnly = false): array
    {
        if (!$activeOnly && self::$cache !== null) {
            return self::$cache;
        }
        $sql = 'SELECT * FROM partner_special_tiers';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, label ASC, id ASC';
        $rows = $this->pdo->query($sql)->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'code' => (string) ($row['code'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'price_column' => (string) ($row['price_column'] ?? ''),
                'is_active' => (int) ($row['is_active'] ?? 0),
                'sort_order' => (int) ($row['sort_order'] ?? 100),
            ];
        }
        if (!$activeOnly) {
            self::$cache = $out;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        $code = self::normalizeCode($code);
        foreach ($this->all(false) as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{id:int,code:string,label:string,price_column:string}
     */
    public function create(string $code, string $label, int $sortOrder = 100): array
    {
        $code = self::normalizeCode($code);
        $label = trim($label);
        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code)) {
            throw new \InvalidArgumentException(
                'Código inválido: usa letras/números/guion bajo, 2–31 caracteres, empieza con letra.'
            );
        }
        if (in_array($code, self::RESERVED, true)) {
            throw new \InvalidArgumentException('Ese código está reservado para Bronze/Silver/Gold.');
        }
        if ($label === '') {
            throw new \InvalidArgumentException('El nombre del convenio es obligatorio.');
        }
        if ($this->findByCode($code) !== null) {
            throw new \InvalidArgumentException('Ya existe un convenio especial con ese código.');
        }

        $column = self::priceColumnForCode($code);
        self::ensurePriceColumn($this->pdo, $column);

        $this->pdo->prepare(
            'INSERT INTO partner_special_tiers (code, label, price_column, is_active, sort_order)
             VALUES (?,?,?,?,?)'
        )->execute([$code, $label, $column, 1, $sortOrder]);

        self::clearCache();
        PartnerAdminService::clearTierCache();

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'code' => $code,
            'label' => $label,
            'price_column' => $column,
        ];
    }

    public function update(int $id, string $label, bool $active, int $sortOrder = 100): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('El nombre del convenio es obligatorio.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE partner_special_tiers
             SET label = ?, is_active = ?, sort_order = ?
             WHERE id = ?'
        );
        $stmt->execute([$label, $active ? 1 : 0, $sortOrder, $id]);
        if ($stmt->rowCount() < 1) {
            // Puede ser 0 si no cambió nada; verifica existencia.
            $check = $this->pdo->prepare('SELECT 1 FROM partner_special_tiers WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                throw new \InvalidArgumentException('Convenio especial no encontrado.');
            }
        }
        self::clearCache();
        PartnerAdminService::clearTierCache();
    }

    /**
     * Etiquetas code => label (solo activos).
     *
     * @return array<string, string>
     */
    public static function activeLabels(): array
    {
        $svc = new self();
        $out = [];
        foreach ($svc->all(true) as $row) {
            $out[$row['code']] = $row['label'];
        }

        return $out;
    }

    /**
     * Columnas de precio especiales activas: price_col => label.
     *
     * @return array<string, string>
     */
    public static function activePriceFields(): array
    {
        $svc = new self();
        $out = [];
        foreach ($svc->all(true) as $row) {
            $col = (string) $row['price_column'];
            if ($col !== '') {
                $out[$col] = (string) $row['label'];
            }
        }

        return $out;
    }

    /**
     * Mapa tier_code => price_column (activos).
     *
     * @return array<string, string>
     */
    public static function activePriceColumnMap(): array
    {
        $svc = new self();
        $out = [];
        foreach ($svc->all(true) as $row) {
            $out[(string) $row['code']] = (string) $row['price_column'];
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ProductRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    private const SELECT_WITH_RELATIONS = 'SELECT p.*,
                c.name AS certifier_name,
                s.name AS supplier_name,
                s.code AS supplier_code,
                pg.code AS product_group_code,
                pg.name AS product_group_name,
                pg.config_json AS group_config_json
             FROM products p
             LEFT JOIN certifiers c ON c.id = p.certifier_id
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             LEFT JOIN product_groups pg ON pg.id = p.product_group_id';

    /** @return list<array<string, mixed>> */
    public function publicCatalog(
        ?string $filterSlug = null,
        ?string $q = null,
        bool $starsOnly = false,
        ?int $limit = null,
        ?int $offset = null
    ): array {
        [$sql, $params] = $this->publicCatalogWhere($filterSlug, $q, $starsOnly);
        $sql = self::SELECT_WITH_RELATIONS . $sql
            . ' ORDER BY p.is_star DESC, p.sort_order ASC, p.name ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) ($offset ?? 0));
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function publicCatalogCount(?string $filterSlug = null, ?string $q = null, bool $starsOnly = false): int
    {
        [$where, $params] = $this->publicCatalogWhere($filterSlug, $q, $starsOnly);
        $sql = 'SELECT COUNT(*) FROM products p
                LEFT JOIN certifiers c ON c.id = p.certifier_id
                LEFT JOIN suppliers s ON s.id = p.supplier_id'
            . $where;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function publicCatalogWhere(?string $filterSlug, ?string $q, bool $starsOnly): array
    {
        $sql = ' WHERE p.is_active = 1 AND p.is_public = 1';
        $params = [];
        if ($filterSlug !== null && $filterSlug !== '' && $filterSlug !== 'all') {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM product_catalog_filters pcf
                JOIN catalog_filters cf ON cf.id = pcf.filter_id
                WHERE pcf.product_id = p.id AND cf.slug = ? AND cf.is_active = 1
            )';
            $params[] = $filterSlug;
        }
        if ($starsOnly) {
            $sql .= ' AND p.is_star = 1';
        }
        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.code LIKE ? OR c.name LIKE ? OR s.name LIKE ?)';
            $like = '%' . trim($q) . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return [$sql, $params];
    }

    /** @return list<array<string, mixed>> */
    public function starProducts(?int $limit = null): array
    {
        $sql = self::SELECT_WITH_RELATIONS . '
             WHERE p.is_active = 1 AND p.is_public = 1 AND p.is_star = 1
             ORDER BY p.sort_order ASC, p.name ASC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll();
    }

    public function adminCount(?string $q = null, array $filters = []): int
    {
        [$where, $params] = $this->adminFiltersWhere($q, $filters);
        $sql = 'SELECT COUNT(*) FROM products p
                LEFT JOIN product_groups pg ON pg.id = p.product_group_id
                LEFT JOIN suppliers s ON s.id = p.supplier_id'
            . $where;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_WITH_RELATIONS . '
             WHERE p.slug = ? LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_WITH_RELATIONS . '
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array{
     *   supplier_id?:int|string|null,
     *   product_group_id?:int|string|null,
     *   is_public?:int|string|null,
     *   is_star?:int|string|null
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function adminList(?string $q = null, ?int $limit = null, ?int $offset = null, array $filters = []): array
    {
        [$where, $params] = $this->adminFiltersWhere($q, $filters);
        $sql = self::SELECT_WITH_RELATIONS . $where . ' ORDER BY p.type, p.name';
        // MariaDB rejects quoted LIMIT/OFFSET from PDO string binding; cast inline.
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) ($offset ?? 0));
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function adminFiltersWhere(?string $q, array $filters): array
    {
        $sql = ' WHERE 1=1';
        $params = [];
        if ($q) {
            $sql .= ' AND (p.name LIKE ? OR p.code LIKE ? OR pg.name LIKE ? OR pg.code LIKE ? OR s.name LIKE ? OR s.code LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : 0;
        if ($supplierId > 0) {
            $sql .= ' AND p.supplier_id = ?';
            $params[] = $supplierId;
        }
        $groupId = isset($filters['product_group_id']) ? (int) $filters['product_group_id'] : 0;
        if ($groupId > 0) {
            $sql .= ' AND p.product_group_id = ?';
            $params[] = $groupId;
        }
        if (array_key_exists('is_public', $filters) && $filters['is_public'] !== '' && $filters['is_public'] !== null) {
            $sql .= ' AND p.is_public = ?';
            $params[] = ((int) $filters['is_public']) === 1 ? 1 : 0;
        }
        if (array_key_exists('is_star', $filters) && $filters['is_star'] !== '' && $filters['is_star'] !== null) {
            $sql .= ' AND p.is_star = ?';
            $params[] = ((int) $filters['is_star']) === 1 ? 1 : 0;
        }

        return [$sql, $params];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO products (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = [];
        foreach ($data as $k => $_) {
            $sets[] = "{$k} = :{$k}";
        }
        $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function countActive(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findBySlugExact(string $slug, ?int $excludeId = null): ?array
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM products WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM products WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        }
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function countPurchaseItems(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_items WHERE product_id = ?');
        $stmt->execute([$productId]);

        return (int) $stmt->fetchColumn();
    }

    public function countTrackings(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM trackings WHERE product_id = ?');
        $stmt->execute([$productId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }
}

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
    public function publicCatalog(?string $filterSlug = null, ?string $q = null, bool $starsOnly = false): array
    {
        $sql = self::SELECT_WITH_RELATIONS . '
                WHERE p.is_active = 1 AND p.is_public = 1';
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
        $sql .= ' ORDER BY p.is_star DESC, p.sort_order ASC, p.name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function starProducts(?int $limit = null): array
    {
        $sql = self::SELECT_WITH_RELATIONS . '
             WHERE p.is_active = 1 AND p.is_public = 1 AND p.is_star = 1
             ORDER BY p.sort_order ASC, p.name ASC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->pdo->query($sql);
        }

        return $stmt->fetchAll();
    }

    public function adminCount(?string $q = null): int
    {
        $sql = 'SELECT COUNT(*) FROM products p
                LEFT JOIN product_groups pg ON pg.id = p.product_group_id
                WHERE 1=1';
        $params = [];
        if ($q) {
            $sql .= ' AND (p.name LIKE ? OR p.code LIKE ? OR pg.name LIKE ? OR pg.code LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
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

    /** @return list<array<string, mixed>> */
    public function adminList(?string $q = null, ?int $limit = null, ?int $offset = null): array
    {
        $sql = self::SELECT_WITH_RELATIONS . '
                WHERE 1=1';
        $params = [];
        if ($q) {
            $sql .= ' AND (p.name LIKE ? OR p.code LIKE ? OR pg.name LIKE ? OR pg.code LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY p.type, p.name';
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = max(0, $offset ?? 0);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
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
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ComboRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM combo_items ci WHERE ci.combo_id = c.id) AS items_count
             FROM combos c
             ORDER BY c.is_star DESC, c.name ASC'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM combos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM combos WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM combos WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function activeContainingProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*
             FROM combos c
             INNER JOIN combo_items ci ON ci.combo_id = c.id
             WHERE c.is_active = 1 AND ci.product_id = ?
             ORDER BY c.is_star DESC, c.public_price ASC, c.name ASC'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll();
    }

    /** ¿La certificación ya está en un combo activo que incluye algún curso? */
    public function hasActiveComboWithCourse(int $productId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM combo_items ci
             INNER JOIN combos c ON c.id = ci.combo_id AND c.is_active = 1
             INNER JOIN combo_items ci2 ON ci2.combo_id = c.id
             INNER JOIN products p2 ON p2.id = ci2.product_id AND p2.type = ?
             WHERE ci.product_id = ?
             LIMIT 1'
        );
        $stmt->execute(['course', $productId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Primer combo activo de la certificación que también incluye un curso.
     *
     * @return array<string, mixed>|null
     */
    public function activeCourseComboForProduct(int $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*
             FROM combos c
             INNER JOIN combo_items ci ON ci.combo_id = c.id
             INNER JOIN combo_items ci2 ON ci2.combo_id = c.id
             INNER JOIN products p2 ON p2.id = ci2.product_id AND p2.type = ?
             WHERE c.is_active = 1 AND ci.product_id = ?
             ORDER BY c.is_star DESC, c.public_price ASC, c.name ASC
             LIMIT 1'
        );
        $stmt->execute(['course', $productId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function items(int $comboId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, ci.sort_order
             FROM combo_items ci
             INNER JOIN products p ON p.id = ci.product_id
             WHERE ci.combo_id = ?
             ORDER BY ci.sort_order ASC, p.name ASC'
        );
        $stmt->execute([$comboId]);

        return $stmt->fetchAll();
    }

    /**
     * @param list<int> $comboIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function itemsByComboIds(array $comboIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $comboIds))));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT ci.combo_id, p.*, ci.sort_order
             FROM combo_items ci
             INNER JOIN products p ON p.id = ci.product_id
             WHERE ci.combo_id IN ({$placeholders})
             ORDER BY ci.combo_id ASC, ci.sort_order ASC, p.name ASC"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $cid = (int) ($row['combo_id'] ?? 0);
            unset($row['combo_id']);
            $out[$cid][] = $row;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicCatalog(
        ?string $q = null,
        bool $starsOnly = false,
        ?int $limit = null,
        ?int $offset = null,
        string $sort = 'relevantes'
    ): array {
        [$where, $params] = $this->publicCatalogWhere($q, $starsOnly);
        $sql = 'SELECT c.* FROM combos c' . $where
            . ' ORDER BY ' . $this->publicCatalogOrderBy($sort);
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) ($offset ?? 0));
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function publicCatalogCount(?string $q = null, bool $starsOnly = false): int
    {
        [$where, $params] = $this->publicCatalogWhere($q, $starsOnly);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM combos c' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function starCombos(?int $limit = null): array
    {
        return $this->publicCatalog(null, true, $limit, 0, 'relevantes');
    }

    public function findPublicBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM combos
             WHERE slug = ? AND is_active = 1 AND COALESCE(is_public, 1) = 1
             LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function publicCatalogWhere(?string $q, bool $starsOnly): array
    {
        $sql = ' WHERE c.is_active = 1 AND COALESCE(c.is_public, 1) = 1';
        $params = [];
        if ($starsOnly) {
            $sql .= ' AND c.is_star = 1';
        }
        if ($q !== null && trim($q) !== '') {
            $tokens = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }
                $sql .= ' AND (
                    c.name LIKE ? OR c.code LIKE ? OR c.slug LIKE ?
                    OR c.short_description LIKE ? OR c.description LIKE ?
                    OR EXISTS (
                        SELECT 1 FROM combo_items ci
                        INNER JOIN products p ON p.id = ci.product_id
                        WHERE ci.combo_id = c.id
                          AND (p.name LIKE ? OR p.code LIKE ? OR p.slug LIKE ?)
                    )
                )';
                $like = '%' . $token . '%';
                array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
            }
        }

        return [$sql, $params];
    }

    private function publicCatalogOrderBy(string $sort): string
    {
        $sort = ProductRepository::normalizeCatalogSort($sort);
        $priceExpr = 'COALESCE(NULLIF(c.catalog_price, 0), NULLIF(c.public_price, 0), 0)';

        return match ($sort) {
            'precio_asc' => $priceExpr . ' ASC, c.name ASC',
            'precio_desc' => $priceExpr . ' DESC, c.name ASC',
            'nombre_asc' => 'c.name ASC',
            'nombre_desc' => 'c.name DESC',
            default => 'c.is_star DESC, c.name ASC',
        };
    }

    /** @return list<int> */
    public function productIds(int $comboId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT product_id FROM combo_items WHERE combo_id = ? ORDER BY sort_order ASC, product_id ASC'
        );
        $stmt->execute([$comboId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $productIds
     */
    public function findActiveByExactProductSet(array $productIds): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        sort($ids);
        if ($ids === []) {
            return null;
        }

        $candidates = $this->pdo->query('SELECT id FROM combos WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($candidates as $comboId) {
            $comboId = (int) $comboId;
            $have = $this->productIds($comboId);
            sort($have);
            if ($have === $ids) {
                return $this->find($comboId);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $base = [
            'code' => $data['code'] ?? '',
            'name' => $data['name'] ?? '',
            'slug' => $data['slug'] ?? '',
            'description' => $data['description'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'logo_path' => $data['logo_path'] ?? null,
            'is_active' => $data['is_active'] ?? 1,
            'is_public' => $data['is_public'] ?? 1,
            'is_star' => $data['is_star'] ?? 0,
            'public_price' => $data['public_price'] ?? 0,
            'catalog_price' => $data['catalog_price'] ?? 0,
            'price_partner_a' => $data['price_partner_a'] ?? null,
            'price_partner_b' => $data['price_partner_b'] ?? null,
            'price_partner_c' => $data['price_partner_c'] ?? null,
        ];
        foreach ($data as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'price_') && !array_key_exists($k, $base)) {
                $base[$k] = $v;
            }
        }
        $cols = array_keys($base);
        $placeholders = array_map(static fn ($c) => ':' . $c, $cols);
        $stmt = $this->pdo->prepare(
            'INSERT INTO combos (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')'
        );
        foreach ($base as $k => $v) {
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
        $stmt = $this->pdo->prepare('UPDATE combos SET ' . implode(', ', $sets) . ' WHERE id = :id');
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM combos WHERE id = ?')->execute([$id]);
    }

    /** @param list<int> $productIds */
    public function syncItems(int $comboId, array $productIds): void
    {
        $this->pdo->prepare('DELETE FROM combo_items WHERE combo_id = ?')->execute([$comboId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO combo_items (combo_id, product_id, sort_order) VALUES (?, ?, ?)'
        );
        $order = 0;
        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            if ($pid < 1) {
                continue;
            }
            $ins->execute([$comboId, $pid, $order++]);
        }
    }

    public function countPurchases(int $comboId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchases WHERE combo_id = ?');
        $stmt->execute([$comboId]);

        return (int) $stmt->fetchColumn();
    }
}

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
        $stmt = $this->pdo->prepare(
            'INSERT INTO combos (
                code, name, slug, description, is_active, is_star,
                public_price, catalog_price, price_cncm, price_partner_a, price_partner_b, price_partner_c
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $data['code'],
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $data['is_active'] ?? 1,
            $data['is_star'] ?? 0,
            $data['public_price'] ?? 0,
            $data['catalog_price'] ?? 0,
            $data['price_cncm'] ?? null,
            $data['price_partner_a'] ?? null,
            $data['price_partner_b'] ?? null,
            $data['price_partner_c'] ?? null,
        ]);

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

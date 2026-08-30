<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class CatalogFilterRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function catalogVisible(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM catalog_filters
             WHERE is_active = 1 AND show_in_catalog = 1
             ORDER BY sort_order ASC, label ASC'
        );

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function adminList(): array
    {
        $stmt = $this->pdo->query(
            'SELECT cf.*,
                    (SELECT COUNT(*) FROM product_catalog_filters pcf WHERE pcf.filter_id = cf.id) AS product_count
             FROM catalog_filters cf
             ORDER BY cf.sort_order ASC, cf.label ASC'
        );

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM catalog_filters WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM catalog_filters WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM catalog_filters WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM catalog_filters WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->execute([$slug, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO catalog_filters (slug, label, filter_group, sort_order, is_active, show_in_catalog)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['slug'],
            $data['label'],
            $data['filter_group'] ?? null,
            (int) ($data['sort_order'] ?? 100),
            !empty($data['is_active']) ? 1 : 0,
            !empty($data['show_in_catalog']) ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE catalog_filters
             SET slug = ?, label = ?, filter_group = ?, sort_order = ?, is_active = ?, show_in_catalog = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['slug'],
            $data['label'],
            $data['filter_group'] ?? null,
            (int) ($data['sort_order'] ?? 100),
            !empty($data['is_active']) ? 1 : 0,
            !empty($data['show_in_catalog']) ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM catalog_filters WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return list<int> */
    public function filterIdsForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT filter_id FROM product_catalog_filters WHERE product_id = ? ORDER BY filter_id'
        );
        $stmt->execute([$productId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'filter_id'));
    }

    /** @param list<int> $filterIds */
    public function setProductFilters(int $productId, array $filterIds): void
    {
        $filterIds = array_values(array_unique(array_filter(array_map('intval', $filterIds), static fn (int $id): bool => $id > 0)));

        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM product_catalog_filters WHERE product_id = ?');
            $del->execute([$productId]);

            if ($filterIds !== []) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO product_catalog_filters (product_id, filter_id) VALUES (?, ?)'
                );
                foreach ($filterIds as $filterId) {
                    $ins->execute([$productId, $filterId]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function ensureDefaults(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM catalog_filters')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $defaults = [
            ['english_adult', 'Inglés adultos', 'Idioma', 10],
            ['english_kids', 'Inglés menores', 'Idioma', 20],
            ['french_adult', 'Francés adultos', 'Idioma', 25],
            ['it', 'Informática', 'Área', 30],
            ['teaching', 'Enseñanza', 'Área', 40],
            ['other', 'Otros', 'Área', 90],
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO catalog_filters (slug, label, filter_group, sort_order, is_active, show_in_catalog)
             VALUES (?, ?, ?, ?, 1, 1)'
        );
        foreach ($defaults as [$slug, $label, $group, $sort]) {
            $stmt->execute([$slug, $label, $group, $sort]);
        }
    }
}

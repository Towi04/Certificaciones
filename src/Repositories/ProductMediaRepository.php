<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ProductMediaRepository
{
    private static bool $schemaEnsured = false;
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->ensureSchema();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forProduct(int $productId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM product_media WHERE product_id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_media WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_media (
                product_id, media_type, title, caption, storage_path, external_url, mime_type, sort_order, is_active
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['product_id'],
            $data['media_type'],
            $data['title'],
            $data['caption'],
            $data['storage_path'] ?? '',
            $data['external_url'] ?? null,
            $data['mime_type'],
            $data['sort_order'],
            !empty($data['is_active']) ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM product_media WHERE id = ?')->execute([$id]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->pdo->prepare(
            'UPDATE product_media
             SET title = ?, caption = ?, sort_order = ?, is_active = ?,
                 storage_path = COALESCE(?, storage_path),
                 mime_type = COALESCE(?, mime_type)
             WHERE id = ?'
        )->execute([
            $data['title'],
            $data['caption'],
            $data['sort_order'],
            !empty($data['is_active']) ? 1 : 0,
            $data['storage_path'] ?? null,
            $data['mime_type'] ?? null,
            $id,
        ]);
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS product_media (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id BIGINT UNSIGNED NOT NULL,
                media_type ENUM('image','video') NOT NULL,
                title VARCHAR(190) NOT NULL DEFAULT '',
                caption VARCHAR(255) NULL,
                storage_path VARCHAR(255) NOT NULL DEFAULT '',
                external_url VARCHAR(512) NULL,
                mime_type VARCHAR(120) NOT NULL DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_product_media_product (product_id, is_active, sort_order),
                CONSTRAINT fk_product_media_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!$this->columnExists('product_media', 'external_url')) {
            $this->pdo->exec('ALTER TABLE product_media ADD COLUMN external_url VARCHAR(512) NULL AFTER storage_path');
        }

        self::$schemaEnsured = true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}

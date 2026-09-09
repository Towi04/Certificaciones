<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ExportTemplateRepository
{
    private static bool $schemaEnsured = false;
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->ensureSchema();
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM export_templates WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM export_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        return $this->pdo->query(
            'SELECT et.*, s.name AS supplier_name
             FROM export_templates et
             LEFT JOIN suppliers s ON s.id = et.supplier_id
             WHERE et.is_active = 1
             ORDER BY et.name ASC'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT et.*, s.name AS supplier_name
             FROM export_templates et
             LEFT JOIN suppliers s ON s.id = et.supplier_id
             ORDER BY et.name ASC'
        )->fetchAll();
    }

    public function deleteByCode(string $code): void
    {
        $this->pdo->prepare('DELETE FROM export_templates WHERE code = ?')->execute([$code]);
    }

    /** @param array<string, mixed> $data */
    public function upsert(string $code, array $data): int
    {
        $existing = $this->findByCode($code);
        if ($existing) {
            unset($data['code']);
            $sets = [];
            foreach (array_keys($data) as $col) {
                $sets[] = "{$col} = :{$col}";
            }
            $data['id'] = (int) $existing['id'];
            $sql = 'UPDATE export_templates SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            foreach ($data as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->execute();

            return (int) $existing['id'];
        }

        $data['code'] = $code;
        $cols = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $cols);
        $sql = 'INSERT INTO export_templates (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS export_templates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(60) NOT NULL,
                name VARCHAR(190) NOT NULL,
                supplier_id BIGINT UNSIGNED NULL,
                file_type ENUM('xlsx','csv') NOT NULL DEFAULT 'csv',
                storage_path VARCHAR(255) NOT NULL DEFAULT '',
                delivery ENUM('email_attach','download') NOT NULL DEFAULT 'download',
                batch_by ENUM('none','exam_date') NOT NULL DEFAULT 'none',
                mapping_json JSON NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uq_export_tpl (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }
}

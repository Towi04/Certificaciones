<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ImportTemplateRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM import_templates WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        return $this->pdo->query(
            'SELECT it.*, s.name AS supplier_name
             FROM import_templates it
             LEFT JOIN suppliers s ON s.id = it.supplier_id
             WHERE it.is_active = 1
             ORDER BY it.name ASC'
        )->fetchAll();
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
            $sql = 'UPDATE import_templates SET ' . implode(', ', $sets) . ' WHERE id = :id';
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
        $sql = 'INSERT INTO import_templates (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }
}

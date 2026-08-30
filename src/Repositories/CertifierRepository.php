<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class CertifierRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM certifiers ORDER BY name')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM certifiers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM certifiers WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO certifiers (name, code, logo_path, website, platform_url, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['code'],
            $data['logo_path'] ?? null,
            $data['website'] ?? null,
            $data['platform_url'] ?? null,
            $data['notes'] ?? null,
            $data['is_active'] ?? 1,
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
        $stmt = $this->pdo->prepare('UPDATE certifiers SET ' . implode(', ', $sets) . ' WHERE id = :id');
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM certifiers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countProducts(int $certifierId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE certifier_id = ?');
        $stmt->execute([$certifierId]);

        return (int) $stmt->fetchColumn();
    }
}

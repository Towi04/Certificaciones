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

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO certifiers (name, code, logo_path) VALUES (?, ?, ?)');
        $stmt->execute([$data['name'], $data['code'], $data['logo_path'] ?? null]);

        return (int) $this->pdo->lastInsertId();
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
}


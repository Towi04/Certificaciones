<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class SupplierRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppliers (name, code, website, notes, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['code'],
            $data['website'] ?? null,
            $data['notes'] ?? null,
            $data['is_active'] ?? 1,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function contacts(int $supplierId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_contacts WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function accounts(int $supplierId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, supplier_id, label, login_url, username, notes FROM supplier_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll();
    }
}


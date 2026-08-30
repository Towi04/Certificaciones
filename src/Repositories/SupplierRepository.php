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

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppliers (name, code, website, logo_path, platform_url, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['code'],
            $data['website'] ?? null,
            $data['logo_path'] ?? null,
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
        $sql = 'UPDATE suppliers SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countProducts(int $supplierId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    public function countGroups(int $supplierId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_groups WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function contacts(int $supplierId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_contacts WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll();
    }

    public function findContact(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_contacts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function createContact(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO supplier_contacts (supplier_id, role_label, name, email, phone, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['supplier_id'],
            $data['role_label'],
            $data['name'] ?? '',
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['notes'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function updateContact(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = [];
        foreach ($data as $k => $_) {
            $sets[] = "{$k} = :{$k}";
        }
        $stmt = $this->pdo->prepare('UPDATE supplier_contacts SET ' . implode(', ', $sets) . ' WHERE id = :id');
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteContact(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM supplier_contacts WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public function accounts(int $supplierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, supplier_id, label, login_url, username, notes FROM supplier_accounts WHERE supplier_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll();
    }

    public function findAccount(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function createAccount(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO supplier_accounts (supplier_id, label, login_url, username, password_enc, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['supplier_id'],
            $data['label'],
            $data['login_url'] ?? null,
            $data['username'] ?? null,
            $data['password_enc'] ?? null,
            $data['notes'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function updateAccount(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = [];
        foreach ($data as $k => $_) {
            $sets[] = "{$k} = :{$k}";
        }
        $stmt = $this->pdo->prepare('UPDATE supplier_accounts SET ' . implode(', ', $sets) . ' WHERE id = :id');
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteAccount(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM supplier_accounts WHERE id = ?');
        $stmt->execute([$id]);
    }
}

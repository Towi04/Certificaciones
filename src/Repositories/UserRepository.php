<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class UserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    public function countAdmins(?string $q = null): int
    {
        $sql = "SELECT COUNT(*) FROM users WHERE role = 'admin'";
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (email LIKE ? OR first_name LIKE ? OR last_name_p LIKE ? OR last_name_m LIKE ?)';
            $like = '%' . trim($q) . '%';
            $params = [$like, $like, $like, $like];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function adminList(?string $q = null, ?int $limit = null, ?int $offset = null): array
    {
        $sql = "SELECT id, role, email, first_name, last_name_p, last_name_m, phone,
                       must_change_password, is_active, last_login_at, created_at
                FROM users
                WHERE role = 'admin'";
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $sql .= ' AND (email LIKE ? OR first_name LIKE ? OR last_name_p LIKE ? OR last_name_m LIKE ?)';
            $like = '%' . trim($q) . '%';
            $params = [$like, $like, $like, $like];
        }
        $sql .= ' ORDER BY first_name ASC, last_name_p ASC, id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) ($offset ?? 0));
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findAdmin(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $stmt->execute([$email, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public function countActiveAdmins(?int $exceptId = null): int
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
            );

            return (int) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?"
        );
        $stmt->execute([$exceptId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{
     *   email:string,password_hash:string,first_name:string,last_name_p:string,
     *   last_name_m:?string,phone:?string,must_change_password:int,is_active:int
     * } $data
     */
    public function createAdmin(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (role, email, password_hash, first_name, last_name_p, last_name_m, phone, must_change_password, is_active)
             VALUES (\'admin\', ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['email'],
            $data['password_hash'],
            $data['first_name'],
            $data['last_name_p'],
            $data['last_name_m'],
            $data['phone'],
            $data['must_change_password'],
            $data['is_active'],
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
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }
}

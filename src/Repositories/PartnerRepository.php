<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class PartnerRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function adminList(?string $q = null): array
    {
        $sql = 'SELECT p.*, u.email, u.first_name, u.last_name_p, u.last_name_m, u.phone,
                       u.is_active AS user_is_active, u.must_change_password
                FROM partners p
                JOIN users u ON u.id = p.user_id';
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $sql .= ' WHERE p.code LIKE ? OR p.display_name LIKE ? OR u.email LIKE ?';
            $like = '%' . trim($q) . '%';
            $params = [$like, $like, $like];
        }
        $sql .= ' ORDER BY p.display_name ASC, p.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, u.email, u.first_name, u.last_name_p, u.last_name_m, u.phone,
                    u.is_active AS user_is_active, u.must_change_password
             FROM partners p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM partners WHERE code = ? LIMIT 1');
            $stmt->execute([$code]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM partners WHERE code = ? AND id <> ? LIMIT 1');
            $stmt->execute([$code, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }
}

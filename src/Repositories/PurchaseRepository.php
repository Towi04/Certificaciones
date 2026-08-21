<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class PurchaseRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function masterList(array $filters = []): array
    {
        $sql = 'SELECT pu.*,
                       u.first_name, u.last_name_p, u.last_name_m, u.email AS student_email, u.phone AS student_phone,
                       p.name AS partner_name, p.code AS partner_code
                FROM purchases pu
                JOIN users u ON u.id = pu.student_user_id
                LEFT JOIN partners p ON p.id = pu.partner_id
                WHERE 1=1';
        $params = [];

        if (!empty($filters['q'])) {
            $sql .= ' AND (u.first_name LIKE ? OR u.last_name_p LIKE ? OR u.email LIKE ? OR pu.matricula LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND pu.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['partner_id'])) {
            $sql .= ' AND pu.partner_id = ?';
            $params[] = $filters['partner_id'];
        }

        $sql .= ' ORDER BY pu.created_at DESC LIMIT 200';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchases WHERE status = ?');
        $stmt->execute([$status]);

        return (int) $stmt->fetchColumn();
    }

    public function nextMatricula(): string
    {
        $n = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM purchases')->fetchColumn();

        return (string) $n;
    }
}


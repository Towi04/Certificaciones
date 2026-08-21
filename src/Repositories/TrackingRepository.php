<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class TrackingRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function forStudent(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name, pr.type AS product_type, pu.matricula
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE t.student_user_id = ?
             ORDER BY t.created_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function forPartner(int $partnerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name, u.first_name, u.last_name_p, u.email, pu.matricula
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN users u ON u.id = t.student_user_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE t.partner_id = ?
             ORDER BY t.created_at DESC'
        );
        $stmt->execute([$partnerId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function waitingAdmin(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name, u.first_name, u.last_name_p, pu.matricula
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN users u ON u.id = t.student_user_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE t.status = ?
             ORDER BY t.updated_at ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, 'waiting_admin');
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function upcomingExams(int $days = 14): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name, u.first_name, u.last_name_p, pu.matricula
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN users u ON u.id = t.student_user_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE t.exam_date IS NOT NULL
               AND t.exam_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY t.exam_date, t.exam_time'
        );
        $stmt->execute([$days]);

        return $stmt->fetchAll();
    }
}

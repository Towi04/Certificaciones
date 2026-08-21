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
            'SELECT t.*, pr.name AS product_name, pr.type AS product_type, pu.matricula,
                    pu.status AS purchase_status, pu.charged_amount, pu.payment_method
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

    /**
     * @param array{
     *   purchase_id:int,purchase_item_id:int,product_id:int,student_user_id:int,
     *   partner_id:?int,pipeline_template_id:?int,current_step_code:?string,status:string
     * } $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO trackings (
                purchase_id, purchase_item_id, product_id, student_user_id, partner_id,
                pipeline_template_id, current_step_code, status
             ) VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $data['purchase_id'],
            $data['purchase_item_id'],
            $data['product_id'],
            $data['student_user_id'],
            $data['partner_id'],
            $data['pipeline_template_id'],
            $data['current_step_code'],
            $data['status'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function forPurchase(int $purchaseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             WHERE t.purchase_id = ?'
        );
        $stmt->execute([$purchaseId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name, pr.type AS product_type, pu.matricula,
                    pu.status AS purchase_status
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE t.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

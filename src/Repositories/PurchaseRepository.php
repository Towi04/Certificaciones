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
                       /* partners.display_name — no existe partners.name */
                       p.display_name AS partner_name, p.code AS partner_code
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

    /** @return list<array<string, mixed>> */
    public function awaitingPaymentList(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pu.id, pu.matricula, pu.status, pu.charged_amount, pu.payment_method, pu.created_at,
                    u.first_name, u.last_name_p, u.email AS student_email
             FROM purchases pu
             JOIN users u ON u.id = pu.student_user_id
             WHERE pu.status IN ('awaiting_payment', 'payment_review')
             ORDER BY pu.created_at ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

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

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM purchases WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByMatricula(string $matricula): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM purchases WHERE matricula = ? LIMIT 1');
        $stmt->execute([$matricula]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByOpenPayChargeId(string $chargeId): ?array
    {
        $chargeId = trim($chargeId);
        if ($chargeId === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM purchases WHERE openpay_charge_id = ? LIMIT 1');
        $stmt->execute([$chargeId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array{
     *   matricula:string,student_user_id:int,partner_id:?int,discount_code_id:?int,combo_id:?int,
     *   status:string,payment_method:string,currency:string,catalog_amount:float,charged_amount:float,
     *   partner_price_amount:?float,partner_credit_earned:float
     * } $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchases (
                matricula, student_user_id, partner_id, discount_code_id, combo_id,
                status, payment_method, currency, catalog_amount, charged_amount,
                partner_price_amount, partner_credit_earned
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $data['matricula'],
            $data['student_user_id'],
            $data['partner_id'],
            $data['discount_code_id'],
            $data['combo_id'],
            $data['status'],
            $data['payment_method'],
            $data['currency'],
            $data['catalog_amount'],
            $data['charged_amount'],
            $data['partner_price_amount'],
            $data['partner_credit_earned'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addItem(int $purchaseId, int $productId, float $public, float $charged): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchase_items (purchase_id, product_id, unit_public_price, unit_charged_price)
             VALUES (?,?,?,?)'
        );
        $stmt->execute([$purchaseId, $productId, $public, $charged]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setPaymentProof(int $purchaseId, string $path): void
    {
        $this->pdo->prepare(
            'UPDATE purchases SET payment_proof_path = ?, status = \'payment_review\' WHERE id = ?'
        )->execute([$path, $purchaseId]);
    }

    public function setOpenPay(int $purchaseId, string $chargeId, ?string $clabe): void
    {
        $this->pdo->prepare(
            'UPDATE purchases SET openpay_charge_id = ?, openpay_clabe = ?, status = \'awaiting_payment\' WHERE id = ?'
        )->execute([$chargeId, $clabe, $purchaseId]);
    }

    public function markPaid(int $purchaseId): void
    {
        $this->pdo->prepare(
            'UPDATE purchases SET status = \'paid\', paid_at = NOW() WHERE id = ?'
        )->execute([$purchaseId]);
    }

    /** @return list<array<string, mixed>> */
    public function items(int $purchaseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pi.*, pr.name AS product_name, pr.slug AS product_slug, pr.code AS product_code
             FROM purchase_items pi
             JOIN products pr ON pr.id = pi.product_id
             WHERE pi.purchase_id = ?'
        );
        $stmt->execute([$purchaseId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function detail(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pu.*,
                    u.first_name, u.last_name_p, u.last_name_m, u.email AS student_email, u.phone AS student_phone,
                    p.display_name AS partner_name, p.code AS partner_code
             FROM purchases pu
             JOIN users u ON u.id = pu.student_user_id
             LEFT JOIN partners p ON p.id = pu.partner_id
             WHERE pu.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}


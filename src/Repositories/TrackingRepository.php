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
            'SELECT t.*, pr.name AS product_name, pr.type AS product_type, pr.code AS product_code,
                    pu.matricula, pu.status AS purchase_status, pu.charged_amount, pu.payment_method,
                    pt.code AS pipeline_code
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN purchases pu ON pu.id = t.purchase_id
             LEFT JOIN pipeline_templates pt ON pt.id = t.pipeline_template_id
             WHERE t.student_user_id = ?
             ORDER BY t.created_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function forPartner(int $partnerId): array
    {
        return $this->forPartnerFiltered($partnerId);
    }

    /**
     * @param array{
     *   q?:?string,
     *   status?:?string,
     *   product_id?:?int,
     *   exam?:?string,
     *   payment?:?string,
     *   limit?:?int,
     *   offset?:?int
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function forPartnerFiltered(int $partnerId, array $filters = []): array
    {
        [$where, $params] = $this->partnerFilterSql($partnerId, $filters);
        $sql = 'SELECT t.*, pr.name AS product_name, pr.type AS product_type, pr.code AS product_code,
                       u.first_name, u.last_name_p, u.last_name_m, u.email, u.phone AS student_phone,
                       pu.matricula, pu.status AS purchase_status, pu.charged_amount
                FROM trackings t
                JOIN products pr ON pr.id = t.product_id
                JOIN users u ON u.id = t.student_user_id
                JOIN purchases pu ON pu.id = t.purchase_id
                WHERE ' . $where . '
                ORDER BY t.created_at DESC, t.id DESC';
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : null;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array{q?:?string,status?:?string,product_id?:?int,exam?:?string,payment?:?string} $filters
     */
    public function countForPartnerFiltered(int $partnerId, array $filters = []): int
    {
        [$where, $params] = $this->partnerFilterSql($partnerId, $filters);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN users u ON u.id = t.student_user_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Resumen de pagos del partner (por purchases.status).
     *
     * @return array<string, array{status:string,label:string,count:int,amount:float}>
     */
    public function partnerPaymentSummary(int $partnerId): array
    {
        $labels = [
            'awaiting_payment' => 'Por pagar',
            'payment_review' => 'En revisión',
            'paid' => 'Pagados',
            'cancelled' => 'Cancelados',
            'refunded' => 'Reembolsados',
        ];
        $stmt = $this->pdo->prepare(
            "SELECT pu.status, COUNT(*) AS cnt, COALESCE(SUM(pu.charged_amount), 0) AS amount
             FROM purchases pu
             WHERE pu.partner_id = ?
               AND pu.status IN ('awaiting_payment','payment_review','paid','cancelled','refunded')
             GROUP BY pu.status"
        );
        $stmt->execute([$partnerId]);
        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($labels as $status => $label) {
            $out[$status] = ['status' => $status, 'label' => $label, 'count' => 0, 'amount' => 0.0];
        }
        foreach ($rows as $row) {
            $st = (string) ($row['status'] ?? '');
            if (!isset($out[$st])) {
                continue;
            }
            $out[$st]['count'] = (int) ($row['cnt'] ?? 0);
            $out[$st]['amount'] = round((float) ($row['amount'] ?? 0), 2);
        }

        return $out;
    }

    /**
     * Exámenes próximos del partner (30/60 días).
     *
     * @return list<array<string, mixed>>
     */
    public function upcomingExamsForPartner(int $partnerId, int $days = 30): array
    {
        $days = in_array($days, [30, 60], true) ? $days : 30;
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.exam_date, t.exam_time, t.status, t.folio,
                    pr.name AS product_name,
                    u.first_name, u.last_name_p, u.email,
                    pu.matricula
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN users u ON u.id = t.student_user_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE t.partner_id = ?
               AND t.exam_date IS NOT NULL
               AND t.exam_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY t.exam_date ASC, t.exam_time ASC, t.id ASC'
        );
        $stmt->execute([$partnerId, $days]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return list<array{id:int,name:string}> */
    public function partnerProductOptions(int $partnerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT pr.id, pr.name
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             WHERE t.partner_id = ?
             ORDER BY pr.name ASC'
        );
        $stmt->execute([$partnerId]);
        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $out;
    }

    /** @return list<string> */
    public function partnerStatusOptions(int $partnerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT t.status
             FROM trackings t
             WHERE t.partner_id = ? AND t.status IS NOT NULL AND t.status <> \'\'
             ORDER BY t.status ASC'
        );
        $stmt->execute([$partnerId]);

        return array_values(array_filter(array_map(
            static fn ($v): string => (string) $v,
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        )));
    }

    /**
     * @param array{q?:?string,status?:?string,product_id?:?int,exam?:?string,payment?:?string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function partnerFilterSql(int $partnerId, array $filters): array
    {
        $where = ['t.partner_id = ?'];
        $params = [$partnerId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(pu.matricula LIKE ?
                OR u.first_name LIKE ?
                OR u.last_name_p LIKE ?
                OR u.last_name_m LIKE ?
                OR u.email LIKE ?
                OR pr.name LIKE ?
                OR t.folio LIKE ?
                OR t.access_key LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        $productId = (int) ($filters['product_id'] ?? 0);
        if ($productId > 0) {
            $where[] = 't.product_id = ?';
            $params[] = $productId;
        }

        $exam = trim((string) ($filters['exam'] ?? ''));
        if ($exam === 'upcoming') {
            $where[] = 't.exam_date IS NOT NULL AND t.exam_date >= CURDATE()';
        } elseif ($exam === 'past') {
            $where[] = 't.exam_date IS NOT NULL AND t.exam_date < CURDATE()';
        } elseif ($exam === 'none') {
            $where[] = 't.exam_date IS NULL';
        } elseif ($exam === 'set') {
            $where[] = 't.exam_date IS NOT NULL';
        }

        $payment = trim((string) ($filters['payment'] ?? ''));
        $allowedPayments = ['awaiting_payment', 'payment_review', 'paid', 'cancelled', 'refunded'];
        if ($payment !== '' && $payment !== 'all' && in_array($payment, $allowedPayments, true)) {
            $where[] = 'pu.status = ?';
            $params[] = $payment;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return list<array<string, mixed>> */
    public function waitingAdmin(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name, u.first_name, u.last_name_p, pu.matricula,
                    pu.id AS purchase_id, pu.status AS purchase_status, pu.charged_amount
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN users u ON u.id = t.student_user_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE t.status = ?
             ORDER BY t.updated_at ASC
             LIMIT ' . (int) $limit
        );
        $stmt->execute(['waiting_admin']);

        return $stmt->fetchAll();
    }


    /**
     * Casos con solicitud a proveedor pendiente de enviar (o reintentar).
     *
     * @return list<array<string, mixed>>
     */
    public function pendingProviderRequests(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.*, pr.name AS product_name, pr.code AS product_code,
                    u.first_name, u.last_name_p, pu.matricula,
                    pu.id AS purchase_id, pu.status AS purchase_status, pu.charged_amount,
                    pg.name AS group_name, pg.code AS group_code
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN users u ON u.id = t.student_user_id
             JOIN purchases pu ON pu.id = t.purchase_id
             LEFT JOIN product_groups pg ON pg.id = pr.product_group_id
             WHERE pu.status = 'paid'
               AND t.status IN ('waiting_admin', 'waiting_provider')
               AND JSON_EXTRACT(t.extra_json, '$.provider_request.required') = true
               AND (
                    JSON_EXTRACT(t.extra_json, '$.provider_request.sent_at') IS NULL
                    OR JSON_UNQUOTE(JSON_EXTRACT(t.extra_json, '$.provider_request.sent_at')) IN ('', 'null')
               )
             ORDER BY t.updated_at ASC
             LIMIT " . (int) $limit
        );
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
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

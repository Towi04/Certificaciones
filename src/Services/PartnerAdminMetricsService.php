<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Métricas de un partner para el panel admin:
 * ventas del convenio, canal (registro propio vs código), crédito por compra.
 */
final class PartnerAdminMetricsService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        PartnerTierService::ensureSchema($this->pdo);
    }

    /**
     * @param array<string, mixed> $partner
     * @return array<string, mixed>
     */
    public function forPartner(array $partner): array
    {
        $partnerId = (int) ($partner['id'] ?? 0);
        $tierSvc = new PartnerTierService();
        $progress = $tierSvc->progressForPartner($partner);
        $period = [
            'start' => (string) ($progress['period_start'] ?? date('Y-01-01')),
            'end' => (string) ($progress['period_end'] ?? date('Y-12-31')),
        ];
        $today = date('Y-m-d');
        $to = min($today, $period['end']);
        $from = $period['start'];

        $channel = $this->channelCounts($partnerId, $from, $to);
        $credit = (new PartnerCreditService())->historyForPartner(
            $partnerId,
            (float) ($partner['credit_balance'] ?? 0)
        );
        $sales = $this->recentCertificationSales($partnerId, $from, $to, 80);
        $statusCounts = $this->purchaseStatusCounts($partnerId);

        return [
            'progress' => $progress,
            'period' => $period,
            'channel' => $channel,
            'credit' => $credit,
            'sales' => $sales,
            'status_counts' => $statusCounts,
            'legacy_sales_bonus' => PartnerTierService::legacySalesBonus($partner),
        ];
    }

    /**
     * @return array{
     *   self:int,code:int,batch:int,total_paid_certs:int,
     *   credit_earned_period:float,credit_used_period:float
     * }
     */
    public function channelCounts(int $partnerId, string $fromYmd, string $toYmd): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN pu.batch_id IS NOT NULL THEN 1 ELSE 0 END) AS batch_n,
                SUM(CASE
                    WHEN pu.batch_id IS NULL AND (
                        COALESCE(pu.partner_credit_earned, 0) > 0.009
                        OR (dc.id IS NOT NULL AND dc.partner_id = pu.partner_id)
                    ) THEN 1 ELSE 0
                END) AS code_n,
                SUM(CASE
                    WHEN pu.batch_id IS NULL
                     AND COALESCE(pu.partner_credit_earned, 0) <= 0.009
                     AND (dc.id IS NULL OR dc.partner_id IS NULL OR dc.partner_id <> pu.partner_id)
                    THEN 1 ELSE 0
                END) AS self_n,
                COUNT(*) AS total_n
             FROM purchase_items pi
             JOIN purchases pu ON pu.id = pi.purchase_id
             JOIN products pr ON pr.id = pi.product_id
             LEFT JOIN discount_codes dc ON dc.id = pu.discount_code_id
             WHERE pu.partner_id = ?
               AND pu.status = 'paid'
               AND pr.type = 'certification'
               AND DATE(COALESCE(pu.paid_at, pu.created_at)) BETWEEN ? AND ?"
        );
        $stmt->execute([$partnerId, $fromYmd, $toYmd]);
        $row = $stmt->fetch() ?: [];

        $creditStmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN pu.partner_credit_applied_at IS NOT NULL
                     AND DATE(pu.partner_credit_applied_at) BETWEEN ? AND ?
                    THEN pu.partner_credit_earned ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE
                    WHEN COALESCE(pu.partner_credit_used, 0) > 0.009
                     AND DATE(COALESCE(pu.paid_at, pu.created_at)) BETWEEN ? AND ?
                    THEN pu.partner_credit_used ELSE 0 END), 0) AS used
             FROM purchases pu
             WHERE pu.partner_id = ?
               AND pu.status = 'paid'"
        );
        $creditStmt->execute([$fromYmd, $toYmd, $fromYmd, $toYmd, $partnerId]);
        $creditRow = $creditStmt->fetch() ?: [];

        return [
            'self' => (int) ($row['self_n'] ?? 0),
            'code' => (int) ($row['code_n'] ?? 0),
            'batch' => (int) ($row['batch_n'] ?? 0),
            'total_paid_certs' => (int) ($row['total_n'] ?? 0),
            'credit_earned_period' => round((float) ($creditRow['earned'] ?? 0), 2),
            'credit_used_period' => round((float) ($creditRow['used'] ?? 0), 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentCertificationSales(int $partnerId, string $fromYmd, string $toYmd, int $limit = 80): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT pu.id AS purchase_id, pu.matricula, pu.status, pu.paid_at, pu.created_at,
                    pu.partner_credit_earned, pu.partner_credit_used, pu.partner_credit_applied_at,
                    pu.batch_id, pu.charged_amount, pu.catalog_amount, pu.partner_price_amount,
                    pr.id AS product_id, pr.name AS product_name, pr.code AS product_code,
                    t.id AS tracking_id,
                    u.first_name, u.last_name_p, u.email,
                    dc.code AS discount_code,
                    CASE
                        WHEN pu.batch_id IS NOT NULL THEN 'batch'
                        WHEN COALESCE(pu.partner_credit_earned, 0) > 0.009
                          OR (dc.id IS NOT NULL AND dc.partner_id = pu.partner_id) THEN 'code'
                        ELSE 'self'
                    END AS channel
             FROM purchase_items pi
             JOIN purchases pu ON pu.id = pi.purchase_id
             JOIN products pr ON pr.id = pi.product_id
             JOIN users u ON u.id = pu.student_user_id
             LEFT JOIN discount_codes dc ON dc.id = pu.discount_code_id
             LEFT JOIN trackings t ON t.purchase_item_id = pi.id
             WHERE pu.partner_id = ?
               AND pu.status = 'paid'
               AND pr.type = 'certification'
               AND DATE(COALESCE(pu.paid_at, pu.created_at)) BETWEEN ? AND ?
             ORDER BY COALESCE(pu.paid_at, pu.created_at) DESC, pu.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$partnerId, $fromYmd, $toYmd]);
        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        $seenPurchases = [];
        foreach ($rows as $row) {
            $channel = (string) ($row['channel'] ?? 'self');
            $purchaseId = (int) ($row['purchase_id'] ?? 0);
            // El crédito es por compra; solo se muestra en la primera fila de esa compra.
            $showCredit = $purchaseId > 0 && !isset($seenPurchases[$purchaseId]);
            if ($showCredit) {
                $seenPurchases[$purchaseId] = true;
            }
            $out[] = [
                'purchase_id' => $purchaseId,
                'tracking_id' => (int) ($row['tracking_id'] ?? 0),
                'matricula' => (string) ($row['matricula'] ?? ''),
                'product_name' => (string) ($row['product_name'] ?? ''),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'student_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? '')),
                'email' => (string) ($row['email'] ?? ''),
                'paid_at' => (string) ($row['paid_at'] ?? $row['created_at'] ?? ''),
                'channel' => $channel,
                'channel_label' => match ($channel) {
                    'code' => 'Por código',
                    'batch' => 'Lote / grupo',
                    default => 'Registro partner',
                },
                'credit_earned' => $showCredit ? round((float) ($row['partner_credit_earned'] ?? 0), 2) : 0.0,
                'credit_used' => $showCredit ? round((float) ($row['partner_credit_used'] ?? 0), 2) : 0.0,
                'charged_amount' => round((float) ($row['charged_amount'] ?? 0), 2),
                'catalog_amount' => round((float) ($row['catalog_amount'] ?? 0), 2),
                'discount_code' => (string) ($row['discount_code'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public function purchaseStatusCounts(int $partnerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT status, COUNT(*) AS n
             FROM purchases
             WHERE partner_id = ?
             GROUP BY status"
        );
        $stmt->execute([$partnerId]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(string) ($row['status'] ?? '')] = (int) ($row['n'] ?? 0);
        }

        return $out;
    }
}

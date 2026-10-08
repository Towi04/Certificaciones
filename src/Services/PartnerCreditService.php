<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Historial de crédito partner reconstruido desde purchases
 * (abonos: partner_credit_earned + applied_at; usos: partner_credit_used).
 */
final class PartnerCreditService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /**
     * @return array{
     *   balance: float,
     *   month_credits: float,
     *   month_debits: float,
     *   all_credits: float,
     *   all_debits: float,
     *   movements: list<array{
     *     type:string,amount:float,signed:float,at:string,matricula:string,
     *     purchase_id:int,product_name:string,note:string
     *   }>
     * }
     */
    public function historyForPartner(int $partnerId, float $balance): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pu.id, pu.matricula, pu.status, pu.created_at, pu.paid_at,
                    pu.partner_credit_earned, pu.partner_credit_used, pu.partner_credit_applied_at,
                    (
                        SELECT pr.name
                        FROM purchase_items pi
                        JOIN products pr ON pr.id = pi.product_id
                        WHERE pi.purchase_id = pu.id
                        ORDER BY pi.id ASC
                        LIMIT 1
                    ) AS product_name
             FROM purchases pu
             WHERE pu.partner_id = ?
               AND (
                    (COALESCE(pu.partner_credit_earned, 0) > 0.009 AND pu.partner_credit_applied_at IS NOT NULL)
                    OR COALESCE(pu.partner_credit_used, 0) > 0.009
               )
             ORDER BY COALESCE(pu.partner_credit_applied_at, pu.paid_at, pu.created_at) DESC, pu.id DESC
             LIMIT 500"
        );
        $stmt->execute([$partnerId]);
        $rows = $stmt->fetchAll() ?: [];

        $movements = [];
        $allCredits = 0.0;
        $allDebits = 0.0;
        $monthCredits = 0.0;
        $monthDebits = 0.0;
        $monthPrefix = date('Y-m');

        foreach ($rows as $row) {
            $earned = round((float) ($row['partner_credit_earned'] ?? 0), 2);
            $used = round((float) ($row['partner_credit_used'] ?? 0), 2);
            $matricula = (string) ($row['matricula'] ?? '');
            $product = (string) ($row['product_name'] ?? 'Compra');
            $purchaseId = (int) ($row['id'] ?? 0);

            if ($earned > 0.009 && !empty($row['partner_credit_applied_at'])) {
                $at = (string) $row['partner_credit_applied_at'];
                $movements[] = [
                    'type' => 'credit',
                    'amount' => $earned,
                    'signed' => $earned,
                    'at' => $at,
                    'matricula' => $matricula,
                    'purchase_id' => $purchaseId,
                    'product_name' => $product,
                    'note' => 'Abono por compra con tu código',
                ];
                $allCredits += $earned;
                if (str_starts_with($at, $monthPrefix)) {
                    $monthCredits += $earned;
                }
            }

            if ($used > 0.009) {
                $at = (string) ($row['paid_at'] ?? $row['created_at'] ?? '');
                $movements[] = [
                    'type' => 'debit',
                    'amount' => $used,
                    'signed' => -$used,
                    'at' => $at,
                    'matricula' => $matricula,
                    'purchase_id' => $purchaseId,
                    'product_name' => $product,
                    'note' => 'Crédito usado como forma de pago',
                ];
                $allDebits += $used;
                if ($at !== '' && str_starts_with($at, $monthPrefix)) {
                    $monthDebits += $used;
                }
            }
        }

        usort($movements, static function (array $a, array $b): int {
            return strcmp((string) $b['at'], (string) $a['at']);
        });

        return [
            'balance' => round($balance, 2),
            'month_credits' => round($monthCredits, 2),
            'month_debits' => round($monthDebits, 2),
            'all_credits' => round($allCredits, 2),
            'all_debits' => round($allDebits, 2),
            'movements' => $movements,
        ];
    }
}

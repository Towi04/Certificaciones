<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class InventoryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function lotsForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*,
                    (SELECT COUNT(*) FROM inventory_codes c WHERE c.lot_id = l.id) AS codes_total,
                    (SELECT COUNT(*) FROM inventory_codes c WHERE c.lot_id = l.id AND c.status = \'available\') AS codes_available,
                    (SELECT COUNT(*) FROM inventory_codes c WHERE c.lot_id = l.id AND c.status = \'assigned\') AS codes_assigned
             FROM inventory_lots l
             WHERE l.product_id = ?
             ORDER BY l.id DESC'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string, mixed>|null */
    public function findLot(int $lotId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_lots WHERE id = ? LIMIT 1');
        $stmt->execute([$lotId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function createLot(array $data): int
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_lots (product_id, label, purchased_at, cost_total, low_stock_threshold)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            (int) $data['product_id'],
            (string) ($data['label'] ?? ''),
            $data['purchased_at'] ?? null,
            $data['cost_total'] ?? null,
            max(0, (int) ($data['low_stock_threshold'] ?? 5)),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{available:int,assigned:int,expired:int,void:int,total:int}
     */
    public function stockCounts(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS n FROM inventory_codes WHERE product_id = ? GROUP BY status'
        );
        $stmt->execute([$productId]);
        $out = ['available' => 0, 'assigned' => 0, 'expired' => 0, 'void' => 0, 'total' => 0];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $status = (string) ($row['status'] ?? '');
            $n = (int) ($row['n'] ?? 0);
            if (isset($out[$status])) {
                $out[$status] = $n;
            }
            $out['total'] += $n;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function codesForProduct(int $productId, ?string $status = null, int $limit = 200): array
    {
        $sql = 'SELECT c.*, t.exam_date AS tracking_exam_date, pu.matricula
                FROM inventory_codes c
                LEFT JOIN trackings t ON t.id = c.assigned_tracking_id
                LEFT JOIN purchases pu ON pu.id = t.purchase_id
                WHERE c.product_id = ?';
        $params = [$productId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY
            FIELD(c.status, \'available\',\'assigned\',\'expired\',\'void\'),
            c.id DESC
            LIMIT ' . max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string, mixed>|null */
    public function findCode(int $codeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_codes WHERE id = ? LIMIT 1');
        $stmt->execute([$codeId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findAvailableCode(int $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM inventory_codes
             WHERE product_id = ? AND status = \'available\'
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Códigos asignados a exámenes lejanos (candidatos a reasignar).
     *
     * @return list<array<string, mixed>>
     */
    public function stealCandidates(int $productId, int $minFutureDays, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, t.exam_date, t.id AS tracking_id, pu.matricula
             FROM inventory_codes c
             JOIN trackings t ON t.id = c.assigned_tracking_id
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE c.product_id = ?
               AND c.status = \'assigned\'
               AND t.exam_date IS NOT NULL
               AND t.exam_date >= DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY t.exam_date DESC, c.id ASC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$productId, max(1, $minFutureDays)]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Trackings que perdieron código y esperan reposición.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingRestockTrackings(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.*, pu.matricula, pu.status AS purchase_status
             FROM trackings t
             JOIN purchases pu ON pu.id = t.purchase_id
             WHERE t.product_id = ?
               AND pu.status = 'paid'
               AND JSON_EXTRACT(t.extra_json, '$.inventory.needs_restock') = true
             ORDER BY t.exam_date ASC, t.id ASC"
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll() ?: [];
    }

    public function insertCode(array $data): int
    {
        $this->pdo->prepare(
            'INSERT INTO inventory_codes (
                lot_id, product_id, code_primary, code_secondary, code_extra, meta_json, status, expires_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $data['lot_id'],
            (int) $data['product_id'],
            (string) $data['code_primary'],
            $data['code_secondary'] ?? null,
            $data['code_extra'] ?? null,
            isset($data['meta_json']) ? (is_string($data['meta_json']) ? $data['meta_json'] : json_encode($data['meta_json'], JSON_UNESCAPED_UNICODE)) : null,
            (string) ($data['status'] ?? 'available'),
            $data['expires_at'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markAssigned(int $codeId, int $trackingId, ?string $expiresAt): void
    {
        $this->pdo->prepare(
            'UPDATE inventory_codes
             SET status = \'assigned\', assigned_tracking_id = ?, assigned_at = NOW(), expires_at = ?
             WHERE id = ?'
        )->execute([$trackingId, $expiresAt, $codeId]);
    }

    public function markAvailable(int $codeId, ?array $metaMerge = null): void
    {
        $code = $this->findCode($codeId);
        $meta = [];
        if ($code && !empty($code['meta_json'])) {
            $decoded = is_string($code['meta_json']) ? json_decode((string) $code['meta_json'], true) : $code['meta_json'];
            $meta = is_array($decoded) ? $decoded : [];
        }
        if (is_array($metaMerge)) {
            $meta = array_merge($meta, $metaMerge);
        }
        $this->pdo->prepare(
            'UPDATE inventory_codes
             SET status = \'available\', assigned_tracking_id = NULL, assigned_at = NULL,
                 meta_json = ?
             WHERE id = ?'
        )->execute([
            $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            $codeId,
        ]);
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

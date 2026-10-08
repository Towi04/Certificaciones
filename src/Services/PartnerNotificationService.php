<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Centro de notificaciones partner: accesos enviados y resultados listos.
 * Leído/no leído vía partners.notifications_seen_at.
 */
final class PartnerNotificationService
{
    private const LOOKBACK_DAYS = 60;
    private const LIST_LIMIT = 80;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM partners LIKE 'notifications_seen_at'");
            if ($stmt && $stmt->fetch()) {
                $done = true;

                return;
            }
            $this->pdo->exec(
                'ALTER TABLE partners ADD COLUMN notifications_seen_at DATETIME NULL AFTER tutorial_json'
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] PartnerNotificationService::ensureSchema: ' . $e->getMessage());
        }
        $done = true;
    }

    public function seenAt(int $partnerId): ?string
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare('SELECT notifications_seen_at FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$partnerId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $raw;
    }

    public function markAllRead(int $partnerId): void
    {
        $this->ensureSchema();
        $this->pdo->prepare(
            'UPDATE partners SET notifications_seen_at = NOW() WHERE id = ?'
        )->execute([$partnerId]);
    }

    /**
     * @return array{
     *   items: list<array{
     *     key:string,type:string,title:string,body:string,at:string,
     *     unread:bool,tracking_id:int,matricula:string,student_name:string,product_name:string
     *   }>,
     *   unread_count:int,
     *   seen_at:?string
     * }
     */
    public function listForPartner(int $partnerId): array
    {
        $this->ensureSchema();
        $seenAt = $this->seenAt($partnerId);
        $items = [];

        foreach ($this->accessMailEvents($partnerId) as $row) {
            $items[] = $row;
        }
        foreach ($this->resultsEvents($partnerId) as $row) {
            $items[] = $row;
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) $b['at'], (string) $a['at']);
        });
        $items = array_slice($items, 0, self::LIST_LIMIT);

        $unread = 0;
        foreach ($items as &$item) {
            $item['unread'] = $seenAt === null || strcmp((string) $item['at'], $seenAt) > 0;
            if ($item['unread']) {
                $unread++;
            }
        }
        unset($item);

        return [
            'items' => $items,
            'unread_count' => $unread,
            'seen_at' => $seenAt,
        ];
    }

    public function unreadCount(int $partnerId): int
    {
        return $this->listForPartner($partnerId)['unread_count'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accessMailEvents(int $partnerId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT ml.id, ml.created_at, ml.tracking_id, ml.subject, ml.status,
                        mt.code AS template_code, mt.name AS template_name,
                        t.id AS tid, pu.matricula, pr.name AS product_name,
                        u.first_name, u.last_name_p
                 FROM mail_logs ml
                 INNER JOIN trackings t ON t.id = ml.tracking_id AND t.partner_id = ?
                 INNER JOIN purchases pu ON pu.id = t.purchase_id
                 INNER JOIN products pr ON pr.id = t.product_id
                 INNER JOIN users u ON u.id = t.student_user_id
                 LEFT JOIN mail_templates mt ON mt.id = ml.mail_template_id
                 WHERE ml.status = 'sent'
                   AND ml.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 ORDER BY ml.created_at DESC
                 LIMIT 200"
            );
            $stmt->execute([$partnerId, self::LOOKBACK_DAYS]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[Doceo] partner notifications access: ' . $e->getMessage());

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['template_code'] ?? ''));
            if ($code === '' || !MailLogService::templateLooksLikeAccess($code)) {
                continue;
            }
            if (MailTemplateService::audienceForTemplate($code) !== 'student') {
                continue;
            }
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''));
            $matricula = (string) ($row['matricula'] ?? '');
            $product = (string) ($row['product_name'] ?? '');
            $tid = (int) ($row['tid'] ?? $row['tracking_id'] ?? 0);
            $out[] = [
                'key' => 'access:' . (int) ($row['id'] ?? 0),
                'type' => 'access',
                'title' => 'Se enviaron accesos',
                'body' => 'Accesos enviados a ' . ($name !== '' ? $name : 'alumno')
                    . ($matricula !== '' ? ' (' . $matricula . ')' : '')
                    . ($product !== '' ? ' · ' . $product : ''),
                'at' => (string) ($row['created_at'] ?? ''),
                'tracking_id' => $tid,
                'matricula' => $matricula,
                'student_name' => $name,
                'product_name' => $product,
            ];
        }

        return $out;
    }

    /**
     * Resultados: correo de resultados al alumno, o caso con nivel/puntaje/URL ya cargados.
     *
     * @return list<array<string, mixed>>
     */
    private function resultsEvents(int $partnerId): array
    {
        $out = [];
        $seenTrackings = [];

        try {
            $stmt = $this->pdo->prepare(
                "SELECT ml.id, ml.created_at, ml.tracking_id, mt.code AS template_code,
                        t.id AS tid, pu.matricula, pr.name AS product_name,
                        u.first_name, u.last_name_p
                 FROM mail_logs ml
                 INNER JOIN trackings t ON t.id = ml.tracking_id AND t.partner_id = ?
                 INNER JOIN purchases pu ON pu.id = t.purchase_id
                 INNER JOIN products pr ON pr.id = t.product_id
                 INNER JOIN users u ON u.id = t.student_user_id
                 LEFT JOIN mail_templates mt ON mt.id = ml.mail_template_id
                 WHERE ml.status = 'sent'
                   AND ml.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 ORDER BY ml.created_at DESC
                 LIMIT 200"
            );
            $stmt->execute([$partnerId, self::LOOKBACK_DAYS]);
            $mailRows = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[Doceo] partner notifications results mail: ' . $e->getMessage());
            $mailRows = [];
        }

        foreach ($mailRows as $row) {
            $code = trim((string) ($row['template_code'] ?? ''));
            if ($code === '' || !MailLogService::templateLooksLikeResults($code)) {
                continue;
            }
            if (MailTemplateService::audienceForTemplate($code) !== 'student') {
                continue;
            }
            $tid = (int) ($row['tid'] ?? $row['tracking_id'] ?? 0);
            if ($tid > 0) {
                $seenTrackings[$tid] = true;
            }
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''));
            $matricula = (string) ($row['matricula'] ?? '');
            $product = (string) ($row['product_name'] ?? '');
            $out[] = [
                'key' => 'results_mail:' . (int) ($row['id'] ?? 0),
                'type' => 'results',
                'title' => 'Resultados listos',
                'body' => 'Resultados enviados a ' . ($name !== '' ? $name : 'alumno')
                    . ($matricula !== '' ? ' (' . $matricula . ')' : '')
                    . ($product !== '' ? ' · ' . $product : ''),
                'at' => (string) ($row['created_at'] ?? ''),
                'tracking_id' => $tid,
                'matricula' => $matricula,
                'student_name' => $name,
                'product_name' => $product,
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT t.id, t.results_level, t.results_score, t.results_url, t.updated_at, t.created_at,
                        pu.matricula, pr.name AS product_name,
                        u.first_name, u.last_name_p
                 FROM trackings t
                 INNER JOIN purchases pu ON pu.id = t.purchase_id
                 INNER JOIN products pr ON pr.id = t.product_id
                 INNER JOIN users u ON u.id = t.student_user_id
                 WHERE t.partner_id = ?
                   AND (
                        (t.results_level IS NOT NULL AND TRIM(t.results_level) <> '')
                        OR t.results_score IS NOT NULL
                        OR (t.results_url IS NOT NULL AND TRIM(t.results_url) <> '')
                   )
                   AND COALESCE(t.updated_at, t.created_at) >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 ORDER BY COALESCE(t.updated_at, t.created_at) DESC
                 LIMIT 100"
            );
            $stmt->execute([$partnerId, self::LOOKBACK_DAYS]);
            $trackRows = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[Doceo] partner notifications results data: ' . $e->getMessage());
            $trackRows = [];
        }

        foreach ($trackRows as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid < 1 || isset($seenTrackings[$tid])) {
                continue;
            }
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''));
            $matricula = (string) ($row['matricula'] ?? '');
            $product = (string) ($row['product_name'] ?? '');
            $level = trim((string) ($row['results_level'] ?? ''));
            $score = $row['results_score'] ?? null;
            $detail = [];
            if ($level !== '') {
                $detail[] = 'Nivel ' . $level;
            }
            if ($score !== null && $score !== '') {
                $detail[] = 'Puntaje ' . (string) $score;
            }
            $out[] = [
                'key' => 'results:' . $tid,
                'type' => 'results',
                'title' => 'Resultados listos',
                'body' => 'Ya hay resultados para ' . ($name !== '' ? $name : 'alumno')
                    . ($matricula !== '' ? ' (' . $matricula . ')' : '')
                    . ($product !== '' ? ' · ' . $product : '')
                    . ($detail !== [] ? ' · ' . implode(', ', $detail) : ''),
                'at' => (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
                'tracking_id' => $tid,
                'matricula' => $matricula,
                'student_name' => $name,
                'product_name' => $product,
            ];
        }

        return $out;
    }
}

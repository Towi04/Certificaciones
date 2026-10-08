<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Historial de correos en mail_logs (por tracking / compra).
 */
final class MailLogService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /**
     * @param array{
     *   mail_template_id?:?int,
     *   template_code?:?string,
     *   purchase_id?:?int,
     *   tracking_id?:?int,
     *   to_email:string,
     *   cc_email?:?string,
     *   subject:string,
     *   body_html?:?string,
     *   status:string,
     *   error_message?:?string,
     *   triggered_by?:?int
     * } $data
     */
    public function record(array $data): void
    {
        $to = trim((string) ($data['to_email'] ?? ''));
        if ($to === '') {
            return;
        }
        $status = (string) ($data['status'] ?? 'sent');
        if (!in_array($status, ['sent', 'failed'], true)) {
            $status = 'failed';
        }
        $templateId = isset($data['mail_template_id']) ? (int) $data['mail_template_id'] : 0;
        if ($templateId < 1 && !empty($data['template_code'])) {
            $templateId = $this->templateIdByCode((string) $data['template_code']);
        }
        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            $subject = '(sin asunto)';
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO mail_logs
                    (mail_template_id, purchase_id, tracking_id, to_email, cc_email, subject, body_html,
                     status, error_message, triggered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $templateId > 0 ? $templateId : null,
                !empty($data['purchase_id']) ? (int) $data['purchase_id'] : null,
                !empty($data['tracking_id']) ? (int) $data['tracking_id'] : null,
                mb_substr($to, 0, 190),
                ($data['cc_email'] ?? null) !== null && trim((string) $data['cc_email']) !== ''
                    ? mb_substr(trim((string) $data['cc_email']), 0, 255)
                    : null,
                mb_substr($subject, 0, 255),
                isset($data['body_html']) ? (string) $data['body_html'] : null,
                $status,
                isset($data['error_message']) && $data['error_message'] !== null
                    ? mb_substr((string) $data['error_message'], 0, 2000)
                    : null,
                !empty($data['triggered_by']) ? (int) $data['triggered_by'] : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Doceo] mail_logs insert: ' . $e->getMessage());
        }
    }

    /**
     * Correos al alumno del caso: audiencia student; excluye marketing/provider.
     * Prefiere filas cuyo to_email coincida con el correo del alumno.
     *
     * @return list<array<string, mixed>>
     */
    public function studentMailsForTracking(int $trackingId, ?int $purchaseId, string $studentEmail): array
    {
        if ($trackingId < 1 && ($purchaseId === null || $purchaseId < 1)) {
            return [];
        }
        $sql = 'SELECT ml.*, mt.code AS template_code, mt.name AS template_name
                FROM mail_logs ml
                LEFT JOIN mail_templates mt ON mt.id = ml.mail_template_id
                WHERE (';
        $params = [];
        $parts = [];
        if ($trackingId > 0) {
            $parts[] = 'ml.tracking_id = ?';
            $params[] = $trackingId;
        }
        if ($purchaseId !== null && $purchaseId > 0) {
            $parts[] = 'ml.purchase_id = ?';
            $params[] = $purchaseId;
        }
        $sql .= implode(' OR ', $parts) . ') ORDER BY ml.created_at DESC, ml.id DESC LIMIT 100';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[Doceo] mail_logs query: ' . $e->getMessage());

            return [];
        }

        $studentEmail = strtolower(trim($studentEmail));
        $out = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['template_code'] ?? ''));
            $audience = $code !== ''
                ? MailTemplateService::audienceForTemplate($code)
                : 'student';
            if (in_array($audience, ['marketing', 'provider', 'partner'], true)) {
                continue;
            }
            if ($audience !== 'student') {
                continue;
            }
            $to = strtolower(trim((string) ($row['to_email'] ?? '')));
            if ($studentEmail !== '' && $to !== '' && $to !== $studentEmail) {
                // Refuerzo: omitir correos claramente a otro destinatario.
                continue;
            }
            $row['audience'] = $audience;
            $row['is_access'] = self::templateLooksLikeAccess($code);
            $row['is_results'] = self::templateLooksLikeResults($code);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Último correo de accesos (por tracking) para tablero partner.
     *
     * @param list<int> $trackingIds
     * @return array<int, array<string, mixed>>
     */
    public function latestAccessMailByTrackingIds(array $trackingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $trackingIds))));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $this->pdo->prepare(
                "SELECT ml.*, mt.code AS template_code, mt.name AS template_name
                 FROM mail_logs ml
                 LEFT JOIN mail_templates mt ON mt.id = ml.mail_template_id
                 WHERE ml.tracking_id IN ({$placeholders})
                 ORDER BY ml.created_at DESC, ml.id DESC"
            );
            $stmt->execute($ids);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            error_log('[Doceo] mail_logs access board: ' . $e->getMessage());

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $tid = (int) ($row['tracking_id'] ?? 0);
            if ($tid < 1 || isset($out[$tid])) {
                continue;
            }
            $code = trim((string) ($row['template_code'] ?? ''));
            if ($code === '' || !self::templateLooksLikeAccess($code)) {
                continue;
            }
            if (MailTemplateService::audienceForTemplate($code) !== 'student') {
                continue;
            }
            $out[$tid] = $row;
        }

        return $out;
    }

    public static function templateLooksLikeAccess(string $code): bool
    {
        $lower = mb_strtolower(trim($code));
        if ($lower === '') {
            return false;
        }

        return str_contains($lower, 'acceso')
            || str_contains($lower, 'access')
            || str_contains($lower, 'folio')
            || str_contains($lower, 'clave');
    }

    public static function templateLooksLikeResults(string $code): bool
    {
        $lower = mb_strtolower(trim($code));
        if ($lower === '') {
            return false;
        }

        return str_contains($lower, 'result')
            || str_contains($lower, 'certific')
            || str_contains($lower, 'cenni')
            || str_contains($lower, 'score');
    }

    private function templateIdByCode(string $code): int
    {
        $code = trim($code);
        if ($code === '') {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM mail_templates WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }
}

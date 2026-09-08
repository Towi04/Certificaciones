<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Cola simple de correos / jobs diferidos (p. ej. acceso 3 días antes del examen).
 */
final class ScheduledMailService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->ensureTable();
    }

    public static function schedule(
        int $trackingId,
        string $jobCode,
        string $sendAt,
        array $payload = []
    ): void {
        (new self())->upsert($trackingId, $jobCode, $sendAt, $payload);
    }

    public static function cancelPending(int $trackingId, string $jobCode): void
    {
        (new self())->cancel($trackingId, $jobCode);
    }

    public function upsert(int $trackingId, string $jobCode, string $sendAt, array $payload = []): void
    {
        $jobCode = trim($jobCode);
        if ($trackingId < 1 || $jobCode === '') {
            return;
        }
        $sendAt = trim($sendAt);
        if ($sendAt === '') {
            return;
        }
        // Cancelar pendientes previos del mismo job.
        $this->cancel($trackingId, $jobCode);
        $this->pdo->prepare(
            'INSERT INTO scheduled_mails (tracking_id, job_code, send_at, payload_json, status)
             VALUES (?, ?, ?, ?, \'pending\')'
        )->execute([
            $trackingId,
            $jobCode,
            $sendAt,
            $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function cancel(int $trackingId, string $jobCode): void
    {
        $this->pdo->prepare(
            'UPDATE scheduled_mails
             SET status = \'cancelled\', processed_at = NOW()
             WHERE tracking_id = ? AND job_code = ? AND status = \'pending\''
        )->execute([$trackingId, $jobCode]);
    }

    /**
     * @return array{processed:int,errors:list<string>}
     */
    public function processDue(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scheduled_mails
             WHERE status = \'pending\' AND send_at <= NOW()
             ORDER BY send_at ASC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        $processed = 0;
        $errors = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            try {
                $this->runJob($row);
                $this->pdo->prepare(
                    'UPDATE scheduled_mails SET status = \'sent\', processed_at = NOW() WHERE id = ?'
                )->execute([$id]);
                $processed++;
            } catch (\Throwable $e) {
                $this->pdo->prepare(
                    'UPDATE scheduled_mails SET status = \'failed\', processed_at = NOW(), last_error = ? WHERE id = ?'
                )->execute([mb_substr($e->getMessage(), 0, 500), $id]);
                $errors[] = '#' . $id . ': ' . $e->getMessage();
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /** @param array<string, mixed> $row */
    private function runJob(array $row): void
    {
        $trackingId = (int) ($row['tracking_id'] ?? 0);
        $job = (string) ($row['job_code'] ?? '');
        $payload = [];
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string) $row['payload_json'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        if ($job === 'inventory_assign_and_mail') {
            $result = (new InventoryService())->processTracking($trackingId, null);
            $action = (string) ($result['action'] ?? '');
            if ($action === 'assign_failed') {
                throw new \RuntimeException($result['detail'] ?? 'assign_failed');
            }

            return;
        }

        // Job = código de plantilla directa.
        $tracking = (new TrackingService())->find($trackingId);
        if ($tracking === null) {
            throw new \RuntimeException('Tracking no encontrado.');
        }
        $mail = new MailTemplateService();
        $vars = (new StepMailService())->buildVars($tracking);
        $to = trim((string) ($tracking['student_email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Sin correo de alumno.');
        }
        $template = trim((string) ($payload['template'] ?? $job));
        if ($mail->render($template, $vars) === null) {
            throw new \RuntimeException('Plantilla no encontrada: ' . $template);
        }
        $mail->send($template, $to, $vars);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS scheduled_mails (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tracking_id BIGINT UNSIGNED NOT NULL,
                job_code VARCHAR(80) NOT NULL,
                send_at DATETIME NOT NULL,
                payload_json JSON NULL,
                status ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
                last_error VARCHAR(500) NULL,
                processed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sched_due (status, send_at),
                KEY idx_sched_tracking (tracking_id, job_code, status),
                CONSTRAINT fk_sched_tracking FOREIGN KEY (tracking_id) REFERENCES trackings(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

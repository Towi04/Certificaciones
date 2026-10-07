<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Connection;
use PDO;

/**
 * Campañas de publicidad: audiencia (clientes unificados + partners),
 * Promo DOCEO por mes y programación anti-spam automática en un rango de fechas.
 */
final class MarketingCampaignService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        (new MarketingContactService())->ensureTables();
        $this->ensureTables();
    }

    public function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS marketing_campaigns (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                mail_template_code VARCHAR(80) NOT NULL,
                promo_code VARCHAR(40) NULL,
                audience_json JSON NOT NULL,
                status ENUM('draft','scheduled','running','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
                window_start DATE NOT NULL,
                window_end DATE NOT NULL,
                interval_seconds INT UNSIGNED NOT NULL DEFAULT 120,
                day_hour_start TINYINT UNSIGNED NOT NULL DEFAULT 9,
                day_hour_end TINYINT UNSIGNED NOT NULL DEFAULT 18,
                max_per_run INT UNSIGNED NOT NULL DEFAULT 15,
                created_by BIGINT UNSIGNED NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_mkt_campaigns_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS marketing_campaign_recipients (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                campaign_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(190) NOT NULL,
                full_name VARCHAR(255) NOT NULL DEFAULT '',
                first_name VARCHAR(120) NOT NULL DEFAULT '',
                phone VARCHAR(40) NULL,
                product_label VARCHAR(190) NULL,
                source VARCHAR(40) NOT NULL DEFAULT 'student',
                source_ref VARCHAR(80) NULL,
                scheduled_at DATETIME NOT NULL,
                status ENUM('pending','sent','failed','skipped','cancelled') NOT NULL DEFAULT 'pending',
                sent_at DATETIME NULL,
                last_error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mkt_recip_campaign_email (campaign_id, email),
                KEY idx_mkt_recip_due (status, scheduled_at),
                KEY idx_mkt_recip_campaign (campaign_id, status),
                CONSTRAINT fk_mkt_recip_campaign FOREIGN KEY (campaign_id)
                    REFERENCES marketing_campaigns(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return list<array<string, mixed>> */
    public function listCampaigns(int $limit = 100): array
    {
        $stmt = $this->pdo->query(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id = c.id) AS recipients_total,
                    (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id = c.id AND r.status = \'sent\') AS recipients_sent,
                    (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id = c.id AND r.status = \'pending\') AS recipients_pending,
                    (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id = c.id AND r.status = \'failed\') AS recipients_failed,
                    (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id = c.id AND r.status = \'cancelled\') AS recipients_cancelled
             FROM marketing_campaigns c
             ORDER BY c.id DESC
             LIMIT ' . max(1, min(200, $limit))
        );

        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM marketing_campaigns WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function recipients(int $campaignId, ?string $status = null, int $limit = 200, int $offset = 0): array
    {
        $sql = 'SELECT * FROM marketing_campaign_recipients WHERE campaign_id = ?';
        $params = [$campaignId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY scheduled_at ASC, id ASC LIMIT '
            . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string, int> */
    public function recipientCounts(int $campaignId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS c FROM marketing_campaign_recipients
             WHERE campaign_id = ? GROUP BY status'
        );
        $stmt->execute([$campaignId]);
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0, 'total' => 0];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $st = (string) ($row['status'] ?? '');
            $n = (int) ($row['c'] ?? 0);
            if (isset($out[$st])) {
                $out[$st] = $n;
            }
            $out['total'] += $n;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, ?int $adminUserId): int
    {
        $data = $this->normalizeCampaignInput($input);
        $this->pdo->prepare(
            'INSERT INTO marketing_campaigns
                (name, mail_template_code, promo_code, audience_json, status,
                 window_start, window_end, interval_seconds, day_hour_start, day_hour_end,
                 max_per_run, created_by, notes)
             VALUES (?, ?, ?, ?, \'draft\', ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['name'],
            $data['mail_template_code'],
            $data['promo_code'],
            json_encode($data['audience'], JSON_UNESCAPED_UNICODE),
            $data['window_start'],
            $data['window_end'],
            $data['interval_seconds'],
            $data['day_hour_start'],
            $data['day_hour_end'],
            $data['max_per_run'],
            $adminUserId,
            $data['notes'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input): void
    {
        $campaign = $this->find($id);
        if ($campaign === null) {
            throw new \InvalidArgumentException('Campaña no encontrada.');
        }
        if (!in_array((string) $campaign['status'], ['draft', 'paused'], true)) {
            throw new \InvalidArgumentException('Solo se pueden editar campañas en borrador o pausadas.');
        }
        $data = $this->normalizeCampaignInput($input);
        $this->pdo->prepare(
            'UPDATE marketing_campaigns SET
                name = ?, mail_template_code = ?, promo_code = ?, audience_json = ?,
                window_start = ?, window_end = ?, interval_seconds = ?,
                day_hour_start = ?, day_hour_end = ?, max_per_run = ?, notes = ?
             WHERE id = ?'
        )->execute([
            $data['name'],
            $data['mail_template_code'],
            $data['promo_code'],
            json_encode($data['audience'], JSON_UNESCAPED_UNICODE),
            $data['window_start'],
            $data['window_end'],
            $data['interval_seconds'],
            $data['day_hour_start'],
            $data['day_hour_end'],
            $data['max_per_run'],
            $data['notes'],
            $id,
        ]);
    }

    /**
     * Vista previa de audiencia sin guardar destinatarios.
     *
     * @param array<string, mixed>|null $audience
     * @return array{count:int,samples:list<array<string,mixed>>}
     */
    public function previewAudience(?array $audience, int $sampleLimit = 12): array
    {
        $recipients = $this->resolveAudience($audience ?? []);
        $samples = array_slice($recipients, 0, max(1, min(30, $sampleLimit)));

        return ['count' => count($recipients), 'samples' => $samples];
    }

    /**
     * Encola destinatarios y pone la campaña en running (o scheduled si aún no inicia la ventana).
     *
     * @return array{recipients:int,status:string}
     */
    public function start(int $id): array
    {
        $campaign = $this->find($id);
        if ($campaign === null) {
            throw new \InvalidArgumentException('Campaña no encontrada.');
        }
        if (!in_array((string) $campaign['status'], ['draft', 'paused'], true)) {
            throw new \InvalidArgumentException('La campaña ya fue iniciada o cancelada.');
        }

        $tpl = (string) $campaign['mail_template_code'];
        if ((new MailTemplateService())->render($tpl, ['name' => 'Prueba']) === null) {
            throw new \InvalidArgumentException('Plantilla de correo no encontrada o inactiva: ' . $tpl);
        }

        $audience = json_decode((string) ($campaign['audience_json'] ?? '{}'), true);
        if (!is_array($audience)) {
            $audience = [];
        }
        $recipients = $this->resolveAudience($audience);
        if ($recipients === []) {
            throw new \InvalidArgumentException('La audiencia no tiene destinatarios con correo válido.');
        }

        $auto = $this->autoAntiSpamParams(
            (string) $campaign['window_start'],
            (string) $campaign['window_end'],
            count($recipients)
        );
        $promoCode = $this->resolvePromoCode($audience, (string) $campaign['window_start']);

        $times = $this->buildSchedule(
            (string) $campaign['window_start'],
            (string) $campaign['window_end'],
            $auto['interval_seconds'],
            $auto['day_hour_start'],
            $auto['day_hour_end'],
            count($recipients)
        );

        $this->pdo->beginTransaction();
        try {
            // Reiniciar cola si estaba pausada/borrador con restos.
            $this->pdo->prepare(
                'DELETE FROM marketing_campaign_recipients WHERE campaign_id = ? AND status IN (\'pending\',\'cancelled\')'
            )->execute([$id]);

            $ins = $this->pdo->prepare(
                'INSERT INTO marketing_campaign_recipients
                    (campaign_id, email, full_name, first_name, phone, product_label, source, source_ref, scheduled_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\')
                 ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    first_name = VALUES(first_name),
                    phone = VALUES(phone),
                    product_label = VALUES(product_label),
                    source = VALUES(source),
                    source_ref = VALUES(source_ref),
                    scheduled_at = VALUES(scheduled_at),
                    status = IF(status = \'sent\', status, \'pending\'),
                    last_error = NULL'
            );

            foreach ($recipients as $i => $r) {
                $ins->execute([
                    $id,
                    $r['email'],
                    $r['full_name'],
                    $r['first_name'],
                    $r['phone'],
                    $r['product_label'],
                    $r['source'],
                    $r['source_ref'],
                    $times[$i]->format('Y-m-d H:i:s'),
                ]);
            }

            $now = new \DateTimeImmutable('now');
            $windowStart = new \DateTimeImmutable((string) $campaign['window_start'] . ' 00:00:00');
            $status = $now < $windowStart ? 'scheduled' : 'running';

            $this->pdo->prepare(
                'UPDATE marketing_campaigns
                 SET status = ?, started_at = COALESCE(started_at, NOW()), completed_at = NULL,
                     interval_seconds = ?, day_hour_start = ?, day_hour_end = ?, max_per_run = ?,
                     promo_code = ?
                 WHERE id = ?'
            )->execute([
                $status,
                $auto['interval_seconds'],
                $auto['day_hour_start'],
                $auto['day_hour_end'],
                $auto['max_per_run'],
                $promoCode !== '' ? $promoCode : null,
                $id,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['recipients' => count($recipients), 'status' => $status];
    }

    public function pause(int $id): void
    {
        $this->setStatus($id, ['running', 'scheduled'], 'paused');
    }

    public function resume(int $id): void
    {
        $campaign = $this->find($id);
        if ($campaign === null || (string) $campaign['status'] !== 'paused') {
            throw new \InvalidArgumentException('Solo se pueden reanudar campañas pausadas.');
        }
        $now = new \DateTimeImmutable('now');
        $windowStart = new \DateTimeImmutable((string) $campaign['window_start'] . ' 00:00:00');
        $status = $now < $windowStart ? 'scheduled' : 'running';
        $this->pdo->prepare('UPDATE marketing_campaigns SET status = ? WHERE id = ?')
            ->execute([$status, $id]);
    }

    public function cancel(int $id): void
    {
        $campaign = $this->find($id);
        if ($campaign === null) {
            throw new \InvalidArgumentException('Campaña no encontrada.');
        }
        $this->pdo->prepare(
            'UPDATE marketing_campaign_recipients SET status = \'cancelled\'
             WHERE campaign_id = ? AND status = \'pending\''
        )->execute([$id]);
        $this->pdo->prepare(
            'UPDATE marketing_campaigns SET status = \'cancelled\', completed_at = NOW() WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Procesa destinatarios vencidos de campañas activas (llamar desde cron).
     *
     * @return array{processed:int,errors:list<string>}
     */
    public function processDue(?int $limit = null): array
    {
        // Activar scheduled cuya ventana ya empezó.
        $this->pdo->exec(
            "UPDATE marketing_campaigns
             SET status = 'running'
             WHERE status = 'scheduled' AND window_start <= CURDATE()"
        );

        $stmt = $this->pdo->query(
            "SELECT id, mail_template_code, promo_code, audience_json, max_per_run, window_start, window_end,
                    day_hour_start, day_hour_end
             FROM marketing_campaigns
             WHERE status = 'running'"
        );
        $campaigns = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $processed = 0;
        $errors = [];

        foreach ($campaigns as $campaign) {
            $campaignId = (int) $campaign['id'];
            $windowEnd = (string) $campaign['window_end'];
            if ($windowEnd !== '' && $windowEnd < date('Y-m-d')) {
                $this->markCompletedIfDone($campaignId);
                continue;
            }

            $hour = (int) date('G');
            $hStart = (int) $campaign['day_hour_start'];
            $hEnd = (int) $campaign['day_hour_end'];
            if ($hour < $hStart || $hour >= $hEnd) {
                continue;
            }

            $batch = $limit ?? (int) $campaign['max_per_run'];
            $batch = max(1, min(50, $batch));

            $due = $this->pdo->prepare(
                "SELECT * FROM marketing_campaign_recipients
                 WHERE campaign_id = ? AND status = 'pending' AND scheduled_at <= NOW()
                 ORDER BY scheduled_at ASC, id ASC
                 LIMIT {$batch}"
            );
            $due->execute([$campaignId]);
            $rows = $due->fetchAll() ?: [];

            foreach ($rows as $row) {
                try {
                    $this->sendOne($campaign, $row);
                    $this->pdo->prepare(
                        'UPDATE marketing_campaign_recipients
                         SET status = \'sent\', sent_at = NOW(), last_error = NULL WHERE id = ?'
                    )->execute([(int) $row['id']]);
                    $processed++;
                } catch (\Throwable $e) {
                    $msg = mb_substr($e->getMessage(), 0, 500);
                    $this->pdo->prepare(
                        'UPDATE marketing_campaign_recipients
                         SET status = \'failed\', last_error = ? WHERE id = ?'
                    )->execute([$msg, (int) $row['id']]);
                    $errors[] = 'Campaña #' . $campaignId . ' · ' . ($row['email'] ?? '') . ': ' . $msg;
                }
            }

            $this->markCompletedIfDone($campaignId);
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $audience
     * @return list<array{
     *   email:string,full_name:string,first_name:string,phone:?string,
     *   product_label:?string,source:string,source_ref:?string
     * }>
     */
    public function resolveAudience(array $audience): array
    {
        $includePartners = !empty($audience['include_partners']);
        $includeClients = $this->audienceIncludesClients($audience);
        if (!$includeClients && !$includePartners) {
            $includeClients = true;
        }

        $productId = isset($audience['product_id']) && (int) $audience['product_id'] > 0
            ? (int) $audience['product_id'] : null;
        $certifierId = isset($audience['certifier_id']) && (int) $audience['certifier_id'] > 0
            ? (int) $audience['certifier_id'] : null;
        // Si hay producto concreto, el filtro de certificadora es redundante.
        if ($productId !== null) {
            $certifierId = null;
        }

        /** @var array<string, array<string, mixed>> $byEmail */
        $byEmail = [];

        if ($includeClients) {
            foreach ($this->fetchStudentBuyers($productId, $certifierId) as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $byEmail[$email] = [
                    'email' => $email,
                    'full_name' => (string) ($row['full_name'] ?? ''),
                    'first_name' => (string) ($row['first_name'] ?? ''),
                    'phone' => ($row['phone'] ?? null) !== null && $row['phone'] !== ''
                        ? (string) $row['phone'] : null,
                    'product_label' => ($row['product_label'] ?? null) !== null
                        ? (string) $row['product_label'] : null,
                    'source' => 'student',
                    'source_ref' => isset($row['user_id']) ? ('user:' . (int) $row['user_id']) : null,
                ];
            }

            foreach ($this->fetchLegacy($productId, $certifierId) as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if (!empty($row['opted_out_at'])) {
                    continue;
                }
                if (isset($byEmail[$email])) {
                    continue;
                }
                $byEmail[$email] = [
                    'email' => $email,
                    'full_name' => (string) ($row['full_name'] ?? ''),
                    'first_name' => (string) ($row['first_name'] ?? ''),
                    'phone' => ($row['phone'] ?? null) !== null && $row['phone'] !== ''
                        ? (string) $row['phone'] : null,
                    'product_label' => ($row['product_name'] ?? null) !== null
                        ? (string) $row['product_name'] : null,
                    'source' => 'legacy',
                    'source_ref' => isset($row['id']) ? ('legacy:' . (int) $row['id']) : null,
                ];
            }
        }

        if ($includePartners) {
            foreach ($this->fetchPartners() as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if (isset($byEmail[$email])) {
                    continue;
                }
                $byEmail[$email] = [
                    'email' => $email,
                    'full_name' => (string) ($row['full_name'] ?? $row['display_name'] ?? ''),
                    'first_name' => (string) ($row['first_name'] ?? ''),
                    'phone' => ($row['phone'] ?? null) !== null && $row['phone'] !== ''
                        ? (string) $row['phone'] : null,
                    'product_label' => 'Partner',
                    'source' => 'partner',
                    'source_ref' => isset($row['partner_id']) ? ('partner:' . (int) $row['partner_id']) : null,
                ];
            }
        }

        return array_values($byEmail);
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function buildSchedule(
        string $windowStart,
        string $windowEnd,
        int $intervalSeconds,
        int $dayHourStart,
        int $dayHourEnd,
        int $count
    ): array {
        if ($count < 1) {
            return [];
        }
        $intervalSeconds = max(30, $intervalSeconds);
        $dayHourStart = max(0, min(23, $dayHourStart));
        $dayHourEnd = max($dayHourStart + 1, min(24, $dayHourEnd));

        $start = new \DateTimeImmutable($windowStart . sprintf(' %02d:00:00', $dayHourStart));
        $end = new \DateTimeImmutable($windowEnd . sprintf(' %02d:00:00', min(23, $dayHourEnd)));
        if ($end <= $start) {
            $end = $start->modify('+1 hour');
        }

        // Si el inicio ya pasó, arrancar «ahora» redondeado al siguiente minuto útil.
        $now = new \DateTimeImmutable('now');
        if ($start < $now) {
            $start = $now->modify('+1 minute')->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
            if ((int) $start->format('G') >= $dayHourEnd) {
                $start = $start->modify('+1 day')->setTime($dayHourStart, 0, 0);
            } elseif ((int) $start->format('G') < $dayHourStart) {
                $start = $start->setTime($dayHourStart, 0, 0);
            }
            if ($start > $end) {
                $end = $start->modify('+' . max(1, (int) ceil(($count * $intervalSeconds) / 3600)) . ' hours');
            }
        }

        $times = [];
        $cursor = $start;
        for ($i = 0; $i < $count; $i++) {
            $times[] = $cursor;
            $cursor = $this->advanceWithinWindow($cursor, $intervalSeconds, $dayHourStart, $dayHourEnd, $end);
        }

        // Si el último se salió de la ventana, redistribuir uniformemente.
        $last = $times[$count - 1];
        if ($last > $end && $count > 1) {
            $span = max(60, $end->getTimestamp() - $start->getTimestamp());
            $step = (int) max(30, floor($span / ($count - 1)));
            $times = [];
            $cursor = $start;
            for ($i = 0; $i < $count; $i++) {
                if ($cursor > $end) {
                    $cursor = $end;
                }
                $times[] = $cursor;
                $cursor = $this->advanceWithinWindow($cursor, $step, $dayHourStart, $dayHourEnd, $end);
            }
        }

        return $times;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   name:string,mail_template_code:string,promo_code:?string,audience:array<string,mixed>,
     *   window_start:string,window_end:string,interval_seconds:int,
     *   day_hour_start:int,day_hour_end:int,max_per_run:int,notes:?string
     * }
     */
    private function normalizeCampaignInput(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Indica un nombre para la campaña.');
        }
        $tpl = trim((string) ($input['mail_template_code'] ?? ''));
        if ($tpl === '') {
            throw new \InvalidArgumentException('Elige una plantilla de correo.');
        }
        $windowStart = trim((string) ($input['window_start'] ?? ''));
        $windowEnd = trim((string) ($input['window_end'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $windowStart)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $windowEnd)
        ) {
            throw new \InvalidArgumentException('Indica un rango de fechas válido (inicio y fin).');
        }
        if ($windowEnd < $windowStart) {
            throw new \InvalidArgumentException('La fecha fin no puede ser anterior al inicio.');
        }

        // Anti-spam: el admin solo define el rango; el ritmo se calcula al iniciar.
        $auto = $this->autoAntiSpamParams($windowStart, $windowEnd, 100);

        $mode = trim((string) ($input['audience_mode'] ?? ''));
        $includeClients = true;
        $includePartners = false;
        if ($mode === 'partners') {
            $includeClients = false;
            $includePartners = true;
        } elseif ($mode === 'both') {
            $includeClients = true;
            $includePartners = true;
        } elseif ($mode === 'clients') {
            $includeClients = true;
            $includePartners = false;
        } else {
            // Compatibilidad con formularios antiguos / API.
            $includePartners = !empty($input['include_partners']);
            if (array_key_exists('include_clients', $input)) {
                $includeClients = !empty($input['include_clients']);
            } else {
                $includeClients = !empty($input['include_students'])
                    || !empty($input['include_legacy'])
                    || !$includePartners;
            }
        }
        if (!$includeClients && !$includePartners) {
            $includeClients = true;
        }

        $promoMonth = max(0, min(12, (int) ($input['promo_month'] ?? 0)));

        $audience = [
            'include_clients' => $includeClients,
            'include_partners' => $includePartners,
            // Compat para campañas/vistas antiguas.
            'include_students' => $includeClients,
            'include_legacy' => $includeClients,
            'product_id' => (int) ($input['product_id'] ?? 0) ?: null,
            'certifier_id' => (int) ($input['certifier_id'] ?? 0) ?: null,
            'promo_month' => $promoMonth,
        ];

        $promo = $this->resolvePromoCode($audience, $windowStart);
        $notes = trim((string) ($input['notes'] ?? ''));

        return [
            'name' => $name,
            'mail_template_code' => $tpl,
            'promo_code' => $promo !== '' ? $promo : null,
            'audience' => $audience,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'interval_seconds' => $auto['interval_seconds'],
            'day_hour_start' => $auto['day_hour_start'],
            'day_hour_end' => $auto['day_hour_end'],
            'max_per_run' => $auto['max_per_run'],
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    /**
     * Parámetros anti-spam derivados solo del rango de fechas y el tamaño de audiencia.
     *
     * @return array{interval_seconds:int,day_hour_start:int,day_hour_end:int,max_per_run:int}
     */
    public function autoAntiSpamParams(string $windowStart, string $windowEnd, int $recipientCount): array
    {
        $dayHourStart = 9;
        $dayHourEnd = 18;
        $maxPerRun = 15;
        $recipientCount = max(1, $recipientCount);

        try {
            $start = new \DateTimeImmutable($windowStart . ' 00:00:00');
            $end = new \DateTimeImmutable($windowEnd . ' 00:00:00');
        } catch (\Throwable) {
            return [
                'interval_seconds' => 180,
                'day_hour_start' => $dayHourStart,
                'day_hour_end' => $dayHourEnd,
                'max_per_run' => $maxPerRun,
            ];
        }

        if ($end < $start) {
            $end = $start;
        }
        $days = (int) $start->diff($end)->days + 1;
        $hoursPerDay = max(1, $dayHourEnd - $dayHourStart);
        $usableSeconds = max(3600, $days * $hoursPerDay * 3600);

        // Reparto uniforme; mínimo ~90s entre correos, máximo 1h.
        $interval = (int) max(90, min(3600, (int) floor($usableSeconds / $recipientCount)));

        // Si hay pocos destinatarios en muchos días, no alargar más de ~20 min.
        if ($recipientCount <= max(1, $days * 3)) {
            $interval = min($interval, 1200);
        }

        return [
            'interval_seconds' => $interval,
            'day_hour_start' => $dayHourStart,
            'day_hour_end' => $dayHourEnd,
            'max_per_run' => $maxPerRun,
        ];
    }

    /** @param array<string, mixed> $audience */
    private function audienceIncludesClients(array $audience): bool
    {
        if (array_key_exists('include_clients', $audience)) {
            return !empty($audience['include_clients']);
        }
        // Campañas antiguas: estudiantes o legacy cuentan como clientes.
        if (array_key_exists('include_students', $audience) || array_key_exists('include_legacy', $audience)) {
            return !empty($audience['include_students']) || !empty($audience['include_legacy']);
        }

        return true;
    }

    /** @param array<string, mixed> $audience */
    private function resolvePromoCode(array $audience, string $windowStart): string
    {
        $promoMonth = (int) ($audience['promo_month'] ?? 0);
        $year = null;
        if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $windowStart, $m)) {
            $year = (int) $m[1];
        }
        // Mes actual: se resuelve al enviar (año/mes de “ahora”).
        if ($promoMonth <= 0) {
            return PromoDoceoService::currentCode();
        }

        return PromoDoceoService::codeForMonth($promoMonth, $year);
    }

    /** @return list<array<string, mixed>> */
    private function fetchStudentBuyers(?int $productId, ?int $certifierId): array
    {
        $sql = 'SELECT u.id AS user_id, u.email, u.first_name, u.last_name_p, u.last_name_m, u.phone,
                       TRIM(CONCAT(u.first_name, \' \', u.last_name_p, \' \', u.last_name_m)) AS full_name,
                       GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR \', \') AS product_label
                FROM purchases pu
                INNER JOIN users u ON u.id = pu.student_user_id
                INNER JOIN purchase_items pi ON pi.purchase_id = pu.id
                INNER JOIN products pr ON pr.id = pi.product_id
                WHERE pu.status NOT IN (\'cancelled\', \'refunded\', \'draft\')
                  AND u.email IS NOT NULL AND u.email != \'\'
                  AND u.is_active = 1';
        $params = [];
        if ($productId !== null) {
            $sql .= ' AND pr.id = ?';
            $params[] = $productId;
        }
        if ($certifierId !== null) {
            $sql .= ' AND pr.certifier_id = ?';
            $params[] = $certifierId;
        }
        $sql .= ' GROUP BY u.id, u.email, u.first_name, u.last_name_p, u.last_name_m, u.phone';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function fetchPartners(): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.id AS partner_id, p.display_name, u.email, u.first_name, u.phone,
                    TRIM(CONCAT(u.first_name, \' \', u.last_name_p, \' \', u.last_name_m)) AS full_name
             FROM partners p
             INNER JOIN users u ON u.id = p.user_id
             WHERE p.is_active = 1 AND u.is_active = 1
               AND u.email IS NOT NULL AND u.email != \'\''
        );

        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    /** @return list<array<string, mixed>> */
    private function fetchLegacy(?int $productId, ?int $certifierId): array
    {
        $sql = 'SELECT mc.* FROM marketing_contacts mc';
        $params = [];
        if ($certifierId !== null) {
            $sql .= ' INNER JOIN products pr ON pr.id = mc.product_id AND pr.certifier_id = ?';
            $params[] = $certifierId;
        }
        $sql .= ' WHERE mc.opted_out_at IS NULL';
        if ($productId !== null) {
            $sql .= ' AND mc.product_id = ?';
            $params[] = $productId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $row
     */
    private function sendOne(array $campaign, array $row): void
    {
        $to = trim((string) ($row['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Correo inválido.');
        }
        $tpl = (string) ($campaign['mail_template_code'] ?? '');
        $base = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
        $full = trim((string) ($row['full_name'] ?? ''));
        $first = trim((string) ($row['first_name'] ?? ''));
        if ($first === '' && $full !== '') {
            $parts = preg_split('/\s+/', $full) ?: [];
            $first = (string) ($parts[0] ?? $full);
        }

        $audience = json_decode((string) ($campaign['audience_json'] ?? '{}'), true);
        if (!is_array($audience)) {
            $audience = [];
        }
        $promoMonth = (int) ($audience['promo_month'] ?? 0);
        if ($promoMonth <= 0) {
            $promoCode = PromoDoceoService::currentCode();
        } else {
            $promoCode = (string) ($campaign['promo_code'] ?? '');
            if ($promoCode === '') {
                $promoCode = $this->resolvePromoCode($audience, (string) ($campaign['window_start'] ?? date('Y-m-d')));
            }
        }

        $vars = [
            'name' => $first !== '' ? $first : $full,
            'first_name' => $first,
            'full_name' => $full !== '' ? $full : $first,
            'student_email' => $to,
            'student_phone' => (string) ($row['phone'] ?? ''),
            'product_name' => (string) ($row['product_label'] ?? ''),
            'certificacion' => (string) ($row['product_label'] ?? ''),
            'catalog_url' => $base !== '' ? $base . '/catalogo' : '/catalogo',
            'login_url' => $base !== '' ? $base . '/login' : '/login',
            'promo_code' => $promoCode,
            'partner_name' => '',
            'partner_code' => '',
            'partner_email' => '',
        ];
        (new MailTemplateService())->send($tpl, $to, $vars);
    }

    private function markCompletedIfDone(int $campaignId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM marketing_campaign_recipients
             WHERE campaign_id = ? AND status = 'pending'"
        );
        $stmt->execute([$campaignId]);
        if ((int) $stmt->fetchColumn() > 0) {
            // Si la ventana terminó, cancelar pendientes restantes.
            $c = $this->find($campaignId);
            if ($c !== null && (string) $c['window_end'] < date('Y-m-d')) {
                $this->pdo->prepare(
                    "UPDATE marketing_campaign_recipients SET status = 'cancelled',
                        last_error = 'Fuera de la ventana de envío'
                     WHERE campaign_id = ? AND status = 'pending'"
                )->execute([$campaignId]);
            } else {
                return;
            }
        }
        $this->pdo->prepare(
            "UPDATE marketing_campaigns SET status = 'completed', completed_at = NOW()
             WHERE id = ? AND status IN ('running','scheduled','paused')"
        )->execute([$campaignId]);
    }

    /** @param list<string> $from */
    private function setStatus(int $id, array $from, string $to): void
    {
        $campaign = $this->find($id);
        if ($campaign === null || !in_array((string) $campaign['status'], $from, true)) {
            throw new \InvalidArgumentException('Estado de campaña no válido para esta acción.');
        }
        $this->pdo->prepare('UPDATE marketing_campaigns SET status = ? WHERE id = ?')
            ->execute([$to, $id]);
    }

    private function advanceWithinWindow(
        \DateTimeImmutable $cursor,
        int $seconds,
        int $dayHourStart,
        int $dayHourEnd,
        \DateTimeImmutable $hardEnd
    ): \DateTimeImmutable {
        $next = $cursor->modify('+' . max(1, $seconds) . ' seconds');
        $hour = (int) $next->format('G');
        if ($hour >= $dayHourEnd || $hour < $dayHourStart) {
            // Siguiente día laboral dentro de la ventana horaria.
            $next = $cursor->setTime($dayHourStart, 0, 0)->modify('+1 day');
        }
        if ($next > $hardEnd) {
            return $hardEnd;
        }

        return $next;
    }
}

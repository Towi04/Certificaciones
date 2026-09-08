<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Tablero operativo admin: una fila = un caso (tracking), estilo hoja de cálculo.
 */
final class AdminOpsBoardService
{
    public const VIEWS = [
        'action' => 'Por atender',
        'pay' => 'Por pagar',
        'provider' => 'Proveedor',
        'access' => 'Folio / clave',
        'exams' => 'Exámenes',
        'all' => 'Todos',
    ];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /**
     * @param array{q?:?string,view?:?string} $filters
     */
    public function count(array $filters): int
    {
        [$where, $params] = $this->whereClause($filters);
        $sql = 'SELECT COUNT(*) FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN purchases pu ON pu.id = t.purchase_id
             JOIN users u ON u.id = t.student_user_id
             LEFT JOIN product_groups pg ON pg.id = pr.product_group_id
             LEFT JOIN pipeline_templates pt ON pt.id = t.pipeline_template_id
             LEFT JOIN partners pa ON pa.id = t.partner_id
             WHERE ' . $where;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{q?:?string,view?:?string} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters, ?int $limit = 50, ?int $offset = 0): array
    {
        [$where, $params] = $this->whereClause($filters);
        $sql = 'SELECT t.id, t.purchase_id, t.product_id, t.pipeline_template_id, t.current_step_code, t.status AS tracking_status,
                    t.exam_date, t.exam_time, t.exam_date_2, t.exam_time_2, t.zoom_url,
                    t.folio, t.access_key, t.cenni_folio, t.extra_json, t.updated_at, t.created_at,
                    t.moodle_username, t.results_level, t.results_score,
                    pr.name AS product_name, pr.code AS product_code, pr.type AS product_type,
                    pr.platform_type, pr.config_json,
                    pg.code AS product_group_code, pg.name AS product_group_name,
                    pg.config_json AS group_config_json,
                    pu.matricula, pu.status AS purchase_status, pu.charged_amount, pu.payment_method,
                    pu.payment_proof_path, pu.paid_at,
                    u.first_name, u.last_name_p, u.last_name_m, u.email AS student_email, u.phone AS student_phone,
                    pt.code AS pipeline_code,
                    pa.code AS partner_code, pa.display_name AS partner_name
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN purchases pu ON pu.id = t.purchase_id
             JOIN users u ON u.id = t.student_user_id
             LEFT JOIN product_groups pg ON pg.id = pr.product_group_id
             LEFT JOIN pipeline_templates pt ON pt.id = t.pipeline_template_id
             LEFT JOIN partners pa ON pa.id = t.partner_id
             WHERE ' . $where . '
             ORDER BY
                CASE
                  WHEN pu.status IN (\'awaiting_payment\',\'payment_review\') THEN 0
                  WHEN JSON_EXTRACT(t.extra_json, \'$.provider_request.required\') = true
                       AND (
                         JSON_EXTRACT(t.extra_json, \'$.provider_request.sent_at\') IS NULL
                         OR JSON_UNQUOTE(JSON_EXTRACT(t.extra_json, \'$.provider_request.sent_at\')) IN (\'\',\'null\')
                       ) THEN 1
                  WHEN pt.code = \'elet_uks\' AND pu.status = \'paid\'
                       AND (t.folio IS NULL OR t.folio = \'\' OR t.access_key IS NULL OR t.access_key = \'\') THEN 2
                  WHEN t.status = \'waiting_admin\' THEN 3
                  ELSE 9
                END ASC,
                t.updated_at DESC, t.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) max(0, (int) $offset);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->annotate($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function annotate(array $row): array
    {
        $purchaseStatus = (string) ($row['purchase_status'] ?? '');
        $pipeline = (string) ($row['pipeline_code'] ?? '');
        $productCode = (string) ($row['product_code'] ?? '');
        $isElet = $pipeline === 'elet_uks' || $productCode === 'ELET-UKS';

        $extra = [];
        if (!empty($row['extra_json']) && is_string($row['extra_json'])) {
            $decoded = json_decode($row['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($row['extra_json'] ?? null)) {
            $extra = $row['extra_json'];
        }
        $provider = is_array($extra['provider_request'] ?? null) ? $extra['provider_request'] : [];
        $providerRequired = !empty($provider['required']);
        $providerSentAt = trim((string) ($provider['sent_at'] ?? ''));
        $providerPending = $providerRequired && ($providerSentAt === '' || $providerSentAt === 'null');

        $folio = trim((string) ($row['folio'] ?? ''));
        $accessKey = trim((string) ($row['access_key'] ?? ''));
        $needsAccess = $isElet && $purchaseStatus === 'paid' && ($folio === '' || $accessKey === '');
        $hasAccess = $folio !== '' && $accessKey !== '';

        $needsPayment = in_array($purchaseStatus, ['awaiting_payment', 'payment_review'], true);

        $pipelineSteps = [];
        $pipelineId = (int) ($row['pipeline_template_id'] ?? 0);
        if ($pipelineId > 0) {
            static $stepsCache = [];
            if (!isset($stepsCache[$pipelineId])) {
                $stepsCache[$pipelineId] = (new TrackingService())->steps($pipelineId);
            }
            $pipelineSteps = $stepsCache[$pipelineId];
        }

        $opsButtons = GroupStepConfig::pendingOpsButtons($row, $pipelineSteps);
        $needsExamAccessBtn = false;
        $pendingOps = 0;
        foreach ($opsButtons as $btn) {
            if (($btn['action'] ?? '') === GroupStepConfig::ACTION_EXAM_ACCESS) {
                $needsExamAccessBtn = true;
            }
            if (empty($btn['done'])) {
                $pendingOps++;
            }
        }

        $adminProofPath = trim((string) ($provider['admin_proof_path'] ?? ''));
        $adminProofId = (int) ($provider['admin_proof_document_id'] ?? 0);
        if ($adminProofId < 1 && $adminProofPath === '') {
            // Fallback: buscar documento subido aunque no esté en extra_json
            try {
                $proofDoc = (new ProviderRequestService())->findAdminPaymentProof((int) ($row['id'] ?? 0));
                if (is_array($proofDoc)) {
                    $adminProofId = (int) ($proofDoc['id'] ?? 0);
                    $adminProofPath = (string) ($proofDoc['storage_path'] ?? '');
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $row['student_full_name'] = trim(
            ($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? '') . ' ' . ($row['last_name_m'] ?? '')
        );
        $row['is_elet'] = $isElet;
        $row['needs_payment'] = $needsPayment;
        $row['provider_enabled'] = $providerRequired || $providerPending;
        $row['provider_required'] = $providerRequired;
        $row['provider_pending'] = $providerPending;
        $row['provider_sent_at'] = $providerSentAt !== '' && $providerSentAt !== 'null' ? $providerSentAt : null;
        $row['provider_error'] = trim((string) ($provider['last_error'] ?? ''));
        $row['admin_proof_uploaded'] = $adminProofId > 0 || $adminProofPath !== '';
        $row['admin_proof_document_id'] = $adminProofId;
        $row['needs_access'] = $needsAccess;
        $row['has_access'] = $hasAccess;
        $row['ops_buttons'] = $opsButtons;
        // Folio/clave solo si el grupo pidió esa acción (no forzar en todo ELeT).
        $row['show_folio_fields'] = $needsExamAccessBtn;
        $row['needs_action'] = $pendingOps > 0
            || (string) ($row['tracking_status'] ?? '') === 'waiting_admin';

        return $row;
    }

    /**
     * Guarda folio/clave y, si $notify, publica accesos (plantilla alumno).
     *
     * @return array{saved:bool,notified:bool}
     */
    public function saveAccess(int $trackingId, string $folio, string $accessKey, int $adminUserId, bool $notify): array
    {
        $folio = trim($folio);
        $accessKey = trim($accessKey);
        if ($folio === '' || $accessKey === '') {
            throw new \InvalidArgumentException('Indica folio y clave.');
        }

        // Siempre guardar primero; si el correo falla, los datos no se pierden.
        $this->pdo->prepare('UPDATE trackings SET folio = ?, access_key = ? WHERE id = ?')
            ->execute([$folio, $accessKey, $trackingId]);
        (new TrackingService())->addLog(
            $trackingId,
            'codigos',
            'Folio/clave guardados desde tablero operativo',
            $adminUserId
        );

        if (!$notify) {
            return ['saved' => true, 'notified' => false];
        }

        (new UksEletService())->publishExamAccess($trackingId, $folio, $accessKey, $adminUserId, true);

        return ['saved' => true, 'notified' => true];
    }

    /**
     * @param list<array{tracking_id:int,folio:string,access_key:string}> $items
     * @return array{ok:int,fail:int,errors:list<string>}
     */
    public function bulkPublishAccess(array $items, int $adminUserId, bool $notify): array
    {
        $ok = 0;
        $fail = 0;
        $errors = [];
        foreach ($items as $item) {
            $tid = (int) ($item['tracking_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            try {
                $this->saveAccess(
                    $tid,
                    (string) ($item['folio'] ?? ''),
                    (string) ($item['access_key'] ?? ''),
                    $adminUserId,
                    $notify
                );
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $errors[] = '#' . $tid . ': ' . $e->getMessage();
            }
        }

        return ['ok' => $ok, 'fail' => $fail, 'errors' => $errors];
    }

    /**
     * @param array{q?:?string,view?:?string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function whereClause(array $filters): array
    {
        $parts = ['1=1'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $parts[] = '(pu.matricula LIKE ? OR u.email LIKE ? OR u.first_name LIKE ?
                OR u.last_name_p LIKE ? OR u.last_name_m LIKE ? OR t.folio LIKE ?
                OR pr.name LIKE ? OR pr.code LIKE ? OR pa.code LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $view = (string) ($filters['view'] ?? 'action');
        if (!isset(self::VIEWS[$view])) {
            $view = 'action';
        }

        switch ($view) {
            case 'pay':
                $parts[] = "pu.status IN ('awaiting_payment','payment_review')";
                break;
            case 'provider':
                $parts[] = "pu.status = 'paid'
                    AND JSON_EXTRACT(t.extra_json, '$.provider_request.required') = true
                    AND (
                        JSON_EXTRACT(t.extra_json, '$.provider_request.sent_at') IS NULL
                        OR JSON_UNQUOTE(JSON_EXTRACT(t.extra_json, '$.provider_request.sent_at')) IN ('','null')
                    )";
                break;
            case 'access':
                $parts[] = "pu.status = 'paid'
                    AND (pt.code = 'elet_uks' OR pr.code = 'ELET-UKS')
                    AND (t.folio IS NULL OR t.folio = '' OR t.access_key IS NULL OR t.access_key = '')";
                break;
            case 'exams':
                $parts[] = 't.exam_date IS NOT NULL
                    AND t.exam_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 21 DAY)';
                break;
            case 'all':
                break;
            case 'action':
            default:
                $parts[] = "(
                    pu.status IN ('awaiting_payment','payment_review')
                    OR t.status = 'waiting_admin'
                    OR (
                        pu.status = 'paid'
                        AND JSON_EXTRACT(t.extra_json, '$.provider_request.required') = true
                        AND (
                            JSON_EXTRACT(t.extra_json, '$.provider_request.sent_at') IS NULL
                            OR JSON_UNQUOTE(JSON_EXTRACT(t.extra_json, '$.provider_request.sent_at')) IN ('','null')
                        )
                    )
                    OR (
                        pu.status = 'paid'
                        AND (pt.code = 'elet_uks' OR pr.code = 'ELET-UKS')
                        AND (t.folio IS NULL OR t.folio = '' OR t.access_key IS NULL OR t.access_key = '')
                    )
                )";
                break;
        }

        return [implode(' AND ', $parts), $params];
    }
}

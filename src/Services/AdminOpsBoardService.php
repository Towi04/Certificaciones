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
        'provider' => 'Solicitud proveedor',
        'access' => 'Folio / clave',
        'exams' => 'Exámenes',
        'all' => 'Todos',
    ];

    /** Texto corto bajo las pestañas. */
    public const VIEW_HINTS = [
        'action' => 'Casos que requieren acción tuya: pago por confirmar, solicitud a proveedor pendiente o accesos incompletos.',
        'provider' => 'Pagados con solicitud al proveedor pendiente de enviar (p. ej. UKS / Lingua Franca).',
        'access' => 'Exámenes ELeT pagados: captura o revisa folio y clave del día.',
        'exams' => 'Exámenes de hoy a 21 días. Semáforo: rojo = hoy, ámbar = mañana, verde = posteriores.',
        'all' => 'Todos los casos (usa la búsqueda para acotar).',
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
                    pu.payment_proof_path, pu.paid_at, pu.combo_id,
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
                  WHEN (pt.code = \'elet_uks\' OR pr.code = \'ELET-UKS\') AND pu.status = \'paid\'
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

        return $this->dedupeConfirmPaymentButtons($out);
    }

    /**
     * Un solo «Confirmar pago» por compra/paquete aunque haya N trackings (combo).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeConfirmPaymentButtons(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['purchase_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        }

        $seen = [];
        foreach ($rows as $i => $row) {
            $pid = (int) ($row['purchase_id'] ?? 0);
            $buttons = is_array($row['ops_buttons'] ?? null) ? $row['ops_buttons'] : [];
            $hasConfirm = false;
            foreach ($buttons as $btn) {
                if (($btn['action'] ?? '') === GroupStepConfig::ACTION_CONFIRM_PAYMENT) {
                    $hasConfirm = true;
                    break;
                }
            }

            $siblingCount = $counts[$pid] ?? 1;
            $rows[$i]['package_sibling_count'] = $siblingCount;
            $rows[$i]['is_package'] = $siblingCount > 1 || (int) ($row['combo_id'] ?? 0) > 0;
            $rows[$i]['payment_confirm_on_sibling'] = false;

            if (!$hasConfirm || $pid < 1) {
                continue;
            }

            if (isset($seen[$pid])) {
                $filtered = [];
                foreach ($buttons as $btn) {
                    if (($btn['action'] ?? '') === GroupStepConfig::ACTION_CONFIRM_PAYMENT) {
                        continue;
                    }
                    $filtered[] = $btn;
                }
                $rows[$i]['ops_buttons'] = $filtered;
                $rows[$i]['payment_confirm_on_sibling'] = true;

                $pendingOps = 0;
                foreach ($filtered as $btn) {
                    if (empty($btn['done'])) {
                        $pendingOps++;
                    }
                }
                $rows[$i]['needs_action'] = $pendingOps > 0
                    || (string) ($row['tracking_status'] ?? '') === 'waiting_admin';
                continue;
            }

            $seen[$pid] = true;
            if ($siblingCount > 1 || (int) ($row['combo_id'] ?? 0) > 0) {
                foreach ($buttons as $j => $btn) {
                    if (($btn['action'] ?? '') !== GroupStepConfig::ACTION_CONFIRM_PAYMENT) {
                        continue;
                    }
                    $n = max($siblingCount, 2);
                    $buttons[$j]['label'] = 'Confirmar pago del paquete (' . $n . ')';
                    $buttons[$j]['package_items'] = $n;
                }
                $rows[$i]['ops_buttons'] = $buttons;
            }
        }

        return $rows;
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
        $zoomUrl = trim((string) ($row['zoom_url'] ?? ''));
        $needsAccess = $isElet && $purchaseStatus === 'paid' && ($folio === '' || $accessKey === '');
        $hasAccess = $folio !== '' && $accessKey !== '';

        $groupCfg = [];
        $rawGroupCfg = $row['group_config_json'] ?? null;
        if (is_string($rawGroupCfg) && $rawGroupCfg !== '') {
            $decodedGroup = json_decode($rawGroupCfg, true);
            $groupCfg = is_array($decodedGroup) ? $decodedGroup : [];
        } elseif (is_array($rawGroupCfg)) {
            $groupCfg = $rawGroupCfg;
        }
        $examCfg = is_array($groupCfg['exam'] ?? null) ? $groupCfg['exam'] : [];
        $groupCode = strtolower(trim((string) ($row['product_group_code'] ?? '')));
        $captureZoom = !empty($examCfg['capture_zoom'])
            || str_contains($groupCode, 'linguafranca')
            || str_contains($groupCode, 'toefl');
        $extraLabel = self::extraFieldLabelFromExamConfig($examCfg);

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
        // Folio/clave visibles para administrar/editar: botón de accesos, ELeT pagado, o ya hay valor.
        $row['show_folio_fields'] = $needsExamAccessBtn
            || ($isElet && $purchaseStatus === 'paid')
            || $folio !== ''
            || $accessKey !== '';
        // Campo extra (Zoom / ID escuela / código curso…): por config del grupo, heurística TOEFL, o ya hay valor.
        $row['show_zoom_fields'] = $captureZoom || $zoomUrl !== '';
        $row['extra_field_label'] = $extraLabel;
        $row['needs_action'] = $pendingOps > 0
            || (string) ($row['tracking_status'] ?? '') === 'waiting_admin';

        return $row;
    }

    /**
     * Etiqueta del 3.er campo de acceso (columna Ops + plantillas {{extra_label}} / {{zoom_label}}).
     *
     * @param array<string, mixed> $examCfg
     */
    public static function extraFieldLabelFromExamConfig(array $examCfg): string
    {
        $label = trim((string) ($examCfg['extra_field_label'] ?? ''));
        if ($label === '') {
            $label = 'Zoom';
        }

        return mb_substr($label, 0, 60);
    }

    /** @param array<string, mixed>|null $groupOrProductConfig */
    public static function extraFieldLabelFromConfig(?array $groupOrProductConfig): string
    {
        $exam = is_array($groupOrProductConfig['exam'] ?? null) ? $groupOrProductConfig['exam'] : [];

        return self::extraFieldLabelFromExamConfig($exam);
    }

    public static function looksLikeUrl(string $value): bool
    {
        $v = trim($value);
        if ($v === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $v) === 1) {
            return filter_var($v, FILTER_VALIDATE_URL) !== false;
        }

        return false;
    }

    /**
     * Guarda folio/clave/campo extra y, si $notify, publica accesos (plantilla alumno).
     *
     * @return array{saved:bool,notified:bool}
     */
    public function saveAccess(
        int $trackingId,
        string $folio,
        string $accessKey,
        int $adminUserId,
        bool $notify,
        string $zoomUrl = ''
    ): array {
        $folio = trim($folio);
        $accessKey = trim($accessKey);
        $zoomUrl = self::normalizeExtraValue($zoomUrl);

        if (($folio !== '' || $accessKey !== '') && ($folio === '' || $accessKey === '')) {
            throw new \InvalidArgumentException('Indica folio y clave juntos.');
        }
        if ($folio === '' && $accessKey === '' && $zoomUrl === '') {
            throw new \InvalidArgumentException('Indica folio/clave o el dato extra (Zoom / ID / código…).');
        }

        // Siempre guardar primero; si el correo falla, los datos no se pierden.
        // Extra-only: no tocar folio/clave existentes.
        if ($folio !== '' || $accessKey !== '') {
            $this->pdo->prepare('UPDATE trackings SET folio = ?, access_key = ?, zoom_url = ? WHERE id = ?')
                ->execute([$folio, $accessKey, $zoomUrl !== '' ? $zoomUrl : null, $trackingId]);
        } else {
            $this->pdo->prepare('UPDATE trackings SET zoom_url = ? WHERE id = ?')
                ->execute([$zoomUrl !== '' ? $zoomUrl : null, $trackingId]);
        }
        $logParts = [];
        if ($folio !== '' || $accessKey !== '') {
            $logParts[] = 'folio/clave';
        }
        if ($zoomUrl !== '') {
            $logParts[] = 'dato extra';
        }
        (new TrackingService())->addLog(
            $trackingId,
            'codigos',
            implode('/', $logParts) . ' guardados desde tablero operativo',
            $adminUserId
        );

        if (!$notify) {
            return ['saved' => true, 'notified' => false];
        }

        if ($folio === '' || $accessKey === '') {
            throw new \InvalidArgumentException(
                'Para enviar la plantilla de accesos indica folio y clave (el dato extra ya quedó guardado si lo escribiste).'
            );
        }

        $notified = (new UksEletService())->publishExamAccess($trackingId, $folio, $accessKey, $adminUserId, true);

        return ['saved' => true, 'notified' => $notified];
    }

    /**
     * Valor libre del campo extra (Zoom, ID escuela, código de curso, etc.).
     * Se guarda en trackings.zoom_url por compatibilidad.
     */
    public static function normalizeExtraValue(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 255) {
            throw new \InvalidArgumentException('El dato extra es demasiado largo (máx. 255).');
        }

        return $value;
    }

    /** @deprecated Use normalizeExtraValue — acepta texto libre, no solo URLs. */
    public static function normalizeZoomUrl(string $raw): string
    {
        return self::normalizeExtraValue($raw);
    }

    /**
     * @param list<array{tracking_id:int,folio:string,access_key:string,zoom_url?:string}> $items
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
                    $notify,
                    (string) ($item['zoom_url'] ?? '')
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
                // Todos los ELeT pagados: ver y editar folio/clave (pendientes primero vía ORDER BY).
                $parts[] = "pu.status = 'paid'
                    AND (pt.code = 'elet_uks' OR pr.code = 'ELET-UKS')";
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

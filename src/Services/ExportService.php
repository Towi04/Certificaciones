<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\ExportTemplateRepository;
use App\Support\AsciiUpperNormalizer;
use App\Support\WorkbookValueResolver;
use PDO;

final class ExportService
{
    public const SCOPE_STUDENT = 'student';
    public const SCOPE_PENDING = 'pending';
    /** Misma fecha, sin filtrar producto (usa filtros de la plantilla). */
    public const SCOPE_EXAM_DATE_ONLY = 'exam_date_only';
    public const SCOPE_PRODUCT = 'product';
    /** Misma fecha + mismo producto (valor histórico del paso: exam_date). */
    public const SCOPE_EXAM_DATE_PRODUCT = 'exam_date_product';

    /** @return list<string> */
    public static function scopes(): array
    {
        return [
            self::SCOPE_STUDENT,
            self::SCOPE_PENDING,
            self::SCOPE_EXAM_DATE_ONLY,
            self::SCOPE_PRODUCT,
            self::SCOPE_EXAM_DATE_PRODUCT,
        ];
    }

    /** @return list<array{value:string,label:string}> */
    public static function scopeOptions(): array
    {
        return [
            ['value' => self::SCOPE_STUDENT, 'label' => 'Solo este alumno'],
            ['value' => self::SCOPE_PENDING, 'label' => 'Pendientes del mismo examen (sin folio / sin descarga / no presentado)'],
            ['value' => self::SCOPE_EXAM_DATE_PRODUCT, 'label' => 'Misma fecha + mismo examen'],
            ['value' => self::SCOPE_EXAM_DATE_ONLY, 'label' => 'Misma fecha de examen (cualquier cert. de la plantilla)'],
            ['value' => self::SCOPE_PRODUCT, 'label' => 'Mismo examen (cualquier fecha)'],
        ];
    }

    public static function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        // Compat: el valor histórico exam_date significaba fecha+producto.
        if ($scope === 'exam_date') {
            return self::SCOPE_EXAM_DATE_PRODUCT;
        }
        if (!in_array($scope, self::scopes(), true)) {
            return self::SCOPE_STUDENT;
        }

        return $scope;
    }

    /** @return list<array{value:string,label:string}> */
    public static function fieldOptions(): array
    {
        $opts = ProviderRequestService::FIELD_OPTIONS;
        $extra = [
            ['value' => 'folio', 'label' => 'Folio'],
            ['value' => 'access_key', 'label' => 'Clave / access key'],
            ['value' => 'zoom_url', 'label' => 'Campo extra (Zoom / ID / código)'],
            ['value' => 'product_group_code', 'label' => 'Código del grupo'],
        ];
        $seen = [];
        $out = [];
        foreach (array_merge($opts, $extra) as $row) {
            $v = (string) ($row['value'] ?? '');
            if ($v === '' || isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            $out[] = $row;
        }
        foreach (CheckoutRequirements::allFieldMeta() as $code => $meta) {
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = [
                'value' => $code,
                'label' => (string) ($meta['label'] ?? $code) . ' (checkout)',
            ];
        }

        return $out;
    }

    private PDO $pdo;
    private ExportTemplateRepository $templates;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->templates = new ExportTemplateRepository();
    }

    /** @return array<string, mixed>|null */
    public function template(string $code): ?array
    {
        return $this->templates->findByCode($code);
    }

    /**
     * @param array{
     *   tracking_id?:int,
     *   exam_date?:string,
     *   product_id?:int,
     *   product_group_id?:int,
     *   purchase_status?:list<string>,
     *   step_codes?:list<string>|null,
     *   exclude_registered?:bool,
     *   template_code?:string,
     *   step_code?:string
     * } $options
     * @return list<array<string, string>>
     */
    public function rowsForTemplate(string $code, array $options = []): array
    {
        return $this->rowsWithMeta($code, $options)['rows'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *   rows:list<array<string,string>>,
     *   tracking_ids:list<int>,
     *   included:int,
     *   excluded:int,
     *   excluded_reasons:array{downloaded:int,folio:int,presented:int}
     * }
     */
    public function rowsWithMeta(string $code, array $options = []): array
    {
        $template = $this->template($code);
        if ($template === null) {
            throw new \InvalidArgumentException('Plantilla de exportación no encontrada: ' . $code);
        }

        $mapping = $this->mapping($template);
        $filters = is_array($mapping['filters'] ?? null) ? $mapping['filters'] : [];
        $normalize = (string) ($mapping['normalize'] ?? 'none');
        if (!in_array($normalize, ['none', 'toefl'], true)) {
            $normalize = 'none';
        }

        $sql = 'SELECT pu.matricula, u.first_name, u.last_name_p, u.last_name_m, u.email, u.phone,
                       t.id AS tracking_id, t.exam_date, t.exam_time, t.folio, t.access_key, t.zoom_url,
                       t.current_step_code, t.extra_json,
                       st.curp AS student_curp, st.birth_date AS student_birth_date, st.sex AS student_sex,
                       st.nationality AS student_nationality, st.extra_fields_json AS student_extra_fields_json,
                       st.address_street AS student_address_street, st.address_city AS student_address_city,
                       st.address_state AS student_address_state, st.address_zip AS student_address_zip,
                       st.address_colony AS student_address_colony, st.address_country AS student_address_country,
                       pr.id AS product_id, pr.code AS product_code, pr.name AS product_name,
                       pg.id AS product_group_id, pg.code AS product_group_code,
                       pa.code AS partner_code
                FROM trackings t
                JOIN purchases pu ON pu.id = t.purchase_id
                JOIN users u ON u.id = t.student_user_id
                JOIN products pr ON pr.id = t.product_id
                LEFT JOIN product_groups pg ON pg.id = pr.product_group_id
                LEFT JOIN partners pa ON pa.id = t.partner_id
                LEFT JOIN students st ON st.user_id = t.student_user_id
                WHERE t.status <> ?';
        $params = ['cancelled'];

        if (!empty($options['tracking_id']) && empty($options['batch'])) {
            $sql .= ' AND t.id = ?';
            $params[] = (int) $options['tracking_id'];
        } else {
            $productCodes = $filters['product_codes'] ?? [];
            if (!is_array($productCodes)) {
                $productCodes = [];
            }
            $productCodes = array_values(array_filter(array_map('strval', $productCodes)));
            $groupCodes = $filters['product_group_codes'] ?? [];
            if (!is_array($groupCodes)) {
                $groupCodes = [];
            }
            $groupCodes = array_values(array_filter(array_map('strval', $groupCodes)));

            if (!empty($options['product_id'])) {
                $sql .= ' AND pr.id = ?';
                $params[] = (int) $options['product_id'];
            } elseif (!empty($options['product_group_id'])) {
                $sql .= ' AND pg.id = ?';
                $params[] = (int) $options['product_group_id'];
            } elseif ($productCodes !== [] || $groupCodes !== []) {
                $parts = [];
                if ($productCodes !== []) {
                    $parts[] = 'pr.code IN (' . implode(',', array_fill(0, count($productCodes), '?')) . ')';
                    array_push($params, ...$productCodes);
                }
                if ($groupCodes !== []) {
                    $parts[] = 'pg.code IN (' . implode(',', array_fill(0, count($groupCodes), '?')) . ')';
                    array_push($params, ...$groupCodes);
                }
                $sql .= ' AND (' . implode(' OR ', $parts) . ')';
            }

            $purchaseStatuses = $options['purchase_status'] ?? ($filters['purchase_status'] ?? ['paid']);
            if (!is_array($purchaseStatuses) || $purchaseStatuses === []) {
                $purchaseStatuses = ['paid'];
            }
            $purchaseStatuses = array_values(array_filter(array_map('strval', $purchaseStatuses)));
            $sql .= ' AND pu.status IN (' . implode(',', array_fill(0, count($purchaseStatuses), '?')) . ')';
            array_push($params, ...$purchaseStatuses);

            if (!empty($options['exam_date'])) {
                $sql .= ' AND t.exam_date = ?';
                $params[] = (string) $options['exam_date'];
            }

            $stepCodes = array_key_exists('step_codes', $options)
                ? $options['step_codes']
                : ($filters['step_codes'] ?? null);
            if (is_array($stepCodes) && $stepCodes !== []) {
                $stepCodes = array_values(array_filter(array_map('strval', $stepCodes)));
                if ($stepCodes !== []) {
                    $sql .= ' AND t.current_step_code IN (' . implode(',', array_fill(0, count($stepCodes), '?')) . ')';
                    array_push($params, ...$stepCodes);
                }
            }
        }

        $sql .= ' ORDER BY t.exam_date ASC, u.last_name_p ASC, u.first_name ASC, pu.matricula ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $excludeRegistered = array_key_exists('exclude_registered', $options)
            ? (bool) $options['exclude_registered']
            : true;
        // Un solo alumno: no filtrar (ops puede re-descargar ese caso).
        if (!empty($options['tracking_id']) && empty($options['batch'])) {
            $excludeRegistered = false;
        }

        $templateCode = trim((string) ($options['template_code'] ?? $code));
        $stepCode = trim((string) ($options['step_code'] ?? ''));
        $reasons = ['downloaded' => 0, 'folio' => 0, 'presented' => 0];
        $excluded = 0;
        $kept = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($excludeRegistered) {
                $why = $this->registeredExclusionReason($row, $templateCode, $stepCode);
                if ($why !== null) {
                    $excluded++;
                    $reasons[$why] = ($reasons[$why] ?? 0) + 1;
                    continue;
                }
            }
            $kept[] = $row;
        }

        $columns = $mapping['columns'] ?? $this->defaultUksColumns();
        $out = [];
        $ids = [];
        foreach ($kept as $row) {
            $row = $this->hydrateCheckoutFromStudent($row);
            $mapped = [];
            foreach ($columns as $col) {
                if (!is_array($col)) {
                    continue;
                }
                $header = trim((string) ($col['header'] ?? ''));
                $field = trim((string) ($col['field'] ?? ''));
                $formula = trim((string) ($col['formula'] ?? ''));
                if ($header === '' || ($field === '' && $formula === '')) {
                    continue;
                }
                $value = $field !== '' ? $this->fieldValue($row, $field) : '';
                if ($formula !== '') {
                    $fieldsBag = $this->fieldsBagForRow($row);
                    try {
                        $value = WorkbookValueResolver::resolve(
                            ['field' => $field, 'formula' => $formula],
                            $fieldsBag,
                            'none'
                        );
                    } catch (\Throwable $e) {
                        throw new \RuntimeException(
                            'Error en fórmula de columna «' . $header . '»: ' . $e->getMessage()
                        );
                    }
                } elseif ($normalize === 'toefl') {
                    $value = AsciiUpperNormalizer::normalize($value);
                }
                $mapped[$header] = $value;
            }
            if ($mapped !== []) {
                $out[] = $mapped;
                $tid = (int) ($row['tracking_id'] ?? 0);
                if ($tid > 0) {
                    $ids[] = $tid;
                }
            }
        }

        return [
            'rows' => $out,
            'tracking_ids' => $ids,
            'included' => count($ids),
            'excluded' => $excluded,
            'excluded_reasons' => $reasons,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *   content:string,
     *   filename:string,
     *   tracking_ids:list<int>,
     *   included:int,
     *   excluded:int,
     *   excluded_reasons:array{downloaded:int,folio:int,presented:int}
     * }
     */
    public function buildDownload(string $code, array $options = []): array
    {
        $template = $this->template($code);
        if ($template === null) {
            throw new \InvalidArgumentException('Plantilla de exportación no encontrada: ' . $code);
        }

        $mapping = $this->mapping($template);
        $columns = $mapping['columns'] ?? $this->defaultUksColumns();
        $headers = [];
        foreach ($columns as $col) {
            if (is_array($col) && trim((string) ($col['header'] ?? '')) !== '') {
                $headers[] = trim((string) $col['header']);
            }
        }
        if ($headers === []) {
            throw new \RuntimeException('La plantilla no define columnas.');
        }

        $meta = $this->rowsWithMeta($code, $options);
        if ($meta['rows'] === []) {
            throw new \InvalidArgumentException(
                $meta['excluded'] > 0
                    ? 'No hay alumnos para incluir (todos excluidos: ya descargados, con folio o ya presentaron).'
                    : 'No hay alumnos que coincidan con el alcance elegido.'
            );
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo generar el CSV.');
        }

        csv_put($stream, $headers);
        foreach ($meta['rows'] as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            csv_put($stream, $line);
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if ($content === false) {
            $content = '';
        }

        return [
            'content' => $content,
            'filename' => $this->downloadFilename($code, $options),
            'tracking_ids' => $meta['tracking_ids'],
            'included' => $meta['included'],
            'excluded' => $meta['excluded'],
            'excluded_reasons' => $meta['excluded_reasons'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function csvContent(string $code, array $options = []): string
    {
        return $this->buildDownload($code, $options)['content'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{included:int,excluded:int,excluded_reasons:array{downloaded:int,folio:int,presented:int},tracking_ids:list<int>}
     */
    public function previewDownload(string $code, array $options = []): array
    {
        $meta = $this->rowsWithMeta($code, $options);

        return [
            'included' => $meta['included'],
            'excluded' => $meta['excluded'],
            'excluded_reasons' => $meta['excluded_reasons'],
            'tracking_ids' => $meta['tracking_ids'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendDownload(string $code, array $options = []): void
    {
        $built = $this->buildDownload($code, $options);
        $this->markDownloadedIds(
            $built['tracking_ids'],
            trim((string) ($options['step_code'] ?? '')),
            $code,
            isset($options['actor_user_id']) ? (int) $options['actor_user_id'] : null
        );

        $payload = "\xEF\xBB\xBF" . $built['content'];

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $built['filename'] . '"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . (string) strlen($payload));
        header('X-Content-Type-Options: nosniff');
        echo $payload;
        exit;
    }

    /**
     * Descarga desde un caso según alcance (alumno / pendientes / fecha / examen).
     *
     * @param array{scope?:string,exclude_registered?:bool,step_code?:string,actor_user_id?:int} $opts
     */
    public function sendDownloadForTracking(string $code, int $trackingId, array $opts = []): void
    {
        $this->sendDownload($code, $this->optionsForTrackingScope($code, $trackingId, $opts));
    }

    /**
     * @param array{scope?:string,exclude_registered?:bool,step_code?:string,actor_user_id?:int} $opts
     * @return array<string, mixed>
     */
    public function optionsForTrackingScope(string $code, int $trackingId, array $opts = []): array
    {
        $tracking = (new TrackingService())->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Caso no encontrado.');
        }

        $scope = self::normalizeScope((string) ($opts['scope'] ?? self::SCOPE_STUDENT));
        $exclude = array_key_exists('exclude_registered', $opts)
            ? (bool) $opts['exclude_registered']
            : true;

        $base = [
            'template_code' => $code,
            'step_code' => trim((string) ($opts['step_code'] ?? '')),
            'actor_user_id' => isset($opts['actor_user_id']) ? (int) $opts['actor_user_id'] : null,
            'exclude_registered' => $exclude,
        ];

        if ($scope === self::SCOPE_STUDENT) {
            return $base + ['tracking_id' => $trackingId];
        }

        $productId = (int) ($tracking['product_id'] ?? 0);
        $examDate = trim((string) ($tracking['exam_date'] ?? ''));

        $options = $base + ['batch' => true];

        if ($scope === self::SCOPE_PENDING || $scope === self::SCOPE_PRODUCT) {
            if ($productId < 1) {
                throw new \InvalidArgumentException('El caso no tiene producto asociado.');
            }
            $options['product_id'] = $productId;
            $options['exclude_registered'] = $exclude;

            return $options;
        }

        if ($scope === self::SCOPE_EXAM_DATE_ONLY) {
            if ($examDate === '') {
                throw new \InvalidArgumentException('El caso no tiene fecha de examen para el lote.');
            }
            $options['exam_date'] = $examDate;

            return $options;
        }

        // exam_date_product (default histórico)
        if ($examDate === '') {
            throw new \InvalidArgumentException('El caso no tiene fecha de examen para generar el lote del día.');
        }
        if ($productId < 1) {
            throw new \InvalidArgumentException('El caso no tiene producto asociado.');
        }
        $options['exam_date'] = $examDate;
        $options['product_id'] = $productId;

        return $options;
    }

    /**
     * @param list<int> $trackingIds
     */
    public function markDownloadedIds(array $trackingIds, string $stepCode, string $templateCode, ?int $actorUserId = null): void
    {
        $svc = new TrackingService();
        foreach ($trackingIds as $tid) {
            $tid = (int) $tid;
            if ($tid < 1) {
                continue;
            }
            try {
                $svc->markCsvDownloaded($tid, $stepCode, $templateCode, $actorUserId);
            } catch (\Throwable) {
                // no bloquear descarga
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return 'downloaded'|'folio'|'presented'|null
     */
    private function registeredExclusionReason(array $row, string $templateCode, string $stepCode): ?string
    {
        if (trim((string) ($row['folio'] ?? '')) !== '') {
            return 'folio';
        }
        if (GroupStepConfig::isExamAttendancePresent($row)) {
            return 'presented';
        }
        if ($this->rowWasCsvDownloaded($row, $templateCode, $stepCode)) {
            return 'downloaded';
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function rowWasCsvDownloaded(array $row, string $templateCode, string $stepCode): bool
    {
        $extra = [];
        $raw = $row['extra_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw)) {
            $extra = $raw;
        }
        $map = is_array($extra['csv_downloads'] ?? null) ? $extra['csv_downloads'] : [];
        if ($map === []) {
            return false;
        }
        if ($stepCode !== '' && !empty($map[$stepCode]['at'])) {
            return true;
        }
        if ($templateCode !== '' && !empty($map['tpl:' . $templateCode]['at'])) {
            return true;
        }
        foreach ($map as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ($templateCode !== ''
                && trim((string) ($entry['template'] ?? '')) === $templateCode
                && trim((string) ($entry['at'] ?? '')) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{code:string,data:array<string,mixed>}
     */
    public function parseAdminInput(array $input, ?string $existingCode = null): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? $existingCode ?? '')));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?? '';
        $code = trim($code, '_');
        if ($code === '') {
            throw new \InvalidArgumentException('El código de la plantilla es obligatorio.');
        }
        if ($existingCode !== null && $existingCode !== '' && $code !== $existingCode) {
            throw new \InvalidArgumentException('El código de la plantilla no se puede cambiar.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre de la plantilla es obligatorio.');
        }

        $headers = is_array($input['col_header'] ?? null) ? $input['col_header'] : [];
        $fields = is_array($input['col_field'] ?? null) ? $input['col_field'] : [];
        $formulas = is_array($input['col_formula'] ?? null) ? $input['col_formula'] : [];
        $columns = [];
        $n = max(count($headers), count($fields), count($formulas));
        for ($i = 0; $i < $n; $i++) {
            $header = trim((string) ($headers[$i] ?? ''));
            $field = trim((string) ($fields[$i] ?? ''));
            $formula = trim((string) ($formulas[$i] ?? ''));
            if ($header === '' && $field === '' && $formula === '') {
                continue;
            }
            if ($header === '' || ($field === '' && $formula === '')) {
                throw new \InvalidArgumentException('Cada columna necesita título y un campo o fórmula.');
            }
            $col = ['header' => mb_substr($header, 0, 120), 'field' => mb_substr($field !== '' ? $field : 'full_name', 0, 80)];
            if ($formula !== '') {
                $col['formula'] = mb_substr($formula, 0, 500);
            }
            $columns[] = $col;
        }
        if ($columns === []) {
            throw new \InvalidArgumentException('Agrega al menos una columna al CSV.');
        }

        $normalize = (string) ($input['normalize'] ?? 'none');
        if (!in_array($normalize, ['none', 'toefl'], true)) {
            $normalize = 'none';
        }
        $batchBy = (string) ($input['batch_by'] ?? 'none');
        if (!in_array($batchBy, ['none', 'exam_date'], true)) {
            $batchBy = 'none';
        }

        $productCodesRaw = trim((string) ($input['product_codes'] ?? ''));
        $productCodes = [];
        if ($productCodesRaw !== '') {
            foreach (preg_split('/[\s,;]+/', $productCodesRaw) ?: [] as $c) {
                $c = trim((string) $c);
                if ($c !== '') {
                    $productCodes[] = $c;
                }
            }
        }
        $groupCodesRaw = trim((string) ($input['product_group_codes'] ?? ''));
        $groupCodes = [];
        if ($groupCodesRaw !== '') {
            foreach (preg_split('/[\s,;]+/', $groupCodesRaw) ?: [] as $c) {
                $c = trim((string) $c);
                if ($c !== '') {
                    $groupCodes[] = $c;
                }
            }
        }

        $purchaseStatus = [];
        $ps = $input['purchase_status'] ?? 'paid';
        if (is_string($ps)) {
            foreach (preg_split('/[\s,;]+/', $ps) ?: [] as $s) {
                $s = trim((string) $s);
                if ($s !== '') {
                    $purchaseStatus[] = $s;
                }
            }
        } elseif (is_array($ps)) {
            foreach ($ps as $s) {
                $s = trim((string) $s);
                if ($s !== '') {
                    $purchaseStatus[] = $s;
                }
            }
        }
        if ($purchaseStatus === []) {
            $purchaseStatus = ['paid'];
        }

        $mapping = [
            'columns' => $columns,
            'normalize' => $normalize,
            'filters' => [
                'product_codes' => $productCodes,
                'product_group_codes' => $groupCodes,
                'purchase_status' => $purchaseStatus,
            ],
        ];

        return [
            'code' => $code,
            'data' => [
                'name' => mb_substr($name, 0, 190),
                'supplier_id' => !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null,
                'file_type' => 'csv',
                'storage_path' => 'templates/' . $code . '.csv',
                'delivery' => 'download',
                'batch_by' => $batchBy,
                'mapping_json' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
                'is_active' => (!empty($input['is_active']) || !empty($input['active'])) ? 1 : 0,
            ],
        ];
    }

    public function createFromAdmin(array $input): string
    {
        $parsed = $this->parseAdminInput($input);
        if ($this->templates->findByCode($parsed['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe una plantilla con el código ' . $parsed['code']);
        }
        $this->templates->upsert($parsed['code'], $parsed['data']);

        return $parsed['code'];
    }

    public function updateFromAdmin(string $code, array $input): void
    {
        if ($this->templates->findByCode($code) === null) {
            throw new \InvalidArgumentException('Plantilla no encontrada.');
        }
        $parsed = $this->parseAdminInput($input, $code);
        $this->templates->upsert($code, $parsed['data']);
    }

    public function delete(string $code): void
    {
        if ($this->templates->findByCode($code) === null) {
            throw new \InvalidArgumentException('Plantilla no encontrada.');
        }
        $this->templates->deleteByCode($code);
    }

    /** @param array<string, mixed> $template @return array<string, mixed> */
    public function mapping(array $template): array
    {
        $raw = $template['mapping_json'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Los datos de checkout viven en students (+ extra_fields_json), no en trackings.checkout_json
     * (esa columna no existe). Armamos un mapa compatible con fieldValue().
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateCheckoutFromStudent(array $row): array
    {
        $checkout = [];
        $extraRaw = $row['student_extra_fields_json'] ?? null;
        if (is_string($extraRaw) && $extraRaw !== '') {
            $decoded = json_decode($extraRaw, true);
            if (is_array($decoded)) {
                $checkout = $decoded;
            }
        } elseif (is_array($extraRaw)) {
            $checkout = $extraRaw;
        }

        $map = [
            'curp' => 'student_curp',
            'birth_date' => 'student_birth_date',
            'sex' => 'student_sex',
            'nationality' => 'student_nationality',
            'address' => 'student_address_street',
            'street' => 'student_address_street',
            'city' => 'student_address_city',
            'state' => 'student_address_state',
            'zip' => 'student_address_zip',
            'colony' => 'student_address_colony',
            'country' => 'student_address_country',
        ];
        foreach ($map as $field => $col) {
            $val = trim((string) ($row[$col] ?? ''));
            if ($val === '') {
                continue;
            }
            if (!isset($checkout[$field]) || trim((string) $checkout[$field]) === '') {
                $checkout[$field] = $val;
            }
        }

        $row['checkout_json'] = $checkout;

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function fieldValue(array $row, string $field): string
    {
        $checkout = [];
        $rawCheckout = $row['checkout_json'] ?? null;
        if (is_string($rawCheckout) && $rawCheckout !== '') {
            $decoded = json_decode($rawCheckout, true);
            if (is_array($decoded)) {
                $checkout = $decoded;
            }
        } elseif (is_array($rawCheckout)) {
            $checkout = $rawCheckout;
        }

        $base = match ($field) {
            'matricula' => (string) ($row['matricula'] ?? ''),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name_p' => (string) ($row['last_name_p'] ?? ''),
            'last_name_m' => (string) ($row['last_name_m'] ?? ''),
            'full_name' => trim(
                (string) ($row['first_name'] ?? '') . ' '
                . (string) ($row['last_name_p'] ?? '') . ' '
                . (string) ($row['last_name_m'] ?? '')
            ),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ($checkout['phone'] ?? '')),
            'exam_date' => (string) ($row['exam_date'] ?? ''),
            'exam_time' => !empty($row['exam_time']) ? substr((string) $row['exam_time'], 0, 5) : '',
            'product_name' => (string) ($row['product_name'] ?? ''),
            'product_code' => (string) ($row['product_code'] ?? ''),
            'product_group_code' => (string) ($row['product_group_code'] ?? ''),
            'partner_code' => (string) ($row['partner_code'] ?? ''),
            'folio' => (string) ($row['folio'] ?? ''),
            'access_key' => (string) ($row['access_key'] ?? ''),
            'zoom_url', 'extra' => (string) ($row['zoom_url'] ?? ''),
            'curp' => (string) ($checkout['curp'] ?? ''),
            'birth_date' => (string) ($checkout['birth_date'] ?? ''),
            'sex' => (string) ($checkout['sex'] ?? ''),
            'nationality' => (string) ($checkout['nationality'] ?? ''),
            'passport' => (string) ($checkout['passport'] ?? ''),
            'address' => (string) ($checkout['address'] ?? ''),
            'city' => (string) ($checkout['city'] ?? ''),
            'state' => (string) ($checkout['state'] ?? ''),
            default => (string) ($checkout[$field] ?? ($row[$field] ?? '')),
        };

        return trim($base);
    }

    /**
     * Mapa de campos para fórmulas CSV (misma fila del alumno).
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function fieldsBagForRow(array $row): array
    {
        $keys = [
            'matricula', 'first_name', 'last_name_p', 'last_name_m', 'full_name', 'email', 'phone',
            'exam_date', 'exam_time', 'product_name', 'product_code', 'product_group_code',
            'partner_code', 'folio', 'access_key', 'zoom_url', 'extra',
            'curp', 'birth_date', 'sex', 'nationality', 'passport', 'address', 'city', 'state',
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->fieldValue($row, $key);
        }
        // También expone checkout extras
        $rawCheckout = $row['checkout_json'] ?? null;
        $checkout = [];
        if (is_string($rawCheckout) && $rawCheckout !== '') {
            $decoded = json_decode($rawCheckout, true);
            if (is_array($decoded)) {
                $checkout = $decoded;
            }
        } elseif (is_array($rawCheckout)) {
            $checkout = $rawCheckout;
        }
        foreach ($checkout as $k => $v) {
            if (!is_string($k) || $k === '' || array_key_exists($k, $out) || !is_scalar($v)) {
                continue;
            }
            $out[$k] = (string) $v;
        }

        return $out;
    }

    /** @return list<array{header:string,field:string}> */
    private function defaultUksColumns(): array
    {
        return [
            ['header' => 'Matrícula', 'field' => 'matricula'],
            ['header' => 'Apellido Paterno', 'field' => 'last_name_p'],
            ['header' => 'Apellido Materno', 'field' => 'last_name_m'],
            ['header' => 'Nombre(s)', 'field' => 'first_name'],
            ['header' => 'Correo Electrónico', 'field' => 'email'],
        ];
    }

    /** @param array<string, mixed> $options */
    private function downloadFilename(string $code, array $options): string
    {
        $parts = ['doceo', $code];
        if (!empty($options['tracking_id'])) {
            $parts[] = 'caso-' . (int) $options['tracking_id'];
        }
        if (!empty($options['exam_date'])) {
            $parts[] = (string) $options['exam_date'];
        }
        $parts[] = date('Ymd-His');

        return preg_replace('/[^a-zA-Z0-9._-]+/', '-', implode('_', $parts)) . '.csv';
    }
}

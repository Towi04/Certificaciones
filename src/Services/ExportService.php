<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\ExportTemplateRepository;
use App\Support\AsciiUpperNormalizer;
use PDO;

final class ExportService
{
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
     *   step_codes?:list<string>|null
     * } $options
     * @return list<array<string, string>>
     */
    public function rowsForTemplate(string $code, array $options = []): array
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
                       t.checkout_json, t.current_step_code, t.extra_json,
                       pr.id AS product_id, pr.code AS product_code, pr.name AS product_name,
                       pg.id AS product_group_id, pg.code AS product_group_code,
                       pa.code AS partner_code
                FROM trackings t
                JOIN purchases pu ON pu.id = t.purchase_id
                JOIN users u ON u.id = t.student_user_id
                JOIN products pr ON pr.id = t.product_id
                LEFT JOIN product_groups pg ON pg.id = pr.product_group_id
                LEFT JOIN partners pa ON pa.id = t.partner_id
                WHERE t.status <> ?';
        $params = ['cancelled'];

        if (!empty($options['tracking_id'])) {
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

        $columns = $mapping['columns'] ?? $this->defaultUksColumns();
        $out = [];
        foreach ($rows as $row) {
            $mapped = [];
            foreach ($columns as $col) {
                if (!is_array($col)) {
                    continue;
                }
                $header = trim((string) ($col['header'] ?? ''));
                $field = trim((string) ($col['field'] ?? ''));
                if ($header === '' || $field === '') {
                    continue;
                }
                $value = $this->fieldValue($row, $field);
                if ($normalize === 'toefl') {
                    $value = AsciiUpperNormalizer::normalize($value);
                }
                $mapped[$header] = $value;
            }
            if ($mapped !== []) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function csvContent(string $code, array $options = []): string
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

        $rows = $this->rowsForTemplate($code, $options);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo generar el CSV.');
        }

        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($stream, $line);
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content === false ? '' : $content;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendDownload(string $code, array $options = []): void
    {
        $template = $this->template($code);
        if ($template === null) {
            throw new \InvalidArgumentException('Plantilla de exportación no encontrada: ' . $code);
        }

        $content = $this->csvContent($code, $options);
        $filename = $this->downloadFilename($code, $options);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) strlen($content));
        header('X-Content-Type-Options: nosniff');
        echo "\xEF\xBB\xBF" . $content;
        exit;
    }

    /**
     * Descarga desde un caso: alumno solo, o todos con misma fecha + misma cert.
     *
     * @param array{scope?:string} $opts
     */
    public function sendDownloadForTracking(string $code, int $trackingId, array $opts = []): void
    {
        $tracking = (new TrackingService())->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Caso no encontrado.');
        }

        $scope = (string) ($opts['scope'] ?? 'student');
        if (!in_array($scope, ['student', 'exam_date'], true)) {
            $scope = 'student';
        }

        if ($scope === 'student') {
            $this->sendDownload($code, ['tracking_id' => $trackingId]);

            return;
        }

        $examDate = trim((string) ($tracking['exam_date'] ?? ''));
        if ($examDate === '') {
            throw new \InvalidArgumentException('El caso no tiene fecha de examen para generar el lote del día.');
        }

        $options = [
            'exam_date' => $examDate,
            'product_id' => (int) ($tracking['product_id'] ?? 0),
        ];
        if ($options['product_id'] < 1) {
            throw new \InvalidArgumentException('El caso no tiene producto asociado.');
        }

        $this->sendDownload($code, $options);
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
        $columns = [];
        $n = max(count($headers), count($fields));
        for ($i = 0; $i < $n; $i++) {
            $header = trim((string) ($headers[$i] ?? ''));
            $field = trim((string) ($fields[$i] ?? ''));
            if ($header === '' && $field === '') {
                continue;
            }
            if ($header === '' || $field === '') {
                throw new \InvalidArgumentException('Cada columna necesita título y dato.');
            }
            $columns[] = ['header' => mb_substr($header, 0, 120), 'field' => mb_substr($field, 0, 80)];
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

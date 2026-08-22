<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\ExportTemplateRepository;
use PDO;

final class ExportService
{
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
     *   purchase_status?:list<string>,
     *   step_codes?:list<string>
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

        $productCodes = $filters['product_codes'] ?? ['ELET-UKS'];
        if (!is_array($productCodes) || $productCodes === []) {
            $productCodes = ['ELET-UKS'];
        }

        $purchaseStatuses = $options['purchase_status'] ?? ($filters['purchase_status'] ?? ['paid']);
        if (!is_array($purchaseStatuses) || $purchaseStatuses === []) {
            $purchaseStatuses = ['paid'];
        }

        $sql = 'SELECT pu.matricula, u.first_name, u.last_name_p, u.last_name_m, u.email,
                       t.id AS tracking_id, t.exam_date, t.exam_time, t.current_step_code, pr.code AS product_code
                FROM trackings t
                JOIN purchases pu ON pu.id = t.purchase_id
                JOIN users u ON u.id = t.student_user_id
                JOIN products pr ON pr.id = t.product_id
                WHERE t.status <> ?
                  AND pr.code IN (' . implode(',', array_fill(0, count($productCodes), '?')) . ')
                  AND pu.status IN (' . implode(',', array_fill(0, count($purchaseStatuses), '?')) . ')';
        $params = ['cancelled', ...$productCodes, ...$purchaseStatuses];

        if (!empty($options['tracking_id'])) {
            $sql .= ' AND t.id = ?';
            $params[] = (int) $options['tracking_id'];
        }

        if (!empty($options['exam_date'])) {
            $sql .= ' AND t.exam_date = ?';
            $params[] = (string) $options['exam_date'];
        }

        $stepCodes = $options['step_codes'] ?? ($filters['step_codes'] ?? null);
        if (is_array($stepCodes) && $stepCodes !== []) {
            $sql .= ' AND t.current_step_code IN (' . implode(',', array_fill(0, count($stepCodes), '?')) . ')';
            array_push($params, ...$stepCodes);
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
                $header = (string) ($col['header'] ?? '');
                $field = (string) ($col['field'] ?? '');
                if ($header === '' || $field === '') {
                    continue;
                }
                $mapped[$header] = $this->fieldValue($row, $field);
            }
            if ($mapped !== []) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param array{
     *   tracking_id?:int,
     *   exam_date?:string,
     *   purchase_status?:list<string>,
     *   step_codes?:list<string>
     * } $options
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
            if (is_array($col) && !empty($col['header'])) {
                $headers[] = (string) $col['header'];
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
     * @param array{
     *   tracking_id?:int,
     *   exam_date?:string,
     *   purchase_status?:list<string>,
     *   step_codes?:list<string>
     * } $options
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

    /** @param array<string, mixed> $template @return array<string, mixed> */
    private function mapping(array $template): array
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
        return match ($field) {
            'matricula' => (string) ($row['matricula'] ?? ''),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name_p' => (string) ($row['last_name_p'] ?? ''),
            'last_name_m' => (string) ($row['last_name_m'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'exam_date' => (string) ($row['exam_date'] ?? ''),
            'exam_time' => !empty($row['exam_time']) ? substr((string) $row['exam_time'], 0, 5) : '',
            default => (string) ($row[$field] ?? ''),
        };
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

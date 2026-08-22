<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Mailer;
use App\Repositories\ImportTemplateRepository;
use App\Database\Connection;
use PDO;

final class ImportService
{
    private PDO $pdo;
    private ImportTemplateRepository $templates;
    private TrackingService $tracking;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->templates = new ImportTemplateRepository();
        $this->tracking = new TrackingService();
    }

    /** @return array<string, mixed>|null */
    public function template(string $code): ?array
    {
        return $this->templates->findByCode($code);
    }

    /**
     * @return array{
     *   processed:int,
     *   updated:int,
     *   skipped:int,
     *   errors:list<string>,
     *   notifications:list<string>
     * }
     */
    public function importUksReport(string $templateCode, string $csvPath, ?int $adminUserId = null): array
    {
        $template = $this->template($templateCode);
        if ($template === null) {
            throw new \InvalidArgumentException('Plantilla de importación no encontrada: ' . $templateCode);
        }

        $mapping = $this->mapping($template);
        $rows = $this->parseCsv($csvPath);
        if ($rows === []) {
            throw new \InvalidArgumentException('El archivo CSV está vacío o no tiene filas de datos.');
        }

        $result = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'notifications' => [],
        ];

        foreach ($rows as $i => $row) {
            $result['processed']++;
            $line = $i + 2;
            try {
                $outcome = $this->applyReportRow($mapping, $row, $adminUserId);
                if ($outcome['updated']) {
                    $result['updated']++;
                    foreach ($outcome['notifications'] as $note) {
                        $result['notifications'][] = $note;
                    }
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $matricula = $this->rowValue($row, (string) ($mapping['match_column'] ?? 'Matrícula'));
                $result['errors'][] = "Fila {$line}" . ($matricula !== '' ? " ({$matricula})" : '') . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, string> $row
     * @return array{updated:bool,notifications:list<string>}
     */
    private function applyReportRow(array $mapping, array $row, ?int $adminUserId): array
    {
        $matchColumn = (string) ($mapping['match_column'] ?? 'Matrícula');
        $matricula = trim($this->rowValue($row, $matchColumn));
        if ($matricula === '') {
            throw new \InvalidArgumentException('Matrícula vacía.');
        }

        $productCode = (string) ($mapping['product_code'] ?? 'ELET-UKS');
        $tracking = $this->findTrackingByMatricula($matricula, $productCode);
        if ($tracking === null) {
            throw new \InvalidArgumentException('No se encontró caso ' . $productCode . ' con matrícula ' . $matricula . '.');
        }

        $cols = is_array($mapping['columns'] ?? null) ? $mapping['columns'] : [];
        $extraCols = is_array($mapping['extra_columns'] ?? null) ? $mapping['extra_columns'] : [];

        $prevExtra = $this->decodeExtra($tracking['extra_json'] ?? null);
        $prevCenni = is_array($prevExtra['uks_report']['cenni'] ?? null) ? $prevExtra['uks_report']['cenni'] : [];

        $folioUks = trim($this->rowValue($row, (string) ($cols['folio'] ?? 'Folio')));
        $cenniFolio = trim($this->rowValue($row, (string) ($cols['cenni_folio'] ?? 'Folio CENNI')));
        $resultsLevel = trim($this->rowValue($row, (string) ($cols['results_level'] ?? 'Nivel Alcanzado')));
        $resultsScoreRaw = trim($this->rowValue($row, (string) ($cols['results_score'] ?? 'Puntaje')));
        $resultsUrl = trim($this->rowValue($row, (string) ($cols['results_url'] ?? 'Certificado')));
        $examCompleted = trim($this->rowValue($row, (string) ($cols['exam_completed_at'] ?? 'Realizado')));

        $cenniDocs = [
            'solicitud' => $this->parseDocStatus($this->rowValue($row, (string) ($cols['cenni_doc_solicitud'] ?? 'Doc. Solicitud Cenni'))),
            'curp' => $this->parseDocStatus($this->rowValue($row, (string) ($cols['cenni_doc_curp'] ?? 'Doc. CURP'))),
            'ine' => $this->parseDocStatus($this->rowValue($row, (string) ($cols['cenni_doc_ine'] ?? 'Doc. Identificación Oficial'))),
        ];
        $cenniDocumentacion = trim($this->rowValue($row, (string) ($cols['cenni_documentacion'] ?? 'Documentación')));

        $sections = [
            'listening' => [
                'level' => $this->rowValue($row, (string) ($extraCols['listening_level'] ?? 'Listening Nivel')),
                'percent' => $this->rowValue($row, (string) ($extraCols['listening_percent'] ?? 'Listening %')),
            ],
            'reading' => [
                'level' => $this->rowValue($row, (string) ($extraCols['reading_level'] ?? 'Reading Nivel')),
                'percent' => $this->rowValue($row, (string) ($extraCols['reading_percent'] ?? 'Reading %')),
            ],
            'use_of_english' => [
                'level' => $this->rowValue($row, (string) ($extraCols['use_of_english_level'] ?? 'Use of English Nivel')),
                'percent' => $this->rowValue($row, (string) ($extraCols['use_of_english_percent'] ?? 'Use of English %')),
            ],
            'writing' => [
                'level' => $this->rowValue($row, (string) ($extraCols['writing_level'] ?? 'Writing Nivel')),
                'percent' => $this->rowValue($row, (string) ($extraCols['writing_percent'] ?? 'Writing %')),
            ],
        ];

        $uksReport = [
            'imported_at' => date('c'),
            'sede' => $this->rowValue($row, (string) ($extraCols['sede'] ?? 'Sede')),
            'folio_uks' => $folioUks,
            'exam_completed_at' => $examCompleted,
            'payment_status' => $this->rowValue($row, (string) ($extraCols['payment_status'] ?? 'Pago')),
            'curp' => $this->rowValue($row, (string) ($extraCols['curp'] ?? 'CURP')),
            'sections' => $sections,
            'cenni' => [
                'folio' => $cenniFolio,
                'documentacion' => $cenniDocumentacion,
                'docs' => $cenniDocs,
                'sep_consulta_url' => 'https://cennisistema.sep.gob.mx/cenni/consulta/consultaEstatus.jsp',
            ],
        ];

        $prevExtra['uks_report'] = $uksReport;
        $extraJson = json_encode($prevExtra, JSON_UNESCAPED_UNICODE);

        $resultsScore = $resultsScoreRaw !== '' && is_numeric($resultsScoreRaw) ? (float) $resultsScoreRaw : null;

        $this->pdo->prepare(
            'UPDATE trackings
             SET folio = COALESCE(NULLIF(?, \'\'), folio),
                 results_level = COALESCE(NULLIF(?, \'\'), results_level),
                 results_score = COALESCE(?, results_score),
                 results_url = COALESCE(NULLIF(?, \'\'), results_url),
                 cenni_folio = COALESCE(NULLIF(?, \'\'), cenni_folio),
                 extra_json = ?
             WHERE id = ?'
        )->execute([
            $folioUks,
            $resultsLevel,
            $resultsScore,
            $resultsUrl,
            $cenniFolio,
            $extraJson,
            (int) $tracking['id'],
        ]);

        $cenniTracking = $this->findCenniTracking((int) $tracking['purchase_id']);
        if ($cenniTracking !== null) {
            $cenniExtra = $this->decodeExtra($cenniTracking['extra_json'] ?? null);
            $cenniExtra['uks_report'] = $uksReport['cenni'];
            $this->pdo->prepare(
                'UPDATE trackings SET cenni_folio = COALESCE(NULLIF(?, \'\'), cenni_folio), extra_json = ? WHERE id = ?'
            )->execute([
                $cenniFolio,
                json_encode($cenniExtra, JSON_UNESCAPED_UNICODE),
                (int) $cenniTracking['id'],
            ]);
        }

        $notifications = [];
        $logParts = ['Import UKS: resultados actualizados'];
        if ($folioUks !== '') {
            $logParts[] = 'folio UKS ' . $folioUks;
        }
        if ($cenniFolio !== '') {
            $logParts[] = 'folio CENNI ' . $cenniFolio;
        }
        $this->tracking->addLog((int) $tracking['id'], 'resultados', implode(' · ', $logParts), $adminUserId);

        if ($cenniFolio !== '' && ($prevCenni['folio'] ?? '') !== $cenniFolio) {
            $msg = $this->notifyCenniFolio($tracking, $cenniFolio);
            if ($msg !== null) {
                $notifications[] = $msg;
            }
            if ($cenniTracking !== null) {
                try {
                    $this->tracking->setStep((int) $cenniTracking['id'], 'folio', $adminUserId, 'Folio CENNI importado desde UKS', 'waiting_student');
                } catch (\Throwable) {
                    // pipeline step may not exist yet
                }
            }
        }

        $docNotify = $this->notifyCenniDocChanges($tracking, $prevCenni, $uksReport['cenni']);
        $notifications = array_merge($notifications, $docNotify);

        if ($resultsLevel !== '' || $resultsUrl !== '') {
            $current = (string) ($tracking['current_step_code'] ?? '');
            if (in_array($current, ['codigos', 'solicitud_uks', 'confirm_pago'], true)) {
                try {
                    $this->tracking->setStep((int) $tracking['id'], 'resultados', $adminUserId, 'Resultados importados desde UKS', 'waiting_student');
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        return ['updated' => true, 'notifications' => $notifications];
    }

    /** @return array<string, string> */
    private function parseCsv(string $path): array
    {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException('No se pudo leer el archivo CSV.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \InvalidArgumentException('No se pudo abrir el archivo CSV.');
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);
            throw new \InvalidArgumentException('El CSV no tiene encabezados.');
        }

        $header = array_map(static fn ($h) => trim((string) $h), $header);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }
            $allEmpty = true;
            foreach ($data as $cell) {
                if (trim((string) $cell) !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = trim((string) ($data[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
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

    /** @return array<string, mixed>|null */
    private function findTrackingByMatricula(string $matricula, string $productCode): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pu.matricula, u.email AS student_email, u.first_name, u.last_name_p, pr.code AS product_code
             FROM trackings t
             JOIN purchases pu ON pu.id = t.purchase_id
             JOIN products pr ON pr.id = t.product_id
             JOIN users u ON u.id = t.student_user_id
             WHERE pu.matricula = ? AND pr.code = ?
             ORDER BY t.id ASC
             LIMIT 1'
        );
        $stmt->execute([$matricula, $productCode]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function findCenniTracking(int $purchaseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             WHERE t.purchase_id = ? AND pr.code = ?
             LIMIT 1'
        );
        $stmt->execute([$purchaseId, 'ELET-CENNI']);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, string> $row */
    private function rowValue(array $row, string $column): string
    {
        if ($column === '') {
            return '';
        }
        if (array_key_exists($column, $row)) {
            return trim((string) $row[$column]);
        }
        foreach ($row as $key => $value) {
            if (strcasecmp((string) $key, $column) === 0) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /** @return array{status:string,raw:string,label:string} */
    private function parseDocStatus(string $raw): array
    {
        $raw = trim($raw);
        $lower = mb_strtolower($raw);
        $status = 'pending';
        if ($raw === '✔' || $raw === '✓' || str_contains($lower, 'aprob') || $lower === 'si' || $lower === 'sí') {
            $status = 'approved';
        } elseif ($raw === '✗' || $raw === 'X' || str_contains($lower, 'rechaz') || str_contains($lower, 'no')) {
            $status = 'rejected';
        }

        return [
            'status' => $status,
            'raw' => $raw,
            'label' => match ($status) {
                'approved' => 'Aprobado',
                'rejected' => 'Rechazado',
                default => $raw !== '' ? $raw : 'Pendiente',
            },
        ];
    }

    /** @return array<string, mixed> */
    private function decodeExtra(mixed $json): array
    {
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($json) ? $json : [];
    }

    /** @param array<string, mixed> $tracking */
    private function notifyCenniFolio(array $tracking, string $folio): ?string
    {
        $email = (string) ($tracking['student_email'] ?? '');
        if ($email === '') {
            return null;
        }

        $name = trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''));
        $matricula = (string) ($tracking['matricula'] ?? '');
        $sepUrl = 'https://cennisistema.sep.gob.mx/cenni/consulta/consultaEstatus.jsp';

        try {
            $mailer = new Mailer();
            $text = "Hola {$name},\n\n"
                . "Ya está disponible tu folio CENNI para el caso {$matricula}.\n"
                . "Folio CENNI: {$folio}\n\n"
                . "Puedes consultar el estatus de tu trámite en:\n{$sepUrl}\n";
            $html = '<p>Hola ' . htmlspecialchars($name) . ',</p>'
                . '<p>Ya está disponible tu <strong>folio CENNI</strong> para el caso '
                . htmlspecialchars($matricula) . '.</p>'
                . '<p><strong>Folio CENNI:</strong> ' . htmlspecialchars($folio) . '</p>'
                . '<p><a href="' . htmlspecialchars($sepUrl) . '">Consultar estatus en SEP</a></p>';
            $mailer->send($email, 'Folio CENNI disponible — caso ' . $matricula, $text, $html);
        } catch (\Throwable) {
            return 'Folio CENNI ' . $folio . ' para ' . $matricula . ' (correo no enviado)';
        }

        return 'Folio CENNI ' . $folio . ' publicado para ' . $matricula;
    }

    /**
     * @param array<string, mixed> $prevCenni
     * @param array<string, mixed> $newCenni
     * @return list<string>
     */
    private function notifyCenniDocChanges(array $tracking, array $prevCenni, array $newCenni): array
    {
        $docLabels = [
            'solicitud' => 'Solicitud CENNI',
            'curp' => 'CURP',
            'ine' => 'Identificación oficial',
        ];
        $prevDocs = is_array($prevCenni['docs'] ?? null) ? $prevCenni['docs'] : [];
        $newDocs = is_array($newCenni['docs'] ?? null) ? $newCenni['docs'] : [];
        $notifications = [];
        $changed = false;
        $lines = [];

        foreach ($docLabels as $key => $label) {
            $prevStatus = (string) ($prevDocs[$key]['status'] ?? 'pending');
            $newStatus = (string) ($newDocs[$key]['status'] ?? 'pending');
            if ($newStatus === 'pending' || $newStatus === $prevStatus) {
                continue;
            }
            $changed = true;
            $lines[] = $label . ': ' . ($newDocs[$key]['label'] ?? $newStatus);
        }

        $prevDocOverall = (string) ($prevCenni['documentacion'] ?? '');
        $newDocOverall = (string) ($newCenni['documentacion'] ?? '');
        if ($newDocOverall !== '' && $newDocOverall !== $prevDocOverall) {
            $changed = true;
            $lines[] = 'Documentación general: ' . $newDocOverall;
        }

        if (!$changed || $lines === []) {
            return [];
        }

        $email = (string) ($tracking['student_email'] ?? '');
        $name = trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''));
        $matricula = (string) ($tracking['matricula'] ?? '');
        $bodyLines = implode("\n", $lines);

        if ($email !== '') {
            try {
                $mailer = new Mailer();
                $text = "Hola {$name},\n\nActualizamos el estatus de tus documentos CENNI (caso {$matricula}):\n{$bodyLines}\n\nRevisa los detalles en tu panel de alumno.\n";
                $html = '<p>Hola ' . htmlspecialchars($name) . ',</p>'
                    . '<p>Actualizamos el estatus de tus documentos CENNI (caso '
                    . htmlspecialchars($matricula) . '):</p><ul>';
                foreach ($lines as $line) {
                    $html .= '<li>' . htmlspecialchars($line) . '</li>';
                }
                $html .= '</ul><p>Revisa los detalles en tu panel de alumno.</p>';
                $mailer->send($email, 'Estatus documentos CENNI — caso ' . $matricula, $text, $html);
            } catch (\Throwable) {
                // panel still shows update
            }
        }

        $this->tracking->addLog((int) $tracking['id'], 'resultados', 'CENNI docs: ' . implode(' · ', $lines), null);
        $notifications[] = 'Docs CENNI actualizados para ' . $matricula;

        return $notifications;
    }

    /** @return array<string, mixed>|null */
    public static function uksReportFromTracking(array $tracking): ?array
    {
        $extra = $tracking['extra_json'] ?? null;
        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
        } else {
            $decoded = is_array($extra) ? $extra : [];
        }
        $report = $decoded['uks_report'] ?? null;

        return is_array($report) ? $report : null;
    }
}

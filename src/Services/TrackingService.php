<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Integrations\Mailer;
use PDO;

final class TrackingService
{
    private PDO $pdo;
    private DocumentService $documents;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->documents = new DocumentService();
    }

    /**
     * Paso inicial al crear el tracking en checkout.
     *
     * @param list<array{code:string,label:string,required:bool,accept:string}> $requiredDocs
     */
    public static function initialStepCode(string $productType, array $requiredDocs): string
    {
        $hasDocs = $requiredDocs !== [];

        return match ($productType) {
            'course' => 'pago',
            'procedure' => 'docs',
            default => $hasDocs ? 'docs' : 'pago',
        };
    }

    public static function initialStatus(string $paymentMethod): string
    {
        // Comprobante → admin revisa pago; SPEI → también puede requerir confirmación
        return in_array($paymentMethod, ['transfer_proof', 'openpay_spei', 'openpay_store'], true)
            ? 'waiting_admin'
            : 'open';
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, pr.name AS product_name, pr.type AS product_type, pr.slug AS product_slug,
                    pr.platform_type, pr.moodle_course_id, pr.access_months, pr.config_json,
                    pu.matricula, pu.status AS purchase_status, pu.charged_amount, pu.payment_method,
                    pu.payment_proof_path, pu.student_user_id AS purchase_student_id,
                    u.first_name, u.last_name_p, u.last_name_m, u.email AS student_email, u.phone AS student_phone,
                    pt.code AS pipeline_code, pt.name AS pipeline_name
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN purchases pu ON pu.id = t.purchase_id
             JOIN users u ON u.id = t.student_user_id
             LEFT JOIN pipeline_templates pt ON pt.id = t.pipeline_template_id
             WHERE t.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function steps(int $pipelineTemplateId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pipeline_steps WHERE pipeline_template_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$pipelineTemplateId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function logs(int $trackingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, u.first_name, u.last_name_p
             FROM tracking_step_logs l
             LEFT JOIN users u ON u.id = l.actor_user_id
             WHERE l.tracking_id = ?
             ORDER BY l.created_at DESC, l.id DESC'
        );
        $stmt->execute([$trackingId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function documentsForTracking(int $trackingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM documents WHERE tracking_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$trackingId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findDocument(int $docId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE id = ? LIMIT 1');
        $stmt->execute([$docId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function setStep(
        int $trackingId,
        string $stepCode,
        ?int $actorUserId,
        ?string $note = null,
        ?string $status = null
    ): void {
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        $pipelineId = (int) ($tracking['pipeline_template_id'] ?? 0);
        if ($pipelineId > 0) {
            $steps = $this->steps($pipelineId);
            $codes = array_column($steps, 'code');
            if ($codes !== [] && !in_array($stepCode, $codes, true)) {
                throw new \InvalidArgumentException('Paso no pertenece al pipeline: ' . $stepCode);
            }
            $stepRow = null;
            foreach ($steps as $s) {
                if ((string) $s['code'] === $stepCode) {
                    $stepRow = $s;
                    break;
                }
            }
            if ($status === null && $stepRow !== null) {
                $status = $this->statusForActor((string) $stepRow['actor'], (bool) $stepRow['is_terminal']);
            }
        }

        if ($status === null) {
            $status = (string) $tracking['status'];
        }

        $this->pdo->prepare(
            'UPDATE trackings SET current_step_code = ?, status = ? WHERE id = ?'
        )->execute([$stepCode, $status, $trackingId]);

        $this->log($trackingId, $stepCode, $note, $actorUserId);
    }

    public function advance(int $trackingId, ?int $actorUserId, ?string $note = null): string
    {
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }
        $pipelineId = (int) ($tracking['pipeline_template_id'] ?? 0);
        if ($pipelineId < 1) {
            throw new \InvalidArgumentException('Este seguimiento no tiene pipeline.');
        }

        $steps = $this->steps($pipelineId);
        if ($steps === []) {
            throw new \InvalidArgumentException('Pipeline sin pasos.');
        }

        $current = (string) ($tracking['current_step_code'] ?? '');
        $idx = -1;
        foreach ($steps as $i => $s) {
            if ((string) $s['code'] === $current) {
                $idx = $i;
                break;
            }
        }
        $next = $steps[$idx + 1] ?? null;
        if ($next === null) {
            throw new \InvalidArgumentException('Ya está en el último paso.');
        }

        $code = (string) $next['code'];
        $this->setStep($trackingId, $code, $actorUserId, $note ?? ('Avance a ' . $next['label']), null);

        return $code;
    }

    /**
     * Checklist de documentos de registro vs lo ya subido.
     *
     * @param array<string, mixed> $product
     * @return list<array{
     *   code:string,label:string,required:bool,accept:string,
     *   status:?string,document_id:?int,original_name:?string,rejection_reason:?string
     * }>
     */
    public function registrationChecklist(int $trackingId, array $product): array
    {
        $required = CheckoutRequirements::registrationDocsForProduct($product);
        if ($required === []) {
            return [];
        }

        $existing = $this->documentsForTracking($trackingId);
        /** @var array<string, array<string, mixed>> $byType */
        $byType = [];
        foreach ($existing as $doc) {
            $code = (string) $doc['doc_type'];
            // Conserva el más reciente por tipo
            if (!isset($byType[$code])) {
                $byType[$code] = $doc;
            }
        }

        $out = [];
        foreach ($required as $req) {
            $doc = $byType[$req['code']] ?? null;
            $out[] = [
                'code' => $req['code'],
                'label' => $req['label'],
                'required' => $req['required'],
                'accept' => $req['accept'],
                'status' => $doc ? (string) $doc['status'] : null,
                'document_id' => $doc ? (int) $doc['id'] : null,
                'original_name' => $doc ? (string) $doc['original_name'] : null,
                'rejection_reason' => $doc['rejection_reason'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Primera carga o reemplazo de un documento del expediente de registro.
     *
     * @param array{tmp_name:string,name:string,error:int,size:int} $file
     */
    public function uploadRegistrationDocument(
        int $trackingId,
        int $studentUserId,
        string $docType,
        array $file
    ): int {
        $tracking = $this->find($trackingId);
        if ($tracking === null || (int) $tracking['student_user_id'] !== $studentUserId) {
            throw new \InvalidArgumentException('Caso no encontrado.');
        }

        $product = [
            'type' => $tracking['product_type'] ?? '',
            'config_json' => $tracking['config_json'] ?? null,
        ];
        $required = CheckoutRequirements::registrationDocsForProduct($product);
        $meta = null;
        foreach ($required as $row) {
            if ($row['code'] === $docType) {
                $meta = $row;
                break;
            }
        }
        if ($meta === null) {
            throw new \InvalidArgumentException('Este caso no pide el documento: ' . $docType);
        }

        $stored = $this->documents->storeUploaded(
            $file,
            'docs/' . (int) ($tracking['purchase_id'] ?? 0),
            $meta['accept']
        );

        $existing = null;
        foreach ($this->documentsForTracking($trackingId) as $doc) {
            if ((string) $doc['doc_type'] === $docType) {
                $existing = $doc;
                break;
            }
        }

        if ($existing !== null) {
            $status = (string) $existing['status'];
            if ($status === 'approved') {
                throw new \InvalidArgumentException('Ese documento ya fue aprobado. Contacta a administración si necesitas cambiarlo.');
            }
            $this->pdo->prepare(
                'UPDATE documents
                 SET storage_path = ?, original_name = ?, status = \'pending\',
                     rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL, uploaded_by = ?
                 WHERE id = ?'
            )->execute([
                $stored['path'],
                $stored['original_name'],
                $studentUserId,
                (int) $existing['id'],
            ]);
            $docId = (int) $existing['id'];
            $this->log($trackingId, 'doc_uploaded', 'Actualizó: ' . $meta['label'], $studentUserId);
        } else {
            $this->pdo->prepare(
                'INSERT INTO documents (tracking_id, purchase_id, student_user_id, doc_type, original_name, storage_path, status, uploaded_by)
                 VALUES (?,?,?,?,?,?,\'pending\',?)'
            )->execute([
                $trackingId,
                (int) $tracking['purchase_id'],
                $studentUserId,
                $docType,
                $stored['original_name'],
                $stored['path'],
                $studentUserId,
            ]);
            $docId = (int) $this->pdo->lastInsertId();
            $this->log($trackingId, 'doc_uploaded', 'Subió: ' . $meta['label'], $studentUserId);
        }

        if ($docType === 'signature') {
            $this->pdo->prepare(
                'UPDATE students SET signature_image_path = ? WHERE user_id = ?'
            )->execute([$stored['path'], $studentUserId]);
        }

        $this->pdo->prepare(
            'UPDATE trackings SET status = \'waiting_admin\' WHERE id = ?'
        )->execute([$trackingId]);

        // Si el pipeline está en docs y ya se subió lo requerido, deja el caso en revisión
        $current = (string) ($tracking['current_step_code'] ?? '');
        if ($current === '' || $current === 'docs' || $current === 'registro') {
            try {
                $this->setStep($trackingId, 'docs', $studentUserId, 'Documentos de registro en revisión', 'waiting_admin');
            } catch (\Throwable) {
                // Pipelines sin paso docs
            }
        }

        return $docId;
    }

    public function approveDocument(int $docId, int $adminUserId): void
    {
        $doc = $this->findDocument($docId);
        if ($doc === null) {
            throw new \InvalidArgumentException('Documento no encontrado.');
        }
        $this->pdo->prepare(
            'UPDATE documents SET status = \'approved\', rejection_reason = NULL, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?'
        )->execute([$adminUserId, $docId]);

        if (!empty($doc['tracking_id'])) {
            $this->log(
                (int) $doc['tracking_id'],
                'doc_approved',
                'Documento aprobado: ' . $doc['doc_type'],
                $adminUserId
            );
        }
    }

    public function rejectDocument(int $docId, int $adminUserId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Indica el motivo del rechazo.');
        }
        $doc = $this->findDocument($docId);
        if ($doc === null) {
            throw new \InvalidArgumentException('Documento no encontrado.');
        }
        $this->pdo->prepare(
            'UPDATE documents SET status = \'rejected\', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?'
        )->execute([$reason, $adminUserId, $docId]);

        if (!empty($doc['tracking_id'])) {
            $trackingId = (int) $doc['tracking_id'];
            $this->pdo->prepare(
                'UPDATE trackings SET status = \'waiting_student\' WHERE id = ?'
            )->execute([$trackingId]);
            $this->log($trackingId, 'doc_rejected', 'Rechazado ' . $doc['doc_type'] . ': ' . $reason, $adminUserId);
            $this->notifyStudentDocRejected($trackingId, (string) $doc['doc_type'], $reason);
        }
    }

    /**
     * Alumno vuelve a subir un documento rechazado.
     *
     * @param array{tmp_name:string,name:string,error:int,size:int} $file
     */
    public function reuploadDocument(int $docId, int $studentUserId, array $file, string $accept = '.pdf,.jpg,.jpeg,.png'): void
    {
        $doc = $this->findDocument($docId);
        if ($doc === null || (int) $doc['student_user_id'] !== $studentUserId) {
            throw new \InvalidArgumentException('Documento no encontrado.');
        }
        if ((string) $doc['status'] !== 'rejected') {
            throw new \InvalidArgumentException('Solo puedes reemplazar documentos rechazados.');
        }

        if ((string) $doc['doc_type'] === 'ine') {
            $accept = '.pdf';
        }

        $stored = $this->documents->storeUploaded(
            $file,
            'docs/' . (int) ($doc['purchase_id'] ?? 0),
            $accept
        );

        $this->pdo->prepare(
            'UPDATE documents
             SET storage_path = ?, original_name = ?, status = \'pending\',
                 rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL, uploaded_by = ?
             WHERE id = ?'
        )->execute([
            $stored['path'],
            $stored['original_name'],
            $studentUserId,
            $docId,
        ]);

        if (!empty($doc['tracking_id'])) {
            $trackingId = (int) $doc['tracking_id'];
            $this->pdo->prepare(
                'UPDATE trackings SET status = \'waiting_admin\' WHERE id = ?'
            )->execute([$trackingId]);
            $this->log($trackingId, 'doc_reuploaded', 'Nueva versión: ' . $doc['doc_type'], $studentUserId);
        }
    }

    /** Tras confirmar pago de la compra: mueve cada tracking al paso operativo. */
    public function onPaymentConfirmed(int $purchaseId, int $adminUserId, ?string $notes = null): void
    {
        $stmt = $this->pdo->prepare('SELECT id, pipeline_template_id, product_id FROM trackings WHERE purchase_id = ?');
        $stmt->execute([$purchaseId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $trackingId = (int) $row['id'];
            $this->log(
                $trackingId,
                'confirm_pago',
                $notes ?? 'Pago confirmado por administración',
                $adminUserId
            );

            $productType = $this->productType((int) $row['product_id']);
            $target = match ($productType) {
                'course' => 'alta_moodle',
                'procedure' => $this->hasPendingDocs($trackingId) ? 'docs' : 'revision',
                default => 'asignacion',
            };

            try {
                $this->setStep(
                    $trackingId,
                    $target,
                    $adminUserId,
                    'Tras pago confirmado → ' . $target,
                    'waiting_admin'
                );
            } catch (\Throwable $e) {
                // Si el pipeline no tiene ese código, avanza un paso desde el actual
                error_log('[Doceo] onPaymentConfirmed step: ' . $e->getMessage());
                try {
                    $this->advance($trackingId, $adminUserId, 'Avance automático tras pago');
                } catch (\Throwable $e2) {
                    error_log('[Doceo] onPaymentConfirmed advance: ' . $e2->getMessage());
                }
            }

            if ($productType === 'course') {
                try {
                    $result = (new MoodleEnrolmentService())->syncTracking($trackingId, $adminUserId, true);
                    if (!empty($result['skipped'])) {
                        $this->log(
                            $trackingId,
                            'alta_moodle',
                            'Moodle omitido: ' . ($result['reason'] ?? 'n/a'),
                            $adminUserId
                        );
                    } elseif (empty($result['ok'])) {
                        $this->log(
                            $trackingId,
                            'alta_moodle',
                            'Moodle falló: ' . ($result['reason'] ?? 'error'),
                            $adminUserId
                        );
                    }
                } catch (\Throwable $e) {
                    error_log('[Doceo] Moodle enrol on payment: ' . $e->getMessage());
                    $this->log(
                        $trackingId,
                        'alta_moodle',
                        'Error Moodle: ' . $e->getMessage(),
                        $adminUserId
                    );
                }
            }
        }
    }

    public function absoluteDocumentPath(array $doc): string
    {
        return $this->documents->absolutePath((string) $doc['storage_path']);
    }

    /**
     * @param array{
     *   exam_date?:?string,
     *   exam_time?:?string,
     *   exam_date_2?:?string,
     *   exam_time_2?:?string,
     *   zoom_url?:?string,
     *   notify?:bool
     * } $data
     */
    public function saveExamSchedule(int $trackingId, array $data, ?int $actorUserId = null): void
    {
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        $examDate = array_key_exists('exam_date', $data)
            ? $this->normalizeDate($data['exam_date'])
            : $this->normalizeDate($tracking['exam_date'] ?? null);
        $examTime = array_key_exists('exam_time', $data)
            ? $this->normalizeTime($data['exam_time'])
            : $this->normalizeTime($tracking['exam_time'] ?? null);
        // 2ª fecha/hora = reagenda (solo si viene en el payload; si no, se conserva)
        $examDate2 = array_key_exists('exam_date_2', $data)
            ? $this->normalizeDate($data['exam_date_2'])
            : $this->normalizeDate($tracking['exam_date_2'] ?? null);
        $examTime2 = array_key_exists('exam_time_2', $data)
            ? $this->normalizeTime($data['exam_time_2'])
            : $this->normalizeTime($tracking['exam_time_2'] ?? null);
        // Zoom = acceso que carga admin; no borrar si el partner solo actualiza fecha
        if (array_key_exists('zoom_url', $data)) {
            $zoom = trim((string) ($data['zoom_url'] ?? ''));
            if ($zoom === '') {
                $zoom = null;
            } elseif (!filter_var($zoom, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('La URL de Zoom/meet no es válida.');
            }
        } else {
            $zoom = isset($tracking['zoom_url']) && $tracking['zoom_url'] !== ''
                ? (string) $tracking['zoom_url']
                : null;
        }

        if ($examDate === null) {
            throw new \InvalidArgumentException('La fecha de examen es obligatoria.');
        }

        $this->pdo->prepare(
            'UPDATE trackings
             SET exam_date = ?, exam_time = ?, exam_date_2 = ?, exam_time_2 = ?, zoom_url = ?
             WHERE id = ?'
        )->execute([$examDate, $examTime, $examDate2, $examTime2, $zoom, $trackingId]);

        $note = 'Examen: ' . $examDate . ($examTime ? ' ' . substr($examTime, 0, 5) : '');
        if ($examDate2) {
            $note .= ' · reagenda: ' . $examDate2 . ($examTime2 ? ' ' . substr($examTime2, 0, 5) : '');
        }
        if ($zoom && array_key_exists('zoom_url', $data)) {
            $note .= ' · enlace Zoom asignado';
        }
        $this->log($trackingId, 'examen', $note, $actorUserId);

        // Si el pipeline tiene paso examen y aún no está ahí ni más adelante, muévelo
        $current = (string) ($tracking['current_step_code'] ?? '');
        if ($current !== 'examen' && $current !== 'resultados' && $current !== 'fin') {
            try {
                $this->setStep($trackingId, 'examen', $actorUserId, 'Fecha de examen asignada', 'waiting_student');
            } catch (\Throwable) {
                // Pipelines sin paso examen (cursos): solo guarda fechas
            }
        }

        if (!empty($data['notify'])) {
            $fresh = $this->find($trackingId);
            if ($fresh) {
                $this->notifyExamScheduled($fresh);
            }
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $dt ? $dt->format('Y-m-d') : null;
    }

    private function normalizeTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        // HTML time puede venir HH:MM
        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }

    /** @param array<string, mixed> $tracking */
    private function notifyExamScheduled(array $tracking): void
    {
        try {
            $name = trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''));
            $when = (string) $tracking['exam_date'];
            if (!empty($tracking['exam_time'])) {
                $when .= ' ' . substr((string) $tracking['exam_time'], 0, 5);
            }
            $url = rtrim((string) (\App\Config\Env::get('APP_URL', '') ?? ''), '/')
                . '/alumno/caso/' . (int) $tracking['id'];
            $zoom = !empty($tracking['zoom_url'])
                ? "\nEnlace: " . $tracking['zoom_url'] . "\n"
                : "\n";
            $text = "Hola {$name},\n\nTu examen de {$tracking['product_name']} quedó programado.\n"
                . "Fecha: {$when}\n"
                . $zoom
                . "Detalle: {$url}\n\n— Instituto DOCEO\n";
            $html = '<p>Hola ' . htmlspecialchars($name) . ',</p>'
                . '<p>Tu examen de <strong>' . htmlspecialchars((string) $tracking['product_name']) . '</strong> quedó programado.</p>'
                . '<p><strong>Fecha:</strong> ' . htmlspecialchars($when) . '</p>'
                . (!empty($tracking['zoom_url'])
                    ? '<p><a href="' . htmlspecialchars((string) $tracking['zoom_url']) . '">Enlace de sesión</a></p>'
                    : '')
                . '<p><a href="' . htmlspecialchars($url) . '">Ver tu caso</a></p>'
                . '<p>— Instituto DOCEO</p>';
            (new \App\Integrations\Mailer())->send(
                (string) $tracking['student_email'],
                'Examen programado — caso ' . $tracking['matricula'],
                $text,
                ['html' => true, 'body_html' => $html]
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] Exam email: ' . $e->getMessage());
        }
    }

    private function productType(int $productId): string
    {
        $stmt = $this->pdo->prepare('SELECT type FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $t = $stmt->fetchColumn();

        return $t ? (string) $t : 'certification';
    }

    private function hasPendingDocs(int $trackingId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM documents WHERE tracking_id = ? AND status IN ('pending','rejected')"
        );
        $stmt->execute([$trackingId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function statusForActor(string $actor, bool $terminal): string
    {
        if ($terminal) {
            return 'completed';
        }

        return match ($actor) {
            'student' => 'waiting_student',
            'partner' => 'waiting_partner',
            'provider' => 'waiting_provider',
            'system' => 'open',
            default => 'waiting_admin',
        };
    }

    private function log(int $trackingId, string $stepCode, ?string $note, ?int $actorUserId): void
    {
        $this->pdo->prepare(
            'INSERT INTO tracking_step_logs (tracking_id, step_code, note, actor_user_id) VALUES (?,?,?,?)'
        )->execute([$trackingId, $stepCode, $note, $actorUserId]);
    }

    private function notifyStudentDocRejected(int $trackingId, string $docType, string $reason): void
    {
        try {
            $t = $this->find($trackingId);
            if ($t === null) {
                return;
            }
            $name = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name_p'] ?? ''));
            $url = rtrim((string) (\App\Config\Env::get('APP_URL', '') ?? ''), '/') . '/alumno/caso/' . $trackingId;
            $text = "Hola {$name},\n\nRechazamos el documento \"{$docType}\" de tu caso {$t['matricula']}.\n"
                . "Motivo: {$reason}\n\nSube una nueva versión aquí: {$url}\n\n— Instituto DOCEO\n";
            $html = '<p>Hola ' . htmlspecialchars($name) . ',</p>'
                . '<p>Rechazamos el documento <strong>' . htmlspecialchars($docType) . '</strong> '
                . 'de tu caso ' . htmlspecialchars((string) $t['matricula']) . '.</p>'
                . '<p><strong>Motivo:</strong> ' . htmlspecialchars($reason) . '</p>'
                . '<p><a href="' . htmlspecialchars($url) . '">Subir nueva versión</a></p>'
                . '<p>— Instituto DOCEO</p>';
            (new Mailer())->send(
                (string) $t['student_email'],
                'Documento rechazado — caso ' . $t['matricula'],
                $text,
                ['html' => true, 'body_html' => $html]
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] doc reject email: ' . $e->getMessage());
        }
    }
}

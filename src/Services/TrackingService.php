<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
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
     * @param array<string, mixed> $product
     * @param list<array{code:string,label:string,required:bool,accept:string}> $requiredDocs
     */
    public static function initialStepCode(array $product, string $productType, array $requiredDocs): string
    {
        return CheckoutRequirements::initialStepCode($product, $productType, $requiredDocs);
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
                    pg.config_json AS group_config_json, pg.code AS product_group_code,
                    pu.matricula, pu.status AS purchase_status, pu.charged_amount, pu.payment_method,
                    pu.payment_proof_path, pu.student_user_id AS purchase_student_id,
                    u.first_name, u.last_name_p, u.last_name_m, u.email AS student_email, u.phone AS student_phone,
                    st.curp, st.birth_date, st.sex, st.nationality, st.extra_fields_json,
                    st.address_street, st.address_city, st.address_state, st.address_zip,
                    pt.code AS pipeline_code, pt.name AS pipeline_name
             FROM trackings t
             JOIN products pr ON pr.id = t.product_id
             JOIN purchases pu ON pu.id = t.purchase_id
             JOIN users u ON u.id = t.student_user_id
             LEFT JOIN students st ON st.user_id = t.student_user_id
             LEFT JOIN product_groups pg ON pg.id = pr.product_group_id
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

        $previousStep = (string) ($tracking['current_step_code'] ?? '');

        $this->pdo->prepare(
            'UPDATE trackings SET current_step_code = ?, status = ? WHERE id = ?'
        )->execute([$stepCode, $status, $trackingId]);

        $this->log($trackingId, $stepCode, $note, $actorUserId);

        if ($previousStep !== $stepCode) {
            $fresh = $this->find($trackingId);
            if ($fresh !== null) {
                $vars = [
                    'name' => trim((string) (($fresh['first_name'] ?? '') . ' ' . ($fresh['last_name_p'] ?? ''))),
                    'matricula' => (string) ($fresh['matricula'] ?? ''),
                    'product_name' => (string) ($fresh['product_name'] ?? ''),
                    'step_code' => $stepCode,
                ];
                GroupEmailAutomation::sendAutoEmailsForStep($fresh, $stepCode, $vars);
            }
        }
    }

    /** Marca un paso como hecho en extra_json.step_done (botones de Operación). */
    public function markStepDone(int $trackingId, string $stepCode, ?int $actorUserId = null, ?string $note = null): void
    {
        $stepCode = trim($stepCode);
        if ($stepCode === '') {
            return;
        }
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }
        $extra = [];
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        }
        $done = is_array($extra['step_done'] ?? null) ? $extra['step_done'] : [];
        $done[$stepCode] = [
            'at' => date('c'),
            'by' => $actorUserId,
            'note' => $note,
        ];
        $extra['step_done'] = $done;
        $this->pdo->prepare('UPDATE trackings SET extra_json = ? WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);
        if ($note !== null && $note !== '') {
            $this->log($trackingId, $stepCode, $note, $actorUserId);
        }
    }

    /**
     * Actualiza nombre/teléfono del alumno del caso.
     *
     * @param array{first_name?:string,last_name_p?:string,last_name_m?:string,phone?:string,email?:string} $data
     */
    public function updateStudentProfile(int $trackingId, array $data, ?int $actorUserId = null): void
    {
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }
        $userId = (int) ($tracking['student_user_id'] ?? 0);
        if ($userId < 1) {
            throw new \InvalidArgumentException('El caso no tiene alumno asociado.');
        }

        $first = trim((string) ($data['first_name'] ?? ''));
        $lp = trim((string) ($data['last_name_p'] ?? ''));
        $lm = trim((string) ($data['last_name_m'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($first === '' || $lp === '') {
            throw new \InvalidArgumentException('Nombre y apellido paterno son obligatorios.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido.');
        }

        if ($email !== '') {
            $dup = $this->pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $dup->execute([$email, $userId]);
            if ($dup->fetch()) {
                throw new \InvalidArgumentException('Ese correo ya está en uso por otra cuenta.');
            }
            $this->pdo->prepare(
                'UPDATE users SET first_name = ?, last_name_p = ?, last_name_m = ?, phone = ?, email = ? WHERE id = ?'
            )->execute([$first, $lp, $lm !== '' ? $lm : null, $phone !== '' ? $phone : null, $email, $userId]);
        } else {
            $this->pdo->prepare(
                'UPDATE users SET first_name = ?, last_name_p = ?, last_name_m = ?, phone = ? WHERE id = ?'
            )->execute([$first, $lp, $lm !== '' ? $lm : null, $phone !== '' ? $phone : null, $userId]);
        }

        $this->log(
            $trackingId,
            'alumno',
            'Datos del alumno actualizados: ' . trim($first . ' ' . $lp . ' ' . $lm),
            $actorUserId
        );
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
            'group_config_json' => $tracking['group_config_json'] ?? null,
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

    /** @param array<string, mixed> $tracking */
    public function canStudentRequestReschedule(array $tracking): bool
    {
        if ((string) ($tracking['purchase_status'] ?? '') !== 'paid') {
            return false;
        }
        if (empty($tracking['exam_date']) || !empty($tracking['exam_date_2'])) {
            return false;
        }
        if (!empty($tracking['results_level']) || !empty($tracking['results_url']) || !empty($tracking['cenni_folio'])) {
            return false;
        }
        if (in_array((string) ($tracking['current_step_code'] ?? ''), ['resultados', 'fin'], true)) {
            return false;
        }

        $cfg = CheckoutRequirements::config([
            'type' => $tracking['product_type'] ?? '',
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
        ]);
        $exam = is_array($cfg['exam'] ?? null) ? $cfg['exam'] : [];

        return (bool) ($exam['allow_reschedule'] ?? false);
    }

    public function requestStudentReschedule(
        int $trackingId,
        int $studentUserId,
        string $examDate,
        string $examTime
    ): void {
        $tracking = $this->find($trackingId);
        if ($tracking === null || (int) $tracking['student_user_id'] !== $studentUserId) {
            throw new \InvalidArgumentException('Caso no encontrado.');
        }
        if (!$this->canStudentRequestReschedule($tracking)) {
            throw new \InvalidArgumentException('Este caso no permite solicitar reagenda desde el panel del alumno.');
        }

        $product = [
            'type' => $tracking['product_type'] ?? '',
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
        ];
        (new ExamScheduleService())->validateSlot($product, $examDate, $examTime);

        $date = $this->normalizeDate($examDate);
        $time = $this->normalizeTime($examTime);
        if ($date === null || $time === null) {
            throw new \InvalidArgumentException('Selecciona fecha y hora válidas para reagendar.');
        }

        $currentDate = $this->normalizeDate($tracking['exam_date'] ?? null);
        $currentTime = $this->normalizeTime($tracking['exam_time'] ?? null);
        if ($date === $currentDate && $time === $currentTime) {
            throw new \InvalidArgumentException('La nueva fecha debe ser distinta a la fecha actual.');
        }

        $this->pdo->prepare(
            'UPDATE trackings
             SET exam_date_2 = ?, exam_time_2 = ?, status = \'waiting_admin\'
             WHERE id = ?'
        )->execute([$date, $time, $trackingId]);

        $this->log(
            $trackingId,
            'reagenda',
            'Alumno solicitó reagenda: ' . $date . ' ' . substr($time, 0, 5),
            $studentUserId
        );
    }

    /** Tras confirmar pago de la compra: mueve cada tracking al paso operativo. */
    public function onPaymentConfirmed(int $purchaseId, int $adminUserId, ?string $notes = null): void
    {
        $stmt = $this->pdo->prepare('SELECT id, pipeline_template_id, product_id FROM trackings WHERE purchase_id = ?');
        $stmt->execute([$purchaseId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $trackingId = (int) $row['id'];
            $productId = (int) $row['product_id'];

            $this->log(
                $trackingId,
                'confirm_pago',
                $notes ?? 'Pago confirmado por administración',
                $adminUserId
            );

            $product = $this->pdo->prepare(
                'SELECT pr.*, pg.config_json AS group_config_json
                 FROM products pr
                 LEFT JOIN product_groups pg ON pg.id = pr.product_group_id
                 WHERE pr.id = ?
                 LIMIT 1'
            );
            $product->execute([$productId]);
            $productRow = $product->fetch() ?: null;
            $pipelineCode = $productRow
                ? CheckoutRequirements::pipelineCode($productRow)
                : null;

            if ($productRow && ProviderRequestService::configForProduct($productRow) !== null) {
                try {
                    (new ProviderRequestService())->onPaymentConfirmed($trackingId, $purchaseId, $adminUserId);
                } catch (\Throwable $e) {
                    error_log('[Doceo] Solicitud proveedor tras pago: ' . $e->getMessage());
                    $cfg = ProviderRequestService::configForProduct($productRow) ?? [];
                    $step = (string) ($cfg['step_code'] ?? 'solicitud_proveedor');
                    $this->setStep(
                        $trackingId,
                        $step,
                        $adminUserId,
                        'Pago confirmado (solicitud al proveedor falló: ' . $e->getMessage() . ')',
                        'waiting_admin'
                    );
                }
                continue;
            }

            if ($pipelineCode === 'elet_uks') {
                try {
                    (new UksEletService())->onPaymentConfirmed($trackingId, $purchaseId, $adminUserId);
                } catch (\Throwable $e) {
                    error_log('[Doceo] UKS solicitud tras pago: ' . $e->getMessage());
                    $this->setStep(
                        $trackingId,
                        'solicitud_uks',
                        $adminUserId,
                        'Pago confirmado (correo UKS falló: ' . $e->getMessage() . ')',
                        'waiting_provider'
                    );
                }
                continue;
            }

            $productType = $this->productType($productId);
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
                    $result = (new MoodleEnrolmentService())->syncTracking($trackingId, $adminUserId, false);
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

            // Inventario (iTEP, etc.): asignar folio/clave o programar envío N días antes.
            if ($productRow !== null && InventoryService::isEnabledForProduct($productRow)) {
                try {
                    $invResult = (new InventoryService())->processTracking($trackingId, $adminUserId);
                    $this->log(
                        $trackingId,
                        'codigos',
                        'Inventario tras pago: ' . ($invResult['action'] ?? 'ok')
                            . (!empty($invResult['detail']) ? (' · ' . $invResult['detail']) : ''),
                        $adminUserId
                    );
                } catch (\Throwable $e) {
                    error_log('[Doceo] Inventario tras pago: ' . $e->getMessage());
                    $this->log(
                        $trackingId,
                        'codigos',
                        'Inventario falló tras pago: ' . $e->getMessage(),
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
     *   access_fields?:array<string,mixed>,
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

        $accessFieldsIn = is_array($data['access_fields'] ?? null) ? $data['access_fields'] : null;
        $normalizedAccess = [];
        if ($accessFieldsIn !== null) {
            foreach ($accessFieldsIn as $code => $value) {
                $c = GroupExtraFields::normalizeCode((string) $code);
                if ($c === '') {
                    continue;
                }
                $normalizedAccess[$c] = AdminOpsBoardService::normalizeExtraValue(trim((string) $value));
            }
        }

        // Campo extra (Zoom / ID escuela / código…): no borrar si el partner solo actualiza fecha
        if (array_key_exists('zoom_url', $data)) {
            $zoom = trim((string) ($data['zoom_url'] ?? ''));
            if ($zoom === '') {
                $zoom = null;
            } else {
                $zoom = AdminOpsBoardService::normalizeExtraValue($zoom);
            }
        } elseif ($normalizedAccess !== []) {
            $first = reset($normalizedAccess);
            $zoom = $first !== false && $first !== '' ? (string) $first : null;
        } else {
            $zoom = isset($tracking['zoom_url']) && $tracking['zoom_url'] !== ''
                ? (string) $tracking['zoom_url']
                : null;
        }

        if ($examDate === null) {
            throw new \InvalidArgumentException('La fecha de examen es obligatoria.');
        }

        $prevDate = $this->normalizeDate($tracking['exam_date'] ?? null);
        $prevTime = $this->normalizeTime($tracking['exam_time'] ?? null);
        $scheduleChanged = $prevDate !== $examDate
            || substr((string) ($prevTime ?? ''), 0, 5) !== substr((string) ($examTime ?? ''), 0, 5);

        $this->pdo->prepare(
            'UPDATE trackings
             SET exam_date = ?, exam_time = ?, exam_date_2 = ?, exam_time_2 = ?, zoom_url = ?
             WHERE id = ?'
        )->execute([$examDate, $examTime, $examDate2, $examTime2, $zoom, $trackingId]);

        if ($accessFieldsIn !== null) {
            $extra = [];
            if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
                $decoded = json_decode($tracking['extra_json'], true);
                $extra = is_array($decoded) ? $decoded : [];
            } elseif (is_array($tracking['extra_json'] ?? null)) {
                $extra = $tracking['extra_json'];
            }
            $extra['access_fields'] = $normalizedAccess;
            $this->pdo->prepare('UPDATE trackings SET extra_json = ? WHERE id = ?')
                ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);
        }

        // Solo contar reagendas (cambio sobre una fecha ya existente), no la primera agenda.
        if ($scheduleChanged && $prevDate !== null) {
            $this->bumpExamRescheduleCount($trackingId, $tracking);
        }

        $note = 'Examen: ' . $examDate . ($examTime ? ' ' . substr($examTime, 0, 5) : '');
        if ($examDate2) {
            $note .= ' · reagenda: ' . $examDate2 . ($examTime2 ? ' ' . substr($examTime2, 0, 5) : '');
        }
        if (($zoom && array_key_exists('zoom_url', $data)) || $normalizedAccess !== []) {
            $note .= ' · dato(s) extra asignado(s)';
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

        if ($scheduleChanged) {
            $fresh = $this->find($trackingId);
            if ($fresh !== null) {
                $product = [
                    'id' => $fresh['product_id'] ?? 0,
                    'config_json' => $fresh['config_json'] ?? null,
                    'group_config_json' => $fresh['group_config_json'] ?? null,
                    'type' => $fresh['product_type'] ?? '',
                    'code' => $fresh['product_code'] ?? '',
                    'name' => $fresh['product_name'] ?? '',
                ];
                if (InventoryService::isEnabledForProduct($product)
                    && (string) ($fresh['purchase_status'] ?? '') === 'paid'
                ) {
                    try {
                        (new InventoryService())->processTracking($trackingId, $actorUserId);
                    } catch (\Throwable $e) {
                        error_log('[Doceo] Inventario tras cambio de fecha: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Cuántas veces se ha reagendado la fecha de examen (no cuenta la primera agenda).
     *
     * @param array<string, mixed> $tracking
     */
    public static function examRescheduleCountFromTracking(array $tracking): int
    {
        $extra = [];
        if (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        } elseif (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        }
        $count = (int) ($extra['exam_reschedule_count'] ?? 0);
        // Legacy: el contador incluía la primera agenda. v2 solo cuenta reagendas.
        if ($count > 0 && empty($extra['exam_reschedule_count_v2'])) {
            $count = max(0, $count - 1);
        }

        return max(0, $count);
    }

    /**
     * @param array<string, mixed> $tracking
     */
    private function bumpExamRescheduleCount(int $trackingId, array $tracking): int
    {
        $extra = [];
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        }
        $current = (int) ($extra['exam_reschedule_count'] ?? 0);
        if (empty($extra['exam_reschedule_count_v2'])) {
            // Convertir contador viejo (incluía 1ª agenda) a solo reagendas.
            $current = max(0, $current - 1);
            $extra['exam_reschedule_count_v2'] = true;
        }
        $next = $current + 1;
        $extra['exam_reschedule_count'] = $next;
        $extra['exam_reschedule_count_v2'] = true;
        $this->pdo->prepare('UPDATE trackings SET extra_json = ? WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);

        return $next;
    }

    /**
     * Marca que se descargó el CSV de un paso de Operación para este caso.
     */
    public function markCsvDownloaded(int $trackingId, string $stepCode, string $templateCode, ?int $actorUserId = null): void
    {
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            return;
        }
        $extra = [];
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        }
        $map = is_array($extra['csv_downloads'] ?? null) ? $extra['csv_downloads'] : [];
        $key = trim($stepCode) !== '' ? trim($stepCode) : ('tpl:' . trim($templateCode));
        if ($key === '' || $key === 'tpl:') {
            $key = 'default';
        }
        $map[$key] = [
            'at' => date('c'),
            'template' => trim($templateCode),
            'by' => $actorUserId,
        ];
        $extra['csv_downloads'] = $map;
        $this->pdo->prepare('UPDATE trackings SET extra_json = ? WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);
    }

    /**
     * Publica resultados / cancelación (campos dinámicos del grupo) y opcionalmente notifica.
     *
     * @param array{
     *   cancelled?:bool,
     *   cancel_reason?:string,
     *   results_value?:array<string,mixed>,
     *   results_file?:array<string,mixed>|null,
     *   results_level?:string,
     *   results_score?:string|float|int|null,
     *   results_url?:string,
     *   cenni_folio?:string,
     *   notify?:bool,
     *   results_step_code?:string
     * } $data
     */
    public function saveResults(int $trackingId, array $data, ?int $actorUserId = null): void
    {
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        $product = [
            'id' => $tracking['product_id'] ?? 0,
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
            'type' => $tracking['product_type'] ?? '',
            'code' => $tracking['product_code'] ?? '',
            'name' => $tracking['product_name'] ?? '',
        ];
        $cfg = CheckoutRequirements::config($product);
        $delivery = ResultsDeliveryService::fromConfig($cfg);
        $prevState = ResultsDeliveryService::stateFromTracking($tracking);
        $inventoryOn = InventoryService::isEnabledForProduct($product);
        $fields = is_array($delivery['fields'] ?? null) ? $delivery['fields'] : [];

        $cancelled = !empty($data['cancelled']);
        $cancelReason = trim((string) ($data['cancel_reason'] ?? ''));

        $postedValues = is_array($data['results_value'] ?? null) ? $data['results_value'] : [];
        $postedFiles = self::normalizeResultsFiles($data['results_file'] ?? null);
        $values = is_array($prevState['values'] ?? null) ? $prevState['values'] : [];

        $level = trim((string) ($tracking['results_level'] ?? ''));
        $score = $tracking['results_score'] ?? null;
        if ($score !== null && $score !== '') {
            $score = is_numeric($score) ? (float) $score : null;
        } else {
            $score = null;
        }
        $url = trim((string) ($tracking['results_url'] ?? ''));
        $cenni = trim((string) ($data['cenni_folio'] ?? $tracking['cenni_folio'] ?? ''));

        // Fallback inventario / POST directo a columnas tipadas.
        if (array_key_exists('results_level', $data)) {
            $level = trim((string) $data['results_level']);
        }
        if (array_key_exists('results_score', $data) && $data['results_score'] !== '') {
            if (!is_numeric($data['results_score'])) {
                throw new \InvalidArgumentException('El puntaje debe ser numérico.');
            }
            $score = (float) $data['results_score'];
        } elseif (array_key_exists('results_score', $data) && $data['results_score'] === '') {
            $score = null;
        }
        if (array_key_exists('results_url', $data)) {
            $url = trim((string) $data['results_url']);
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('La URL de resultados no es válida.');
            }
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $code = (string) ($field['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $type = (string) ($field['type'] ?? ResultsDeliveryService::TYPE_TEXT);
            $placeholder = (string) ($field['placeholder'] ?? $code);

            if ($type === ResultsDeliveryService::TYPE_PDF) {
                $file = is_array($postedFiles[$code] ?? null) ? $postedFiles[$code] : null;
                if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $stored = $this->documents->storeUploaded(
                        $file,
                        'results/' . $trackingId,
                        '.pdf'
                    );
                    $values[$code] = [
                        'path' => (string) ($stored['path'] ?? ''),
                        'name' => (string) ($stored['original_name'] ?? 'resultados.pdf'),
                    ];
                }
                continue;
            }

            if (!array_key_exists($code, $postedValues)) {
                continue;
            }
            $raw = trim((string) $postedValues[$code]);
            if ($type === ResultsDeliveryService::TYPE_URL && $raw !== ''
                && !filter_var($raw, FILTER_VALIDATE_URL)
            ) {
                throw new \InvalidArgumentException(
                    'La URL de «' . (string) ($field['label'] ?? $code) . '» no es válida.'
                );
            }
            $values[$code] = $raw;

            // Sync columnas tipadas cuando code/placeholder coincide.
            foreach ([$code, $placeholder] as $key) {
                if ($key === 'results_url') {
                    $url = $raw;
                    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                        throw new \InvalidArgumentException('La URL de resultados no es válida.');
                    }
                } elseif ($key === 'results_level') {
                    $level = $raw;
                } elseif ($key === 'results_score') {
                    if ($raw === '') {
                        $score = null;
                    } elseif (!is_numeric($raw)) {
                        throw new \InvalidArgumentException('El puntaje debe ser numérico.');
                    } else {
                        $score = (float) $raw;
                    }
                }
            }
        }

        if ($cancelled) {
            if ($cancelReason === '') {
                throw new \InvalidArgumentException('Indica el motivo de cancelación del examen.');
            }
        } elseif ($delivery['enabled']) {
            $probe = $tracking;
            $probe['results_url'] = $url !== '' ? $url : null;
            $probe['results_level'] = $level !== '' ? $level : null;
            $probe['results_score'] = $score;
            $probeExtra = [];
            if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
                $decoded = json_decode($tracking['extra_json'], true);
                $probeExtra = is_array($decoded) ? $decoded : [];
            } elseif (is_array($tracking['extra_json'] ?? null)) {
                $probeExtra = $tracking['extra_json'];
            }
            $probeExtra['results_delivery'] = [
                'values' => $values,
                'cancelled' => false,
                'cancel_reason' => '',
            ];
            $probe['extra_json'] = $probeExtra;
            if (!ResultsDeliveryService::isReady($probe, $delivery)) {
                throw new \InvalidArgumentException(ResultsDeliveryService::blockedReason($probe, $delivery));
            }
        } elseif ($inventoryOn) {
            if ($level === '' && $score === null && $url === '' && $cenni === '') {
                throw new \InvalidArgumentException('Indica al menos un dato de resultados o folio CENNI.');
            }
        } else {
            if ($level === '' && $score === null && $url === '' && $cenni === '' && $values === []) {
                throw new \InvalidArgumentException('Indica al menos un dato de resultados.');
            }
        }

        $this->pdo->prepare(
            'UPDATE trackings
             SET results_level = ?, results_score = ?, results_url = ?, cenni_folio = ?
             WHERE id = ?'
        )->execute([
            $level !== '' ? $level : null,
            $score,
            $url !== '' ? $url : null,
            $cenni !== '' ? $cenni : null,
            $trackingId,
        ]);

        $extra = [];
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        }
        $bag = is_array($extra['results_delivery'] ?? null) ? $extra['results_delivery'] : [];
        $bag['values'] = $values;
        if ($cancelled) {
            $bag['cancelled'] = true;
            $bag['cancel_reason'] = $cancelReason;
        } else {
            $bag['cancelled'] = false;
            $bag['cancel_reason'] = '';
        }
        $bag['updated_at'] = date('c');
        $extra['results_delivery'] = $bag;
        $this->pdo->prepare('UPDATE trackings SET extra_json = ? WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);

        $parts = [];
        if ($cancelled) {
            $parts[] = 'cancelación: ' . $cancelReason;
        } else {
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $code = (string) ($field['code'] ?? '');
                if ($code === '' || !isset($values[$code])) {
                    continue;
                }
                $label = (string) ($field['label'] ?? $code);
                if (($field['type'] ?? '') === ResultsDeliveryService::TYPE_PDF) {
                    $raw = $values[$code];
                    if (is_array($raw) && trim((string) ($raw['path'] ?? '')) !== '') {
                        $parts[] = $label . ' (pdf)';
                    }
                } elseif (trim((string) $values[$code]) !== '') {
                    $parts[] = $label;
                }
            }
            if ($level !== '') {
                $parts[] = 'nivel ' . $level;
            }
            if ($score !== null) {
                $parts[] = 'puntaje ' . $score;
            }
            if ($url !== '') {
                $parts[] = 'url';
            }
            if ($cenni !== '') {
                $parts[] = 'CENNI ' . $cenni;
            }
            $parts = array_values(array_unique($parts));
        }
        $this->log(
            $trackingId,
            'resultados',
            ($cancelled ? 'Examen cancelado: ' : 'Resultados publicados: ') . implode(' · ', $parts),
            $actorUserId
        );

        try {
            $this->setStep(
                $trackingId,
                'resultados',
                $actorUserId,
                $cancelled ? 'Cancelación registrada' : 'Resultados cargados',
                'waiting_student'
            );
        } catch (\Throwable) {
            // Pipelines sin paso resultados.
        }

        $notify = array_key_exists('notify', $data) ? !empty($data['notify']) : true;
        if (!$notify) {
            return;
        }

        $fresh = $this->find($trackingId);
        if ($fresh === null) {
            return;
        }

        $resultsStep = trim((string) ($data['results_step_code'] ?? ''));
        if ($resultsStep === '') {
            $defs = GroupStepConfig::defsFromConfig(CheckoutRequirements::config([
                'config_json' => $fresh['config_json'] ?? null,
                'group_config_json' => $fresh['group_config_json'] ?? null,
            ]));
            foreach ($defs as $code => $def) {
                if ((string) ($def['action'] ?? '') !== GroupStepConfig::ACTION_SEND_MAIL) {
                    continue;
                }
                $tpl = trim((string) (($def['email']['template_code'] ?? '')));
                if (!empty($def['requires_results'])
                    || ResultsDeliveryService::stepRequiresResults($def, $delivery, $tpl)
                ) {
                    $resultsStep = (string) $code;
                    break;
                }
            }
        }

        if ($delivery['enabled']) {
            if ($resultsStep === '') {
                throw new \InvalidArgumentException(
                    'Configura un paso «Enviar correo» que requiera datos de resultados del grupo.'
                );
            }
            (new StepMailService())->sendForStep($trackingId, $resultsStep, $actorUserId);

            return;
        }

        if ($cancelled) {
            // Cancelación solo aplica con entrega de resultados del grupo (plantilla de cancelación).
            return;
        }

        $productFresh = [
            'id' => $fresh['product_id'] ?? 0,
            'config_json' => $fresh['config_json'] ?? null,
            'group_config_json' => $fresh['group_config_json'] ?? null,
            'type' => $fresh['product_type'] ?? '',
            'code' => $fresh['product_code'] ?? '',
            'name' => $fresh['product_name'] ?? '',
        ];
        if (InventoryService::isEnabledForProduct($productFresh)) {
            (new InventoryService())->sendResultsMail($trackingId, $actorUserId);
        }
    }

    /**
     * Normaliza $_FILES['results_file'] (por código de campo) a archivos individuales.
     *
     * @param mixed $raw
     * @return array<string, array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string}>
     */
    private static function normalizeResultsFiles(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        // Ya es mapa code => file meta
        if (isset($raw['tmp_name']) && is_string($raw['tmp_name'])) {
            return ['results_pdf' => $raw];
        }
        if (isset($raw['tmp_name']) && is_array($raw['tmp_name'])) {
            $out = [];
            foreach ($raw['tmp_name'] as $code => $tmp) {
                $code = (string) $code;
                $out[$code] = [
                    'tmp_name' => (string) $tmp,
                    'name' => (string) ($raw['name'][$code] ?? ''),
                    'type' => (string) ($raw['type'][$code] ?? ''),
                    'error' => (int) ($raw['error'][$code] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($raw['size'][$code] ?? 0),
                ];
            }

            return $out;
        }
        $out = [];
        foreach ($raw as $code => $file) {
            if (is_array($file) && isset($file['tmp_name'])) {
                $out[(string) $code] = $file;
            }
        }

        return $out;
    }

    /**
     * Metadatos de agenda (TOEFL extraordinaria, Cambridge sesión, etc.).
     *
     * @param array<string, mixed> $meta
     */
    public function mergeExamScheduleMeta(int $trackingId, array $meta): void
    {
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }
        $extra = [];
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        }
        $current = is_array($extra['exam_schedule'] ?? null) ? $extra['exam_schedule'] : [];
        $extra['exam_schedule'] = array_merge($current, $meta);
        $this->pdo->prepare('UPDATE trackings SET extra_json = ? WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);
    }

    /**
     * Admin confirma o ajusta una fecha extraordinaria / anticipada.
     *
     * @param array{exam_date?:string,exam_time?:string,note?:string} $data
     */
    public function authorizeExamSchedule(int $trackingId, int $adminUserId, array $data = []): void
    {
        $tracking = $this->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }
        if (array_key_exists('exam_date', $data) || array_key_exists('exam_time', $data)) {
            $this->saveExamSchedule($trackingId, [
                'exam_date' => $data['exam_date'] ?? ($tracking['exam_date'] ?? null),
                'exam_time' => $data['exam_time'] ?? ($tracking['exam_time'] ?? null),
            ], $adminUserId);
        }
        $this->mergeExamScheduleMeta($trackingId, [
            'status' => 'confirmed',
            'requires_admin' => false,
            'authorized_by' => $adminUserId,
            'authorized_at' => date('c'),
            'admin_note' => trim((string) ($data['note'] ?? '')),
        ]);
        $this->pdo->prepare('UPDATE trackings SET status = ? WHERE id = ?')
            ->execute(['waiting_student', $trackingId]);
        $this->log(
            $trackingId,
            'examen',
            'Fecha de examen autorizada por admin'
                . (!empty($data['note']) ? (': ' . trim((string) $data['note'])) : ''),
            $adminUserId
        );
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

    public function addLog(int $trackingId, string $stepCode, ?string $note, ?int $actorUserId = null): void
    {
        $this->log($trackingId, $stepCode, $note, $actorUserId);
    }

    private function log(int $trackingId, string $stepCode, ?string $note, ?int $actorUserId): void
    {
        $this->pdo->prepare(
            'INSERT INTO tracking_step_logs (tracking_id, step_code, note, actor_user_id) VALUES (?,?,?,?)'
        )->execute([$trackingId, $stepCode, $note, $actorUserId]);
    }
}

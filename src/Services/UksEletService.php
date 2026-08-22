<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Connection;
use App\Integrations\Mailer;
use App\Repositories\PurchaseRepository;
use App\Repositories\SupplierRepository;
use App\Support\Settings;
use PDO;

/**
 * Flujo operativo ELET ↔ UKS tras confirmar pago del alumno.
 */
final class UksEletService
{
    private PDO $pdo;
    private DocumentService $documents;
    private TrackingService $tracking;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->documents = new DocumentService();
        $this->tracking = new TrackingService();
    }

    /** @param array<string, mixed> $tracking */
    public function isEletUksTracking(array $tracking): bool
    {
        if (($tracking['pipeline_code'] ?? '') === 'elet_uks') {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT code FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([(int) ($tracking['product_id'] ?? 0)]);

        return (string) $stmt->fetchColumn() === 'ELET-UKS';
    }

    public function examUrl(): string
    {
        $url = Settings::get('elet_exam_url', Env::get('ELET_EXAM_URL', 'https://exam.elet.com.mx/')) ?? 'https://exam.elet.com.mx/';

        return rtrim(trim($url), '/') . '/';
    }

    /**
     * Tras confirmar pago: correo a UKS (nombre, fecha/hora, reglamento firmado) y paso solicitud_uks.
     */
    public function onPaymentConfirmed(int $trackingId, int $purchaseId, int $adminUserId): void
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null || !$this->isEletUksTracking($tracking)) {
            return;
        }

        $this->tracking->setStep(
            $trackingId,
            'solicitud_uks',
            $adminUserId,
            'Pago confirmado · solicitud enviada a UKS',
            'waiting_provider'
        );

        $this->sendSolicitudEmail($trackingId, $purchaseId, false);
    }

    public function sendSolicitudEmail(int $trackingId, int $purchaseId, bool $includePaymentProof = false): void
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        $purchase = (new PurchaseRepository())->find($purchaseId);
        if ($purchase === null) {
            throw new \InvalidArgumentException('Compra no encontrada.');
        }

        $to = $this->uksRequestEmail();
        if ($to === '') {
            throw new \RuntimeException(
                'Configura el correo de destino en Admin → Correos (UKS ELeT) o en el proveedor UKS.'
            );
        }

        $fullName = trim(implode(' ', array_filter([
            $tracking['first_name'] ?? '',
            $tracking['last_name_p'] ?? '',
            $tracking['last_name_m'] ?? '',
        ])));

        $examDate = (string) ($tracking['exam_date'] ?? '');
        $examTime = !empty($tracking['exam_time']) ? substr((string) $tracking['exam_time'], 0, 5) : '';
        $matricula = (string) ($tracking['matricula'] ?? $purchase['matricula']);
        $certificacion = trim((string) ($tracking['product_name'] ?? 'Certificación UKS'));

        $reglamentoDoc = $this->findSignedReglamentoDocument($trackingId, $purchaseId);
        if ($reglamentoDoc === null) {
            throw new \RuntimeException(
                'No se encontró el reglamento firmado del alumno. No se puede enviar la solicitud a UKS.'
            );
        }

        $reglamentoPath = $this->documents->absolutePath((string) $reglamentoDoc['storage_path']);
        if (!is_file($reglamentoPath)) {
            throw new \RuntimeException('El archivo del reglamento firmado no está disponible en el servidor.');
        }

        $fileLinks = new SignedFileLinkService();
        $reglamentoUrl = $fileLinks->documentLink((int) $reglamentoDoc['id']);
        $comprobanteUrl = '';
        if ($includePaymentProof) {
            $proofPath = (string) ($purchase['payment_proof_path'] ?? '');
            if ($proofPath !== '') {
                $abs = $this->documents->absolutePath($proofPath);
                if (is_file($abs)) {
                    $comprobanteUrl = $fileLinks->purchaseProofLink($purchaseId);
                }
            }
        }

        $documentosHtml = $this->uksDocumentosHtml($reglamentoUrl, $comprobanteUrl);
        $attachmentNote = 'Documentos disponibles por enlace (vigencia ~90 días).';

        $mailTpl = new MailTemplateService();
        $vars = [
            'certificacion' => $certificacion,
            'product_name' => $certificacion,
            'full_name' => $fullName,
            'matricula' => $matricula,
            'student_email' => (string) ($tracking['student_email'] ?? ''),
            'exam_date' => $examDate,
            'exam_time' => $examTime,
            'reglamento_url' => $reglamentoUrl,
            'comprobante_url' => $comprobanteUrl,
            'documentos_html' => $documentosHtml,
            'attachment_note' => $attachmentNote,
        ];

        if ($mailTpl->renderUksSolicitud($vars) !== null) {
            $mailTpl->sendUksSolicitud($to, $vars);
        } else {
            $subject = 'Solicitud ' . $certificacion . ' · ' . $fullName . ' · ' . $matricula;
            $text = "Solicitud de registro examen {$certificacion} — Instituto DOCEO\n\n"
                . "Certificación: {$certificacion}\n"
                . "Alumno: {$fullName}\n"
                . "Matrícula DOCEO: {$matricula}\n"
                . "Correo alumno: " . ($tracking['student_email'] ?? '') . "\n"
                . "Fecha examen: {$examDate}\n"
                . "Hora examen: {$examTime}\n\n"
                . "Reglamento firmado: {$reglamentoUrl}\n"
                . ($comprobanteUrl !== '' ? "Comprobante: {$comprobanteUrl}\n" : '')
                . "\n— Instituto DOCEO\n";
            $html = '<p>Solicitud de registro examen <strong>' . htmlspecialchars($certificacion) . '</strong> — Instituto DOCEO</p>'
                . '<ul>'
                . '<li><strong>Certificación:</strong> ' . htmlspecialchars($certificacion) . '</li>'
                . '<li><strong>Alumno:</strong> ' . htmlspecialchars($fullName) . '</li>'
                . '<li><strong>Matrícula DOCEO:</strong> ' . htmlspecialchars($matricula) . '</li>'
                . '<li><strong>Correo:</strong> ' . htmlspecialchars((string) ($tracking['student_email'] ?? '')) . '</li>'
                . '<li><strong>Fecha examen:</strong> ' . htmlspecialchars($examDate) . '</li>'
                . '<li><strong>Hora examen:</strong> ' . htmlspecialchars($examTime) . '</li>'
                . '</ul>'
                . $documentosHtml;
            (new Mailer())->send($to, $subject, $text, [
                'html' => true,
                'body_html' => $html,
            ]);
        }

        $logNote = 'Correo a UKS (' . $to . ') · enlaces documentos';
        if ($comprobanteUrl !== '') {
            $logNote .= ' + comprobante';
        }
        $this->tracking->addLog($trackingId, 'solicitud_uks', $logNote, null);
    }

    private function uksDocumentosHtml(string $reglamentoUrl, string $comprobanteUrl): string
    {
        $html = '<p><strong>Documentos:</strong></p><ul>'
            . '<li><a href="' . htmlspecialchars($reglamentoUrl) . '">Reglamento firmado</a></li>';
        if ($comprobanteUrl !== '') {
            $html .= '<li><a href="' . htmlspecialchars($comprobanteUrl) . '">Comprobante de pago</a></li>';
        }
        $html .= '</ul>'
            . '<p class="muted" style="font-size:.85rem">Enlaces seguros del sistema DOCEO (sin adjuntos en el correo).</p>';

        return $html;
    }

    /** @return array<string, mixed>|null */
    private function findSignedReglamentoDocument(int $trackingId, int $purchaseId): ?array
    {
        foreach (['reglamento_firmado', 'reglamento'] as $docType) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM documents WHERE tracking_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$trackingId, $docType]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        foreach (['reglamento_firmado', 'reglamento'] as $docType) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM documents WHERE purchase_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$purchaseId, $docType]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Publica folio + clave del día y avisa al alumno con link del examen.
     */
    public function publishExamAccess(
        int $trackingId,
        string $folio,
        string $accessKey,
        int $adminUserId,
        bool $notifyStudent = true
    ): void {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }
        if (!$this->isEletUksTracking($tracking)) {
            throw new \InvalidArgumentException('Este caso no es ELET-UKS.');
        }

        $folio = trim($folio);
        $accessKey = trim($accessKey);
        if ($folio === '' || $accessKey === '') {
            throw new \InvalidArgumentException('Indica folio y clave del día.');
        }

        $this->pdo->prepare(
            'UPDATE trackings SET folio = ?, access_key = ? WHERE id = ?'
        )->execute([$folio, $accessKey, $trackingId]);

        $this->tracking->setStep(
            $trackingId,
            'codigos',
            $adminUserId,
            'Folio y clave del día asignados',
            'waiting_student'
        );

        if ($notifyStudent) {
            $this->sendStudentExamAccessEmail($trackingId, $folio, $accessKey);
        }

        $this->tracking->addLog(
            $trackingId,
            'codigos',
            'Accesos examen publicados · folio ' . $folio,
            $adminUserId
        );
    }

    public function sendStudentExamAccessEmail(int $trackingId, ?string $folio = null, ?string $accessKey = null): void
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            throw new \InvalidArgumentException('Seguimiento no encontrado.');
        }

        $folio = trim($folio ?? (string) ($tracking['folio'] ?? ''));
        $accessKey = trim($accessKey ?? (string) ($tracking['access_key'] ?? ''));
        if ($folio === '' || $accessKey === '') {
            throw new \InvalidArgumentException('Faltan folio o clave del día.');
        }

        $email = (string) ($tracking['student_email'] ?? '');
        if ($email === '') {
            throw new \InvalidArgumentException('El alumno no tiene correo.');
        }

        $name = trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''));
        $matricula = (string) ($tracking['matricula'] ?? '');
        $examUrl = $this->examUrl();
        $examDate = (string) ($tracking['exam_date'] ?? '');
        $examTime = !empty($tracking['exam_time']) ? substr((string) $tracking['exam_time'], 0, 5) : '';

        $mailTpl = new MailTemplateService();
        $vars = [
            'name' => $name,
            'matricula' => $matricula,
            'exam_url' => $examUrl,
            'exam_date' => $examDate,
            'exam_time' => $examTime,
            'folio' => $folio,
            'access_key' => $accessKey,
        ];

        if ($mailTpl->render('student_elet_exam_access', $vars) !== null) {
            $mailTpl->send('student_elet_exam_access', $email, $vars);
            return;
        }

        $subject = 'Accesos a tu examen ELeT · ' . $matricula;
        $text = "Hola {$name},\n\n"
            . "Tu examen ELeT está programado para {$examDate} {$examTime}.\n\n"
            . "Entra al examen en: {$examUrl}\n"
            . "Folio (único): {$folio}\n"
            . "Clave del día: {$accessKey}\n\n"
            . "Matrícula DOCEO: {$matricula}\n\n"
            . "— Instituto DOCEO\n";

        $html = '<p>Hola ' . htmlspecialchars($name) . ',</p>'
            . '<p>Tu examen <strong>ELeT</strong> está programado para '
            . htmlspecialchars($examDate) . ' ' . htmlspecialchars($examTime) . '.</p>'
            . '<p><strong>Acceso al examen:</strong><br>'
            . '<a href="' . htmlspecialchars($examUrl) . '">' . htmlspecialchars($examUrl) . '</a></p>'
            . '<ul>'
            . '<li><strong>Folio (único):</strong> ' . htmlspecialchars($folio) . '</li>'
            . '<li><strong>Clave del día:</strong> ' . htmlspecialchars($accessKey) . '</li>'
            . '</ul>'
            . '<p>Matrícula DOCEO: ' . htmlspecialchars($matricula) . '</p>';

        (new Mailer())->send($email, $subject, $text, ['html' => true, 'body_html' => $html]);
    }

    /** Clave del día usada por otro alumno en la misma fecha (si existe). */
    public function accessKeyHintForDate(string $examDate, int $excludeTrackingId = 0): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT access_key FROM trackings
             WHERE exam_date = ? AND access_key IS NOT NULL AND access_key <> \'\'
               AND id <> ?
             ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([$examDate, $excludeTrackingId]);
        $key = $stmt->fetchColumn();

        return $key ? (string) $key : null;
    }

    /**
     * Etiquetas amigables para el panel del alumno (ELET-UKS).
     *
     * @param array<string, mixed> $tracking
     * @return array{step: string, status: string}
     */
    public function studentPortalLabels(array $tracking, array $stepLabels, array $statusLabels): array
    {
        $stepCode = (string) ($tracking['current_step_code'] ?? '');
        $statusKey = (string) ($tracking['status'] ?? '');
        $payKey = (string) ($tracking['purchase_status'] ?? '');

        $step = $stepLabels[$stepCode] ?? $stepCode;
        $status = $statusLabels[$statusKey] ?? $statusKey;

        if (!$this->isEletUksTracking($tracking)) {
            return ['step' => $step, 'status' => $status];
        }

        if ($payKey === 'paid') {
            if (in_array($stepCode, ['registro', 'confirm_pago'], true)) {
                return [
                    'step' => 'Coordinación con UKS',
                    'status' => 'Pago confirmado · esperando UKS',
                ];
            }
            if ($stepCode === 'solicitud_uks') {
                return [
                    'step' => 'Solicitud a UKS',
                    'status' => 'En proceso con UKS',
                ];
            }
            if ($stepCode === 'codigos' && empty($tracking['folio'])) {
                return [
                    'step' => 'Accesos al examen',
                    'status' => 'Asignando folio y clave',
                ];
            }
        }

        return ['step' => $step, 'status' => $status];
    }

    private function uksRequestEmail(): string
    {
        $fromSettings = trim((string) (Settings::get('uks_elet_request_email') ?? ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        $fromEnv = trim((string) (Env::get('UKS_ELET_REQUEST_EMAIL', '') ?? ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $supplier = $this->pdo->prepare('SELECT id FROM suppliers WHERE code = ? LIMIT 1');
        $supplier->execute(['uks']);
        $supplierId = (int) $supplier->fetchColumn();
        if ($supplierId > 0) {
            foreach ((new SupplierRepository())->contacts($supplierId) as $contact) {
                $email = trim((string) ($contact['email'] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $email;
                }
            }
        }

        return '';
    }
}

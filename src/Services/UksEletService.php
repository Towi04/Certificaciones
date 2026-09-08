<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Connection;
use App\Support\Settings;
use PDO;

/**
 * Flujo operativo ELET ↔ UKS tras confirmar pago del alumno.
 */
final class UksEletService
{
    private PDO $pdo;
    private TrackingService $tracking;

    public function __construct()
    {
        $this->pdo = Connection::get();
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
     * Tras confirmar pago: avanza a solicitud_uks (correo auto vía GroupEmailAutomation / ProviderRequestService).
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
            'Pago confirmado · solicitud UKS',
            'waiting_provider'
        );
    }

    /**
     * Publica folio + clave del día. Si $notifyStudent, envía plantilla del paso exam_access/codigos
     * vía StepMailService (salvo trigger=auto, ya disparado por setStep).
     *
     * @return bool true si se envió (o ya se envió en auto) el correo al alumno
     */
    public function publishExamAccess(
        int $trackingId,
        string $folio,
        string $accessKey,
        int $adminUserId,
        bool $notifyStudent = true
    ): bool {
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

        $notified = false;
        if ($notifyStudent) {
            $notified = $this->trySendExamAccessStepMail($trackingId, $adminUserId);
        }

        $this->tracking->addLog(
            $trackingId,
            'codigos',
            'Accesos examen publicados · folio ' . $folio
                . ($notifyStudent ? ($notified ? ' · correo enviado' : ' · correo no enviado') : ''),
            $adminUserId
        );

        return $notified;
    }

    /**
     * Envía plantilla del paso exam_access (o codigos) si está configurada con trigger=admin.
     * Si trigger=auto, setStep ya envió — no reenviar.
     */
    private function trySendExamAccessStepMail(int $trackingId, int $adminUserId): bool
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return false;
        }

        $product = [
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
            'id' => $tracking['product_id'] ?? 0,
            'name' => $tracking['product_name'] ?? '',
            'code' => $tracking['product_code'] ?? '',
        ];
        $defs = GroupStepConfig::defsFromConfig(CheckoutRequirements::config($product));

        $stepCode = null;
        $emailCfg = null;

        foreach ($defs as $code => $def) {
            if (!is_array($def)) {
                continue;
            }
            if ((string) ($def['action'] ?? '') !== GroupStepConfig::ACTION_EXAM_ACCESS) {
                continue;
            }
            $email = is_array($def['email'] ?? null) ? $def['email'] : [];
            if (!empty($email['enabled']) && trim((string) ($email['template_code'] ?? '')) !== '') {
                $stepCode = (string) ($def['code'] ?? $code);
                $emailCfg = $email;
                break;
            }
        }

        if ($stepCode === null && isset($defs['codigos']) && is_array($defs['codigos'])) {
            $email = is_array($defs['codigos']['email'] ?? null) ? $defs['codigos']['email'] : [];
            if (!empty($email['enabled']) && trim((string) ($email['template_code'] ?? '')) !== '') {
                $stepCode = 'codigos';
                $emailCfg = $email;
            }
        }

        if ($stepCode === null || $emailCfg === null) {
            return false;
        }

        // Evitar doble envío: trigger=auto ya disparó en setStep.
        if (($emailCfg['trigger'] ?? '') === 'auto') {
            return true;
        }

        try {
            (new StepMailService())->sendForStep($trackingId, $stepCode, $adminUserId);

            return true;
        } catch (\Throwable $e) {
            error_log('[Doceo] Exam access step mail: ' . $e->getMessage());

            return false;
        }
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
}

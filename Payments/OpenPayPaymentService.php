<?php

declare(strict_types=1);

namespace App\Payments;

use App\Catalog\CatalogRepository;
use App\Config\Env;
use App\Integrations\OpenPayClient;
use App\Mail\CaseMailService;

final class OpenPayPaymentService
{
    public function __construct(
        private readonly CatalogRepository $repo,
        private readonly OpenPayClient $openPay = new OpenPayClient(),
        private readonly ?CaseMailService $mail = null,
    ) {
    }

    private function mailer(): CaseMailService
    {
        return $this->mail ?? new CaseMailService($this->repo);
    }

    /**
     * Crea (o regenera si aún no está pagado) una CLABE SPEI única para el caso.
     *
     * @return array<string, mixed> Campos OpenPay guardados en el caso
     */
    public function ensureSpeiCharge(int $caseId, bool $forceNew = false, bool $sendInstructions = true): array
    {
        $case = $this->repo->certificationCaseDetailed($caseId);
        if (!$case) {
            throw new \RuntimeException('Caso no encontrado.');
        }

        $status = strtolower((string) ($case['openpay_status'] ?? ''));
        if (!$forceNew && in_array($status, ['completed', 'paid'], true) && !empty($case['payment_confirmed_at'])) {
            return $this->openPaySnapshot($case);
        }
        if (!$forceNew && !empty($case['openpay_clabe']) && in_array($status, ['in_progress', 'charge_pending', ''], true)) {
            if ($sendInstructions) {
                try {
                    $this->mailer()->sendTemplate($caseId, 'pago_clabe', null);
                } catch (\Throwable) {
                }
            }

            return $this->openPaySnapshot($case);
        }

        $amount = $this->amountForCase($case);
        if ($amount <= 0) {
            throw new \RuntimeException('El monto a cobrar es 0. Revisa precio público / CENNI de la certificación.');
        }

        $orderId = 'PDV-CASE-' . $caseId . '-' . date('YmdHis');
        $name = trim((string) ($case['student_name'] ?? 'Alumno Doceo'));
        $email = trim((string) ($case['student_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('El caso necesita un correo de alumno válido para OpenPay.');
        }

        $phone = preg_replace('/\D+/', '', (string) ($case['student_phone'] ?? '')) ?: '0000000000';
        $due = (new \DateTimeImmutable('+7 days'))->format('c');
        $desc = 'Certificación ' . (string) ($case['certification_code'] ?? '') . ' — caso #' . $caseId;
        if (!empty($case['exam_extraordinary']) && (float) ($case['exam_extraordinary_fee'] ?? 0) > 0) {
            $desc .= ' + aplicación extraordinaria';
        }

        $charge = $this->openPay->createBankCharge([
            'amount' => $amount,
            'description' => mb_substr($desc, 0, 250),
            'order_id' => $orderId,
            'due_date' => $due,
            'customer' => [
                'name' => mb_substr($name, 0, 100),
                'email' => $email,
                'phone_number' => mb_substr($phone, 0, 15),
            ],
        ]);

        $pm = is_array($charge['payment_method'] ?? null) ? $charge['payment_method'] : [];
        $chargeId = (string) ($charge['id'] ?? '');
        $fields = [
            'openpay_charge_id' => $chargeId,
            'openpay_order_id' => (string) ($charge['order_id'] ?? $orderId),
            'openpay_clabe' => (string) ($pm['clabe'] ?? ''),
            'openpay_bank' => (string) ($pm['bank'] ?? 'BBVA Bancomer'),
            'openpay_agreement' => (string) ($pm['agreement'] ?? ''),
            'openpay_reference' => (string) ($pm['name'] ?? $pm['agreement'] ?? ''),
            'openpay_amount' => round((float) ($charge['amount'] ?? $amount), 2),
            'openpay_status' => (string) ($charge['status'] ?? 'in_progress'),
            'openpay_due_at' => $this->normalizeDate($charge['due_date'] ?? $due),
            'openpay_pdf_url' => $chargeId !== '' ? $this->openPay->speiPdfUrl($chargeId) : null,
        ];
        $this->repo->updateCertificationCase($caseId, $fields);

        if ($sendInstructions) {
            try {
                $this->mailer()->sendTemplate($caseId, 'pago_clabe', null);
            } catch (\Throwable) {
                // La CLABE ya quedó guardada; el correo se puede reenviar desde el caso.
            }
        }

        return $fields;
    }

    /**
     * Procesa webhook OpenPay (charge.succeeded → confirma pago del caso).
     *
     * @param array<string, mixed> $payload
     */
    public function handleWebhook(array $payload): array
    {
        $this->repo->ensureOpenPayWebhookEventsTable();

        $type = (string) ($payload['type'] ?? '');
        $tx = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];
        $chargeId = (string) ($tx['id'] ?? '');
        $orderId = (string) ($tx['order_id'] ?? '');
        $verificationCode = trim((string) ($payload['verification_code'] ?? ''));

        $eventId = $this->repo->logOpenPayWebhook([
            'event_type' => $type,
            'openpay_charge_id' => $chargeId !== '' ? $chargeId : null,
            'order_id' => $orderId !== '' ? $orderId : null,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);

        if ($type === 'verification') {
            $note = $verificationCode !== '' ? 'verification_code:' . $verificationCode : null;
            $this->repo->markOpenPayWebhookProcessed($eventId, true, $note);
            try {
                $dir = dirname(__DIR__, 2) . '/storage/logs';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @file_put_contents(
                    $dir . '/openpay-webhook-verification.txt',
                    ($verificationCode !== '' ? $verificationCode : '(sin código)') . "\n" . date('c') . "\n",
                    LOCK_EX
                );
            } catch (\Throwable) {
            }

            return [
                'ok' => true,
                'type' => $type,
                'verification_code' => $verificationCode !== '' ? $verificationCode : null,
            ];
        }

        if ($type !== 'charge.succeeded' && $type !== 'spei.received') {
            $this->repo->markOpenPayWebhookProcessed($eventId, true, 'ignored:' . $type);

            return ['ok' => true, 'ignored' => $type];
        }

        try {
            // Prórroga Moodle (order PDV-EXT-…) antes que pago del caso
            $prorroga = null;
            if ($chargeId !== '') {
                $prorroga = $this->repo->courseProrrogaByOpenPayChargeId($chargeId);
            }
            if (!$prorroga && $orderId !== '') {
                $prorroga = $this->repo->courseProrrogaByOpenPayOrderId($orderId);
            }
            if (!$prorroga && $orderId !== '' && str_starts_with($orderId, 'PDV-EXT-')) {
                if (preg_match('/^PDV-EXT-(\d+)-/', $orderId, $m)) {
                    $prorroga = $this->repo->courseProrroga((int) $m[1]);
                }
            }
            if ($prorroga) {
                $prorrogaId = (int) $prorroga['id'];
                $this->repo->attachOpenPayWebhookCase($eventId, (int) $prorroga['case_id']);
                if (($prorroga['status'] ?? '') === 'paid') {
                    $this->repo->markOpenPayWebhookProcessed($eventId, true, 'prorroga_already_paid');

                    return ['ok' => true, 'prorroga_id' => $prorrogaId, 'already_paid' => true];
                }
                $result = (new \App\Services\CourseProrrogaService($this->repo, mail: $this->mailer()))
                    ->confirmPaid($prorrogaId, 'openpay', null);
                $this->repo->markOpenPayWebhookProcessed(
                    $eventId,
                    true,
                    'prorroga_paid:ends:' . ($result['access_ends_at'] ?? '')
                );

                return [
                    'ok' => true,
                    'prorroga_id' => $prorrogaId,
                    'case_id' => (int) $prorroga['case_id'],
                    'paid' => true,
                    'access_ends_at' => $result['access_ends_at'] ?? null,
                ];
            }

            $case = null;
            if ($chargeId !== '') {
                $case = $this->repo->certificationCaseByOpenPayChargeId($chargeId);
            }
            if (!$case && $orderId !== '') {
                $case = $this->repo->certificationCaseByOpenPayOrderId($orderId);
            }
            if (!$case) {
                throw new \RuntimeException('No hay caso ligado a charge/order OpenPay.');
            }

            $caseId = (int) $case['id'];
            $this->repo->attachOpenPayWebhookCase($eventId, $caseId);

            if (!empty($case['payment_confirmed_at']) && in_array(strtolower((string) ($case['openpay_status'] ?? '')), ['completed', 'paid'], true)) {
                $this->repo->markOpenPayWebhookProcessed($eventId, true, 'already_paid');

                return ['ok' => true, 'case_id' => $caseId, 'already_paid' => true];
            }

            $now = date('Y-m-d H:i:s');
            try {
                $this->repo->ensurePaymentMethodColumn();
            } catch (\Throwable) {
            }
            $this->repo->updateCertificationCase($caseId, [
                'openpay_status' => 'completed',
                'openpay_paid_at' => $now,
                'payment_confirmed_at' => $now,
                'payment_method' => 'openpay',
            ]);

            try {
                $this->repo->markCaseStepDoneByKeywords($caseId, ['realiza el pago', 'pago del examen', 'el alumno realiza'], null, 'Pago confirmado por OpenPay');
            } catch (\Throwable) {
            }

            try {
                (new \App\Workflow\ActionRunner($this->repo))->runTriggers($caseId, 'payment_confirmed', null);
            } catch (\Throwable $e) {
                error_log('[PDV] Action triggers OpenPay payment #' . $caseId . ': ' . $e->getMessage());
            }

            $moodleNote = null;
            try {
                $fulfill = (new \App\Services\ExamFulfillmentService($this->repo, $this->mailer()))->fulfillAfterPayment($caseId, null);
                $moodleResult = $fulfill['moodle'] ?? [];
                if (is_array($moodleResult) && empty($moodleResult['skipped']) && empty($moodleResult['error'])) {
                    $moodleNote = !empty($moodleResult['created_user'])
                        ? 'moodle_created:' . ($moodleResult['username'] ?? '')
                        : 'moodle_enrolled:' . ($moodleResult['username'] ?? '');
                } elseif (is_array($moodleResult) && !empty($moodleResult['error'])) {
                    $moodleNote = 'moodle_error:' . $moodleResult['error'];
                } elseif (is_array($moodleResult) && ($moodleResult['reason'] ?? '') !== 'no_moodle_courses') {
                    $moodleNote = 'moodle_skip:' . ($moodleResult['reason'] ?? '');
                }
                if (!empty($fulfill['inventory']['assigned'])) {
                    $moodleNote = ($moodleNote ? $moodleNote . '|' : '') . 'inventory_assigned';
                } elseif (!empty($fulfill['inventory']['error'])) {
                    $moodleNote = ($moodleNote ? $moodleNote . '|' : '') . 'inventory_error:' . $fulfill['inventory']['error'];
                }
                if (!empty($fulfill['access_mail']['sent'])) {
                    $moodleNote = ($moodleNote ? $moodleNote . '|' : '') . 'access_mail_sent';
                }
            } catch (\Throwable $moodleErr) {
                $moodleNote = 'fulfill_error:' . $moodleErr->getMessage();
                error_log('[PDV] Fulfill case #' . $caseId . ': ' . $moodleErr->getMessage());
            }

            try {
                $this->mailer()->sendTemplate($caseId, 'pago_confirmado', null);
            } catch (\Throwable $mailErr) {
                // Pago ya confirmado; el reenvío puede hacerse manual.
                $err = 'paid_mail_failed:' . $mailErr->getMessage();
                if ($moodleNote) {
                    $err .= '|' . $moodleNote;
                }
                $this->repo->markOpenPayWebhookProcessed($eventId, true, $err);

                return ['ok' => true, 'case_id' => $caseId, 'paid' => true, 'mail_error' => $mailErr->getMessage(), 'moodle' => $moodleNote];
            }

            $this->repo->markOpenPayWebhookProcessed($eventId, true, $moodleNote);

            return ['ok' => true, 'case_id' => $caseId, 'paid' => true, 'moodle' => $moodleNote];
        } catch (\Throwable $e) {
            $this->repo->markOpenPayWebhookProcessed($eventId, false, $e->getMessage());
            throw $e;
        }
    }

    /** @param array<string, mixed> $case */
    public function amountForCase(array $case): float
    {
        $price = (float) ($case['public_price'] ?? 0);
        // Caso solo-curso: public_price ya viene del JOIN a courses
        if ((int) ($case['course_id'] ?? 0) > 0 && (int) ($case['certification_id'] ?? 0) < 1) {
            return round(max(0, $price), 2);
        }
        $cenniProcess = (string) ($case['cenni_process'] ?? 'none');
        $cenniIncluded = (int) ($case['cenni_included'] ?? 0) === 1;
        $cenniFee = (float) ($case['cenni_fee'] ?? 0);
        if ($cenniProcess !== 'none' && !$cenniIncluded && $cenniFee > 0) {
            $price += $cenniFee;
        }
        if (!empty($case['exam_extraordinary'])) {
            $extra = (float) ($case['exam_extraordinary_fee'] ?? 0);
            if ($extra > 0) {
                $price += $extra;
            }
        }

        return round($price, 2);
    }

    /** @param array<string, mixed> $case @return array<string, mixed> */
    private function openPaySnapshot(array $case): array
    {
        return [
            'openpay_charge_id' => $case['openpay_charge_id'] ?? null,
            'openpay_order_id' => $case['openpay_order_id'] ?? null,
            'openpay_clabe' => $case['openpay_clabe'] ?? null,
            'openpay_bank' => $case['openpay_bank'] ?? null,
            'openpay_agreement' => $case['openpay_agreement'] ?? null,
            'openpay_reference' => $case['openpay_reference'] ?? null,
            'openpay_amount' => $case['openpay_amount'] ?? null,
            'openpay_status' => $case['openpay_status'] ?? null,
            'openpay_due_at' => $case['openpay_due_at'] ?? null,
            'openpay_pdf_url' => $case['openpay_pdf_url'] ?? null,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, string> */
    public static function cenniStatuses(): array
    {
        return [
            'none' => 'Sin CENNI',
            'awaiting_uks_upload' => 'Alumno debe subir docs en UKS',
            'awaiting_pdv_upload' => 'Alumno debe subir docs en Doceo',
            'docs_in_review' => 'Documentos en revisión',
            'docs_rejected' => 'Documentos rechazados / corrección',
            'sep_pending' => 'En trámite ante la SEP',
            'issued' => 'CENNI emitido',
        ];
    }

    /** @return array<string, string> */
    public static function cenniProcesses(): array
    {
        return [
            'none' => 'Sin trámite CENNI',
            'uks_external' => 'Externo UKS (ELET: alumno sube en UKS)',
            'doceo_managed' => 'Gestionado por Doceo (alumno sube docs aquí)',
        ];
    }
}

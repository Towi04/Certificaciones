<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Config\Env;
use App\Database\Connection;
use App\Integrations\OpenPayClient;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use PDO;

final class CheckoutService
{
    private PDO $pdo;
    private ProductRepository $products;
    private PurchaseRepository $purchases;
    private TrackingRepository $trackings;
    private PricingService $pricing;
    private StudentAccountService $students;
    private DocumentService $documents;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->products = new ProductRepository();
        $this->purchases = new PurchaseRepository();
        $this->trackings = new TrackingRepository();
        $this->pricing = new PricingService();
        $this->students = new StudentAccountService();
        $this->documents = new DocumentService();
    }

    /**
     * @param array{
     *   email:string,first_name:string,last_name_p:string,last_name_m?:string,
     *   phone?:string,curp?:string,birth_date?:string,sex?:string,nationality?:string
     * } $buyer
     * @param array<string, array{tmp_name:string,name:string,error:int,size:int}> $files
     * @param array{exam_date?:string,exam_time?:string,exam_kind?:string,exam_session_id?:string,exam_allow_short_advance?:bool}|null $exam
     * @return array{
     *   purchase: array<string,mixed>,
     *   created_account: bool,
     *   plain_password: ?string,
     *   openpay: ?array<string,mixed>,
     *   redirect_url: ?string
     * }
     */
    public function complete(
        int $productId,
        array $buyer,
        array $files,
        string $paymentMethod,
        ?string $promoCode,
        int $cardMsiMonths = 1,
        ?array $exam = null,
        ?int $comboId = null,
        bool $usePartnerCredit = false
    ): array {
        $product = $this->products->find($productId);
        if ($product === null || !(int) $product['is_active'] || !(int) $product['is_public']) {
            throw new \InvalidArgumentException('Producto no disponible.');
        }

        $combo = null;
        $comboItems = [];
        if ($comboId !== null && $comboId > 0) {
            $comboRepo = new \App\Repositories\ComboRepository();
            $combo = $comboRepo->find($comboId);
            if ($combo === null || !(int) $combo['is_active']) {
                throw new \InvalidArgumentException('El combo seleccionado no está disponible.');
            }
            $comboItems = $comboRepo->items($comboId);
            $ids = array_map(static fn (array $i): int => (int) $i['id'], $comboItems);
            if (!in_array($productId, $ids, true)) {
                throw new \InvalidArgumentException('Ese combo no incluye el producto que estás adquiriendo.');
            }
            if (count($comboItems) < 2) {
                throw new \InvalidArgumentException('El combo está mal configurado.');
            }
        }

        $openpayConfigured = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? '')) !== ''
            && trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? '')) !== '';

        $allowedPay = ['transfer_proof', 'openpay_spei', 'openpay_card', 'openpay_store', 'credit'];
        if (!$openpayConfigured) {
            $allowedPay = ['transfer_proof', 'openpay_store', 'credit'];
        }
        if (!in_array($paymentMethod, $allowedPay, true)) {
            throw new \InvalidArgumentException('Método de pago inválido.');
        }

        // Docs / reglamento: unión de ítems del combo (o solo el producto ancla).
        $docsProducts = $comboItems !== [] ? $comboItems : [$product];
        $required = [];
        $seenDoc = [];
        foreach ($docsProducts as $dp) {
            foreach (CheckoutRequirements::docsForProduct($dp) as $doc) {
                $code = (string) $doc['code'];
                if (isset($seenDoc[$code])) {
                    continue;
                }
                $seenDoc[$code] = true;
                $required[] = $doc;
            }
        }
        $reglamento = CheckoutRequirements::reglamentoForProduct($product);
        $this->assertRequiredDocs($required, $files);
        if ($reglamento !== null && $reglamento['required_before_checkout']) {
            $this->assertReglamentoFirmado($reglamento, $files);
        }

        $examSchedule = new ExamScheduleService();
        $examProduct = $product;
        if ($comboItems !== []) {
            foreach ($comboItems as $ci) {
                if (ExamScheduleService::needsExamAtCheckout($ci)) {
                    $examProduct = $ci;
                    break;
                }
            }
        }
        $examMeta = null;
        if (ExamScheduleService::needsExamAtCheckout($examProduct)) {
            $examDate = trim((string) ($exam['exam_date'] ?? ''));
            $examTime = trim((string) ($exam['exam_time'] ?? ''));
            if ($examDate === '' || $examTime === '') {
                throw new \InvalidArgumentException('Selecciona fecha y hora para tu examen.');
            }
            $examMeta = $examSchedule->validateSelection($examProduct, $examDate, $examTime, [
                'kind' => (string) ($exam['exam_kind'] ?? ExamScheduleService::KIND_REGULAR),
                'session_id' => (string) ($exam['exam_session_id'] ?? ''),
                'allow_short_advance' => !empty($exam['exam_allow_short_advance']),
            ]);
        }

        // Partner logueado: ignora códigos promocionales y siempre cobra su nivel.
        $session = Auth::user();
        $isPartnerSession = $session !== null && ($session['role'] ?? '') === 'partner';
        if ($isPartnerSession) {
            $promoCode = null;
        }

        if ($combo !== null) {
            $quote = $this->pricing->quoteCombo($combo, $promoCode);
        } else {
            $quote = $this->pricing->quoteProduct($product, $promoCode);
        }

        if ($isPartnerSession) {
            $quote = $this->pricing->applySessionPartnerIfAny($quote, $combo ?? $product);
        }
        $partnerId = $quote['partner_id'];

        $cardMsiMonths = max(1, $cardMsiMonths);
        $baseAmount = (float) ($quote['base'] ?? $quote['charged']);
        $examSurcharge = (float) ($examMeta['surcharge'] ?? 0);
        if ($examSurcharge > 0) {
            $baseAmount = round($baseAmount + $examSurcharge, 2);
            $quote['exam_surcharge'] = $examSurcharge;
            $quote['charged'] = $baseAmount;
            $quote['base'] = $baseAmount;
        }
        $pricingProduct = $combo ?? $product;
        // El crédito partner solo aplica en transferencia (o pago 100% con crédito).
        if ($usePartnerCredit && $paymentMethod === 'credit') {
            // ok
        } elseif ($usePartnerCredit && $paymentMethod !== 'transfer_proof') {
            throw new \InvalidArgumentException(
                'El crédito a favor solo se puede usar con transferencia o para cubrir el total.'
            );
        }
        if ($paymentMethod === 'credit' && !$usePartnerCredit) {
            $usePartnerCredit = true;
        }

        $pricingMethod = $paymentMethod === 'credit' ? 'transfer_proof' : $paymentMethod;
        $pricing = $this->resolvePaymentAmount($baseAmount, $pricingProduct, $pricingMethod, $cardMsiMonths);
        $chargeAmount = $pricing['gross'];
        $storedMsiMonths = $pricing['msi'];
        $orderTotal = $chargeAmount;

        $account = null;
        $purchaseId = 0;
        $studentUserId = 0;
        $trackingId = 0;
        $createdTrackingIds = [];
        $openpay = null;
        $redirectUrl = null;
        $cardPaymentUrl = null;
        $creditUsed = 0.0;

        // DDL (ALTER) hace COMMIT implícito en MySQL: asegurar esquema antes del TX.
        $this->purchases->ensureSchema();

        $this->pdo->beginTransaction();
        try {
            $account = $this->students->findOrCreate($buyer);
            $studentUserId = (int) $account['user']['id'];

            $matricula = $this->purchases->nextMatricula();

            $partnerCredit = round((float) ($quote['partner_credit'] ?? 0), 2);
            $partnerPriceAmt = isset($quote['partner_price']) && $quote['partner_price'] !== null
                ? round((float) $quote['partner_price'], 2)
                : null;
            // Precio de nivel del partner y crédito se basan en el monto del producto (sin comisión TDC).
            if ($partnerId && $partnerCredit <= 0 && $partnerPriceAmt !== null && $partnerPriceAmt > 0) {
                $partnerCredit = max(0.0, round($baseAmount - $partnerPriceAmt, 2));
            }

            // Partner logueado: puede descontar su saldo a favor del monto a pagar.
            if ($usePartnerCredit) {
                if (!$isPartnerSession || !$partnerId) {
                    throw new \InvalidArgumentException('Solo un partner puede usar crédito a favor.');
                }
                $creditUsed = $this->consumePartnerCredit((int) $partnerId, $orderTotal);
                $chargeAmount = max(0.0, round($orderTotal - $creditUsed, 2));
                if ($chargeAmount <= 0.009) {
                    $chargeAmount = 0.0;
                    $paymentMethod = 'credit';
                    $storedMsiMonths = null;
                } else {
                    $paymentMethod = 'transfer_proof';
                }
                // Partner registrando no genera crédito ganado.
                $partnerCredit = 0.0;
            }

            $status = in_array($paymentMethod, ['transfer_proof', 'openpay_store', 'credit'], true)
                ? 'payment_review'
                : 'awaiting_payment';

            $purchaseId = $this->purchases->create([
                'matricula' => $matricula,
                'student_user_id' => $studentUserId,
                'partner_id' => $partnerId,
                'discount_code_id' => $quote['discount_code_id'],
                'combo_id' => $combo !== null ? (int) $combo['id'] : null,
                'status' => $status,
                'payment_method' => $paymentMethod,
                'currency' => 'MXN',
                'catalog_amount' => $quote['catalog'],
                'charged_amount' => $chargeAmount,
                'card_msi_months' => $storedMsiMonths,
                'partner_price_amount' => $partnerPriceAmt,
                'partner_credit_earned' => $partnerCredit,
                'partner_credit_used' => $creditUsed,
            ]);

            $lineProducts = $comboItems !== [] ? $comboItems : [$product];
            $sharesById = [];
            if (count($lineProducts) > 1) {
                $breakdown = ComboAdminService::priceBreakdown($lineProducts, $orderTotal);
                foreach ($breakdown['items'] as $row) {
                    $sharesById[(int) $row['id']] = (float) $row['combo_share'];
                }
            }
            $n = count($lineProducts);
            $allocated = 0.0;
            $firstTrackingId = 0;
            $creditNote = $creditUsed > 0
                ? (' · crédito partner $' . number_format($creditUsed, 2)
                    . ($chargeAmount > 0
                        ? (' · transferir $' . number_format($chargeAmount, 2))
                        : ' · cubierto al 100% con crédito'))
                : '';
            foreach ($lineProducts as $idx => $lineProduct) {
                $lineProductId = (int) $lineProduct['id'];
                if ($idx === $n - 1) {
                    $lineCharge = round($orderTotal - $allocated, 2);
                } elseif (isset($sharesById[$lineProductId])) {
                    $lineCharge = round($sharesById[$lineProductId], 2);
                    $allocated += $lineCharge;
                } else {
                    $lineCharge = round($orderTotal / $n, 2);
                    $allocated += $lineCharge;
                }
                $itemId = $this->purchases->addItem(
                    $purchaseId,
                    $lineProductId,
                    (float) $lineProduct['public_price'],
                    $lineCharge
                );

                $pipelineId = $this->resolvePipelineId($lineProduct);
                $stepCode = TrackingService::initialStepCode($lineProduct, (string) $lineProduct['type'], $required);
                $trackStatus = TrackingService::initialStatus(
                    $paymentMethod === 'credit' ? 'transfer_proof' : $paymentMethod
                );
                $tid = $this->trackings->create([
                    'purchase_id' => $purchaseId,
                    'purchase_item_id' => $itemId,
                    'product_id' => $lineProductId,
                    'student_user_id' => $studentUserId,
                    'partner_id' => $partnerId,
                    'pipeline_template_id' => $pipelineId,
                    'current_step_code' => $stepCode,
                    'status' => $trackStatus,
                ]);
                $createdTrackingIds[] = $tid;
                if ($firstTrackingId === 0) {
                    $firstTrackingId = $tid;
                    $trackingId = $tid;
                }

                $this->pdo->prepare(
                    'INSERT INTO tracking_step_logs (tracking_id, step_code, note, actor_user_id)
                     VALUES (?, ?, ?, ?)'
                )->execute([
                    $tid,
                    $stepCode,
                    ($combo !== null ? ('Combo ' . $combo['code'] . ' · ') : '')
                        . 'Compra registrada · matrícula ' . $matricula . $creditNote,
                    $studentUserId,
                ]);
            }

            $this->saveDocuments($required, $files, $purchaseId, $firstTrackingId, $studentUserId);
            if ($reglamento !== null) {
                $this->saveReglamentoFirmado($reglamento, $files, $purchaseId, $firstTrackingId, $studentUserId);
            }

            if ($paymentMethod === 'credit') {
                // Cubierto solo con crédito: sin comprobante; admin confirma el uso del saldo.
            } elseif (in_array($paymentMethod, ['transfer_proof', 'openpay_store'], true)) {
                $proof = $files['payment_proof'] ?? null;
                if ($proof === null || ($proof['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new \InvalidArgumentException(
                        $creditUsed > 0
                            ? 'Sube el comprobante por el restante a transferir (' . money($chargeAmount) . ').'
                            : 'Sube el comprobante de pago.'
                    );
                }
                $stored = $this->documents->storeUploaded($proof, 'payments/' . $purchaseId, '.pdf,.jpg,.jpeg,.png');
                $this->purchases->setPaymentProof($purchaseId, $stored['path']);
            } elseif ($paymentMethod === 'openpay_spei') {
                $openpay = $this->createSpeiCharge(
                    $purchaseId,
                    $matricula,
                    $chargeAmount,
                    (string) $product['name'],
                    $account['user']
                );
            } elseif ($paymentMethod === 'openpay_card') {
                $openpay = $this->createCardRedirectCharge(
                    $purchaseId,
                    $matricula,
                    $chargeAmount,
                    (string) $product['name'],
                    $account['user'],
                    $cardMsiMonths
                );
                $cardPaymentUrl = (string) ($openpay['redirect_url'] ?? '');
            }

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $purchase = $this->purchases->find($purchaseId);
        if ($purchase === null) {
            throw new \RuntimeException('No se pudo leer la compra creada.');
        }

        if (ExamScheduleService::needsExamAtCheckout($examProduct) && $exam !== null && $examMeta !== null) {
            $trackingSvc = new TrackingService();
            $trackingSvc->saveExamSchedule($trackingId, [
                'exam_date' => $exam['exam_date'] ?? null,
                'exam_time' => $exam['exam_time'] ?? null,
                'notify' => false,
            ], $studentUserId);
            $trackingSvc->mergeExamScheduleMeta($trackingId, [
                'kind' => (string) ($examMeta['kind'] ?? ExamScheduleService::KIND_REGULAR),
                'session_id' => $examMeta['session_id'] ?? null,
                'surcharge_amount' => (float) ($examMeta['surcharge'] ?? 0),
                'requires_admin' => !empty($examMeta['requires_admin']),
                'status' => !empty($examMeta['requires_admin']) ? 'pending_admin' : 'confirmed',
                'requested_at' => date('c'),
                'label' => (string) ($examMeta['label'] ?? ''),
                'venue' => (string) ($examMeta['venue'] ?? ''),
                'city' => (string) ($examMeta['city'] ?? ''),
                'address' => (string) ($examMeta['address'] ?? ''),
            ]);
            if (!empty($examMeta['requires_admin'])) {
                $this->pdo->prepare('UPDATE trackings SET status = ? WHERE id = ?')
                    ->execute(['waiting_admin', $trackingId]);
                $trackingSvc->addLog(
                    $trackingId,
                    'examen',
                    'Fecha extraordinaria/anticipada pendiente de autorización admin',
                    $studentUserId
                );
            }
        }

        $loginUrl = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/') . '/login';
        $fullName = trim(($account['user']['first_name'] ?? '') . ' ' . ($account['user']['last_name_p'] ?? ''));
        $plainPassword = $account['created'] ? $account['plain_password'] : null;
        $payInstructionsHtml = match ($paymentMethod) {
            'transfer_proof' => 'Recibimos tu comprobante. Validaremos el pago y te avisaremos.',
            'openpay_card' => $cardPaymentUrl !== null && $cardPaymentUrl !== ''
                ? 'Te enviamos el link de pago para proceder de manera segura desde el portal OpenPay de BBVA: <a href="'
                    . htmlspecialchars($cardPaymentUrl) . '">abrir link de pago</a>.'
                : 'Te enviaremos por correo el link de pago para proceder de manera segura desde el portal OpenPay de BBVA.',
            'openpay_store' => 'Recibimos tu comprobante de depósito OXXO. Validaremos el pago y te avisaremos.',
            default => 'Tu solicitud quedó registrada. Completa el pago SPEI con los datos de tu caso.',
        };
        $passwordBlockHtml = $plainPassword !== null
            ? '<p><strong>Usuario:</strong> ' . htmlspecialchars((string) $account['user']['email'])
              . '<br><strong>Contraseña temporal:</strong> ' . htmlspecialchars((string) $plainPassword) . '</p>'
            : '<p>Usa tu cuenta existente para seguir el caso.</p>';
        $registrationVars = [
            'full_name' => $fullName,
            'name' => $fullName,
            'matricula' => (string) $purchase['matricula'],
            'amount' => money($purchase['charged_amount']),
            'pay_instructions_html' => $payInstructionsHtml,
            'password_block_html' => $passwordBlockHtml,
            'login_url' => $loginUrl,
            'temp_password' => (string) ($plainPassword ?? ''),
        ];
        $trackSvc = new TrackingService();
        foreach ($createdTrackingIds as $tid) {
            $fresh = $trackSvc->find((int) $tid);
            if ($fresh === null) {
                continue;
            }
            $stepCode = (string) ($fresh['current_step_code'] ?? '');
            if ($stepCode === '') {
                continue;
            }
            GroupEmailAutomation::sendAutoEmailsForStep($fresh, $stepCode, array_merge($registrationVars, [
                'product_name' => (string) ($fresh['product_name'] ?? $product['name'] ?? ''),
            ]));
        }

        // Partner/admin deben conservar su sesión; el alumno inicia la suya solo en compra directa.
        $actorRole = Auth::role();
        if (!in_array($actorRole, ['partner', 'admin'], true)) {
            if ($account['created']) {
                $this->students->loginAs($account['user']);
            } elseif ($actorRole === 'student' || !Auth::check()) {
                if (!Auth::check() || Auth::role() === 'student') {
                    $this->students->loginAs($account['user']);
                }
            }
        }

        return [
            'purchase' => $purchase,
            'created_account' => $account['created'],
            'plain_password' => $account['plain_password'],
            'openpay' => $openpay,
            'redirect_url' => is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : null,
            'tracking_id' => (int) ($firstTrackingId ?? $trackingId ?? 0),
            'partner_checkout' => $actorRole === 'partner',
        ];
    }

    /**
     * Tras regresar de la terminal virtual OpenPay, confirma el pago si el cargo está completed.
     */
    public function finalizeOpenPayReturn(string $matricula, ?string $chargeId = null): bool
    {
        $purchase = $this->purchases->findByMatricula($matricula);
        if ($purchase === null) {
            return false;
        }
        if ((string) $purchase['status'] === 'paid') {
            return true;
        }

        $chargeId = trim((string) ($chargeId ?? $purchase['openpay_charge_id'] ?? ''));
        if ($chargeId === '') {
            return false;
        }

        $merchant = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? ''));
        $key = trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? ''));
        if ($merchant === '' || $key === '') {
            return false;
        }

        try {
            $remote = (new OpenPayClient())->getCharge($chargeId);
        } catch (\Throwable $e) {
            error_log('[Doceo] OpenPay return verify: ' . $e->getMessage());

            return false;
        }

        if (strtolower((string) ($remote['status'] ?? '')) !== 'completed') {
            return false;
        }

        $actorId = (int) ($purchase['student_user_id'] ?? 0);
        $msi = !empty($purchase['card_msi_months']) ? (int) $purchase['card_msi_months'] : 0;
        $note = 'Pago con tarjeta OpenPay (redirect' . ($msi > 1 ? ", {$msi} MSI" : '') . ')';
        $this->confirmPayment((int) $purchase['id'], $actorId > 0 ? $actorId : $this->systemActorUserId(), $note);

        return true;
    }

    /**
     * @return array{gross: float, fee: float, msi: ?int}
     */
    private function resolvePaymentAmount(
        float $base,
        array $product,
        string $paymentMethod,
        int $cardMsiMonths
    ): array {
        if ($paymentMethod === 'transfer_proof') {
            return ['gross' => round($base, 2), 'fee' => 0.0, 'msi' => null];
        }

        if ($paymentMethod === 'openpay_spei') {
            return ['gross' => round($base, 2), 'fee' => 0.0, 'msi' => null];
        }

        if ($paymentMethod === 'openpay_store') {
            return ['gross' => round($base, 2), 'fee' => 0.0, 'msi' => null];
        }

        if ($paymentMethod === 'openpay_card') {
            if (!CardMsiCalculator::isValidMonths($base, $product, $cardMsiMonths)) {
                throw new \InvalidArgumentException('El plan de tarjeta seleccionado no aplica para este producto.');
            }
            $p = OpenPayFeeCalculator::grossCardFromNet($base, $cardMsiMonths);

            return [
                'gross' => $p['gross'],
                'fee' => $p['fee'],
                'msi' => $cardMsiMonths > 1 ? $cardMsiMonths : null,
            ];
        }

        throw new \InvalidArgumentException('Método de pago inválido.');
    }

    /**
     * Descuenta del saldo del partner (con bloqueo de fila) hasta $maxAmount.
     */
    private function consumePartnerCredit(int $partnerId, float $maxAmount): float
    {
        $maxAmount = max(0.0, round($maxAmount, 2));
        if ($partnerId < 1 || $maxAmount <= 0) {
            return 0.0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT credit_balance FROM partners WHERE id = ? AND is_active = 1 FOR UPDATE'
        );
        $stmt->execute([$partnerId]);
        $balance = round((float) ($stmt->fetchColumn() ?: 0), 2);
        if ($balance <= 0) {
            throw new \InvalidArgumentException('No tienes saldo a favor disponible.');
        }
        $use = min($balance, $maxAmount);
        if ($use <= 0) {
            return 0.0;
        }
        $upd = $this->pdo->prepare(
            'UPDATE partners SET credit_balance = credit_balance - ? WHERE id = ? AND credit_balance >= ?'
        );
        $upd->execute([$use, $partnerId, $use]);
        if ($upd->rowCount() < 1) {
            throw new \InvalidArgumentException('No se pudo aplicar el crédito; intenta de nuevo.');
        }

        return round($use, 2);
    }

    /**
     * @return array{already_paid:bool, partner_credit_applied:float}
     */
    public function confirmPayment(int $purchaseId, int $adminUserId, ?string $notes = null): array
    {
        $purchase = $this->purchases->find($purchaseId);
        if ($purchase === null) {
            throw new \InvalidArgumentException('Compra no encontrada.');
        }
        $alreadyPaid = (string) $purchase['status'] === 'paid';
        $creditApplied = 0.0;

        $this->ensurePartnerCreditAppliedColumn();

        $this->pdo->beginTransaction();
        try {
            if (!$alreadyPaid) {
                $this->purchases->markPaid($purchaseId);
            }
            // Abono idempotente: también repara compras ya pagadas sin crédito aplicado.
            $creditApplied = $this->applyPartnerCreditIfPending($purchaseId);
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($alreadyPaid) {
            return ['already_paid' => true, 'partner_credit_applied' => $creditApplied];
        }

        // El pago ya quedó en BD; un fallo de avance de pasos no debe mostrarse como
        // «error al confirmar pago» (el admin ya marcó pagado / crédito abonado).
        try {
            (new TrackingService())->onPaymentConfirmed($purchaseId, $adminUserId, $notes);
        } catch (\Throwable $e) {
            error_log('[Doceo] onPaymentConfirmed tras marcar pagado: ' . $e->getMessage());
        }
        try {
            // Confirmación de pago: alumno o partner (precio de nivel), no ambos.
            GroupEmailAutomation::sendPaymentConfirmedEmails($purchaseId);
        } catch (\Throwable $e) {
            error_log('[Doceo] emails tras pago: ' . $e->getMessage());
        }

        return ['already_paid' => false, 'partner_credit_applied' => $creditApplied];
    }

    /**
     * Asegura columna de idempotencia en instalaciones ya desplegadas.
     */
    private function ensurePartnerCreditAppliedColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM purchases LIKE 'partner_credit_applied_at'");
            if ($stmt && $stmt->fetch()) {
                $done = true;

                return;
            }
            if ($this->pdo->inTransaction()) {
                throw new \RuntimeException(
                    'Esquema de compras incompleto (partner_credit_applied_at). Reintenta la operación.'
                );
            }
            $this->pdo->exec(
                'ALTER TABLE purchases ADD COLUMN partner_credit_applied_at DATETIME NULL AFTER partner_credit_earned'
            );
            $done = true;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[Doceo] ensurePartnerCreditAppliedColumn: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Abona partner_credit_earned al saldo del partner (una sola vez por compra).
     * Si el crédito quedó en 0 pero hay partner_price_amount, lo recalcula.
     *
     * @return float Monto abonado en esta llamada (0 si ya estaba aplicado o no aplica)
     */
    public function applyPartnerCreditIfPending(int $purchaseId): float
    {
        $this->ensurePartnerCreditAppliedColumn();
        $purchase = $this->purchases->find($purchaseId);
        if ($purchase === null) {
            return 0.0;
        }
        if ((string) ($purchase['status'] ?? '') !== 'paid') {
            return 0.0;
        }
        if (!empty($purchase['partner_credit_applied_at'])) {
            return 0.0;
        }

        $partnerId = (int) ($purchase['partner_id'] ?? 0);
        $credit = round((float) ($purchase['partner_credit_earned'] ?? 0), 2);

        // Reparar compras donde el código partner sí ligó partner_id/precio pero el crédito quedó en 0.
        if ($credit <= 0 && $partnerId > 0) {
            $credit = $this->recomputePartnerCredit($purchase);
            if ($credit > 0) {
                $this->pdo->prepare(
                    'UPDATE purchases SET partner_credit_earned = ? WHERE id = ? AND partner_credit_earned <= 0'
                )->execute([$credit, $purchaseId]);
            }
        }

        if ($partnerId < 1 || $credit <= 0) {
            $this->pdo->prepare(
                'UPDATE purchases SET partner_credit_applied_at = COALESCE(partner_credit_applied_at, NOW()) WHERE id = ?'
            )->execute([$purchaseId]);

            return 0.0;
        }

        $this->pdo->prepare(
            'UPDATE partners SET credit_balance = credit_balance + ? WHERE id = ?'
        )->execute([$credit, $partnerId]);
        $this->pdo->prepare(
            'UPDATE purchases SET partner_credit_applied_at = NOW(), partner_credit_earned = ? WHERE id = ?'
        )->execute([$credit, $purchaseId]);

        error_log(sprintf(
            '[Doceo] Crédito partner +%0.2f aplicado (purchase=%d partner=%d)',
            $credit,
            $purchaseId,
            $partnerId
        ));

        return $credit;
    }

    /**
     * Recalcula crédito = cobrado (sin MSI) − precio de nivel del partner.
     *
     * @param array<string, mixed> $purchase
     */
    private function recomputePartnerCredit(array $purchase): float
    {
        $partnerPrice = (float) ($purchase['partner_price_amount'] ?? 0);
        if ($partnerPrice <= 0) {
            return 0.0;
        }
        $charged = (float) ($purchase['charged_amount'] ?? 0);
        $msi = (int) ($purchase['card_msi_months'] ?? 0);
        // Con MSI el charged incluye comisión; usar precio público del primer ítem si existe.
        if ($msi > 1) {
            $items = $this->purchases->items((int) $purchase['id']);
            $sumPublic = 0.0;
            foreach ($items as $item) {
                $sumPublic += (float) ($item['unit_public_price'] ?? 0);
            }
            if ($sumPublic > 0) {
                $charged = $sumPublic;
            }
        }

        return max(0.0, round($charged - $partnerPrice, 2));
    }

    /**
     * Aplica créditos pendientes de un partner (compras pagadas sin abono).
     *
     * @return array{applied:int, amount:float}
     */
    public function applyPendingPartnerCreditsForPartner(int $partnerId): array
    {
        $this->ensurePartnerCreditAppliedColumn();
        if ($partnerId < 1) {
            return ['applied' => 0, 'amount' => 0.0];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id FROM purchases
             WHERE partner_id = ?
               AND status = 'paid'
               AND partner_credit_applied_at IS NULL
             ORDER BY id ASC"
        );
        $stmt->execute([$partnerId]);
        $ids = array_map(static fn ($r) => (int) $r['id'], $stmt->fetchAll() ?: []);

        $applied = 0;
        $amount = 0.0;
        foreach ($ids as $id) {
            $ownTx = !$this->pdo->inTransaction();
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }
            try {
                $added = $this->applyPartnerCreditIfPending($id);
                if ($ownTx) {
                    $this->pdo->commit();
                }
                if ($added > 0) {
                    $applied++;
                    $amount += $added;
                }
            } catch (\Throwable $e) {
                if ($ownTx && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log('[Doceo] applyPendingPartnerCreditsForPartner #' . $id . ': ' . $e->getMessage());
            }
        }

        return ['applied' => $applied, 'amount' => round($amount, 2)];
    }

    /**
     * Webhook OpenPay: confirma SPEI cuando el cargo queda completed.
     * Idempotente si la compra ya está paid.
     *
     * @return array{ok:bool,action:string,type?:string,purchase_id?:int,matricula?:string,reason?:string}
     */
    public function handleOpenPayWebhook(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON de webhook inválido.');
        }

        $type = (string) ($payload['type'] ?? $payload['event_type'] ?? '');
        if ($type === '' || $type === 'verification') {
            return ['ok' => true, 'action' => 'ack', 'type' => $type !== '' ? $type : 'empty'];
        }

        $successTypes = ['charge.succeeded', 'spei.received'];
        if (!in_array($type, $successTypes, true)) {
            return ['ok' => true, 'action' => 'ignored', 'type' => $type, 'reason' => 'event_not_payment'];
        }

        $tx = $payload['transaction'] ?? $payload['data'] ?? null;
        if (!is_array($tx)) {
            return ['ok' => true, 'action' => 'ignored', 'type' => $type, 'reason' => 'no_transaction'];
        }

        $chargeId = trim((string) ($tx['id'] ?? ''));
        if ($chargeId === '') {
            return ['ok' => true, 'action' => 'ignored', 'type' => $type, 'reason' => 'no_charge_id'];
        }

        $purchase = $this->purchases->findByOpenPayChargeId($chargeId);
        if ($purchase === null) {
            $orderId = (string) ($tx['order_id'] ?? '');
            $matricula = $this->matriculaFromOpenPayOrderId($orderId);
            if ($matricula !== null) {
                $purchase = $this->purchases->findByMatricula($matricula);
            }
        }
        if ($purchase === null) {
            return [
                'ok' => true,
                'action' => 'ignored',
                'type' => $type,
                'reason' => 'purchase_not_found',
            ];
        }

        if ((string) $purchase['status'] === 'paid') {
            return [
                'ok' => true,
                'action' => 'already_paid',
                'type' => $type,
                'purchase_id' => (int) $purchase['id'],
                'matricula' => (string) $purchase['matricula'],
            ];
        }

        // Verifica en la API que el cargo esté completed (no confiar solo en el evento).
        $merchant = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? ''));
        $key = trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? ''));
        if ($merchant !== '' && $key !== '') {
            $remote = (new OpenPayClient())->getCharge($chargeId);
            $remoteStatus = strtolower((string) ($remote['status'] ?? ''));
            if ($remoteStatus !== 'completed') {
                return [
                    'ok' => true,
                    'action' => 'ignored',
                    'type' => $type,
                    'purchase_id' => (int) $purchase['id'],
                    'matricula' => (string) $purchase['matricula'],
                    'reason' => 'charge_status_' . $remoteStatus,
                ];
            }
        }

        $actorId = $this->systemActorUserId();
        $this->confirmPayment(
            (int) $purchase['id'],
            $actorId,
            'Pago SPEI confirmado por OpenPay (' . $type . ', cargo ' . $chargeId . ')'
        );

        return [
            'ok' => true,
            'action' => 'confirmed',
            'type' => $type,
            'purchase_id' => (int) $purchase['id'],
            'matricula' => (string) $purchase['matricula'],
        ];
    }

    private function matriculaFromOpenPayOrderId(string $orderId): ?string
    {
        // Formato al crear SPEI: doceo-{matricula}-{timestamp}
        if (preg_match('/^doceo-(.+)-(\d+)$/', trim($orderId), $m)) {
            return $m[1];
        }

        return null;
    }

    private function systemActorUserId(): int
    {
        $email = trim((string) (Env::get('ADMIN_EMAIL', '') ?? ''));
        if ($email !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM users WHERE email = ? AND role = 'admin' LIMIT 1"
            );
            $stmt->execute([$email]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        $id = $this->pdo->query(
            "SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
        )->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('No hay usuario admin para registrar la confirmación de pago.');
        }

        return (int) $id;
    }

    /** @param array{doc_code:string,label?:string} $reglamento @param array<string, array{tmp_name:string,name:string,error:int,size:int}> $files */
    private function assertReglamentoFirmado(array $reglamento, array $files): void
    {
        $key = 'doc_' . $reglamento['doc_code'];
        $file = $files[$key] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('Debes leer y firmar el reglamento antes de continuar.');
        }
    }

    /**
     * @param array{doc_code:string} $reglamento
     * @param array<string, array{tmp_name:string,name:string,error:int,size:int}> $files
     */
    private function saveReglamentoFirmado(
        array $reglamento,
        array $files,
        int $purchaseId,
        int $trackingId,
        int $studentUserId
    ): void {
        $key = 'doc_' . $reglamento['doc_code'];
        $file = $files[$key] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        $stored = $this->documents->storeUploaded($file, 'docs/' . $purchaseId, '.pdf');
        $this->pdo->prepare(
            'INSERT INTO documents (tracking_id, purchase_id, student_user_id, doc_type, original_name, storage_path, status, uploaded_by)
             VALUES (?,?,?,?,?,?,\'approved\',?)'
        )->execute([
            $trackingId,
            $purchaseId,
            $studentUserId,
            $reglamento['doc_code'],
            $stored['original_name'],
            $stored['path'],
            $studentUserId,
        ]);
    }

    /**
     * @param list<array{code:string,label:string,required:bool,accept:string}> $required
     * @param array<string, array{tmp_name:string,name:string,error:int,size:int}> $files
     */
    private function assertRequiredDocs(array $required, array $files): void
    {
        foreach ($required as $doc) {
            if (!$doc['required']) {
                continue;
            }
            $key = 'doc_' . $doc['code'];
            $file = $files[$key] ?? null;
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new \InvalidArgumentException('Falta el documento: ' . $doc['label']);
            }
        }
    }

    /**
     * @param list<array{code:string,label:string,required:bool,accept:string}> $required
     * @param array<string, array{tmp_name:string,name:string,error:int,size:int}> $files
     */
    private function saveDocuments(
        array $required,
        array $files,
        int $purchaseId,
        int $trackingId,
        int $studentUserId
    ): void {
        foreach ($required as $doc) {
            $key = 'doc_' . $doc['code'];
            $file = $files[$key] ?? null;
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $stored = $this->documents->storeUploaded(
                $file,
                'docs/' . $purchaseId,
                (string) ($doc['accept'] ?? '.pdf,.jpg,.jpeg,.png')
            );
            $this->pdo->prepare(
                'INSERT INTO documents (tracking_id, purchase_id, student_user_id, doc_type, original_name, storage_path, status, uploaded_by)
                 VALUES (?,?,?,?,?,?,\'pending\',?)'
            )->execute([
                $trackingId,
                $purchaseId,
                $studentUserId,
                $doc['code'],
                $stored['original_name'],
                $stored['path'],
                $studentUserId,
            ]);
        }
    }

    /** @param array<string, mixed> $user */
    private function createSpeiCharge(
        int $purchaseId,
        string $matricula,
        float $amount,
        string $productName,
        array $user
    ): ?array {
        $merchant = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? ''));
        $key = trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? ''));
        if ($merchant === '' || $key === '') {
            return null;
        }

        $client = new OpenPayClient();
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name_p'] ?? ''));
        $charge = $client->createBankCharge([
            'amount' => $amount,
            'description' => 'DOCEO ' . $matricula . ' · ' . mb_substr($productName, 0, 80),
            'order_id' => 'doceo-' . $matricula . '-' . time(),
            'customer' => [
                'name' => $name !== '' ? $name : 'Alumno DOCEO',
                'email' => (string) $user['email'],
                'phone_number' => (string) ($user['phone'] ?? ''),
            ],
        ]);

        $chargeId = (string) ($charge['id'] ?? '');
        $clabe = (string) ($charge['payment_method']['clabe'] ?? $charge['clabe'] ?? '');
        $this->purchases->setOpenPay($purchaseId, $chargeId, $clabe !== '' ? $clabe : null);

        return [
            'charge_id' => $chargeId,
            'clabe' => $clabe,
            'bank' => (string) ($charge['payment_method']['bank'] ?? ''),
            'pdf_url' => $chargeId !== '' ? $client->speiPdfUrl($chargeId) : null,
            'beneficiary' => Env::get('OPENPAY_BENEFICIARY_NAME', 'Instituto DOCEO'),
            'status' => (string) ($charge['status'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $user */
    private function createCardRedirectCharge(
        int $purchaseId,
        string $matricula,
        float $amount,
        string $productName,
        array $user,
        int $msiMonths
    ): array {
        $merchant = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? ''));
        $key = trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? ''));
        if ($merchant === '' || $key === '') {
            throw new \RuntimeException('OpenPay no está configurado.');
        }

        $client = new OpenPayClient();
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name_p'] ?? ''));
        $appUrl = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
        $redirectUrl = $appUrl . '/compra/' . rawurlencode($matricula) . '?openpay_return=1';

        $charge = $client->createCardRedirectCharge([
            'amount' => $amount,
            'description' => 'DOCEO ' . $matricula . ' · ' . mb_substr($productName, 0, 80),
            'order_id' => 'doceo-' . $matricula . '-' . time(),
            'redirect_url' => $redirectUrl,
            'customer' => [
                'name' => $name !== '' ? $name : 'Alumno DOCEO',
                'email' => (string) $user['email'],
                'phone_number' => (string) ($user['phone'] ?? ''),
            ],
            'payments' => $msiMonths > 1 ? $msiMonths : null,
        ]);

        $chargeId = (string) ($charge['id'] ?? '');
        if ($chargeId === '') {
            throw new \RuntimeException('OpenPay no devolvió identificador de cargo.');
        }

        $payUrl = (string) ($charge['payment_method']['url'] ?? '');
        if ($payUrl === '') {
            throw new \RuntimeException('OpenPay no devolvió URL de pago seguro.');
        }

        $this->purchases->setOpenPayCharge($purchaseId, $chargeId, null, null, null);

        return [
            'charge_id' => $chargeId,
            'redirect_url' => $payUrl,
            'status' => (string) ($charge['status'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $user */
    private function createStoreCharge(
        int $purchaseId,
        string $matricula,
        float $amount,
        string $productName,
        array $user
    ): array {
        $merchant = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? ''));
        $key = trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? ''));
        if ($merchant === '' || $key === '') {
            throw new \RuntimeException('OpenPay no está configurado.');
        }

        $client = new OpenPayClient();
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name_p'] ?? ''));
        $dueDate = (new \DateTimeImmutable('+3 days'))->format('Y-m-d\TH:i:s');

        $charge = $client->createStoreCharge([
            'amount' => $amount,
            'description' => 'DOCEO ' . $matricula . ' · ' . mb_substr($productName, 0, 80),
            'order_id' => 'doceo-' . $matricula . '-' . time(),
            'due_date' => $dueDate,
            'customer' => [
                'name' => $name !== '' ? $name : 'Alumno DOCEO',
                'email' => (string) $user['email'],
                'phone_number' => (string) ($user['phone'] ?? ''),
            ],
        ]);

        $chargeId = (string) ($charge['id'] ?? '');
        $reference = (string) ($charge['payment_method']['reference'] ?? '');
        $barcodeUrl = (string) ($charge['payment_method']['barcode_url'] ?? '');
        if ($chargeId === '') {
            throw new \RuntimeException('OpenPay no devolvió referencia OXXO.');
        }

        $this->purchases->setOpenPayCharge($purchaseId, $chargeId, null, $reference !== '' ? $reference : null, $barcodeUrl !== '' ? $barcodeUrl : null);

        return [
            'charge_id' => $chargeId,
            'reference' => $reference,
            'barcode_url' => $barcodeUrl,
            'due_date' => (string) ($charge['due_date'] ?? ''),
            'status' => (string) ($charge['status'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $product */
    private function resolvePipelineId(array $product): ?int
    {
        $code = CheckoutRequirements::pipelineCode($product);
        if ($code !== null) {
            $stmt = $this->pdo->prepare('SELECT id FROM pipeline_templates WHERE code = ? LIMIT 1');
            $stmt->execute([$code]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        $productType = (string) ($product['type'] ?? 'certification');
        $stmt = $this->pdo->prepare(
            'SELECT id FROM pipeline_templates WHERE product_type = ? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$productType]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Config\Env;
use App\Database\Connection;
use App\Integrations\Mailer;
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
     * @return array{
     *   purchase: array<string,mixed>,
     *   created_account: bool,
     *   plain_password: ?string,
     *   openpay: ?array<string,mixed>
     * }
     */
    public function complete(
        int $productId,
        array $buyer,
        array $files,
        string $paymentMethod,
        ?string $promoCode,
        int $cardMsiMonths = 1,
        ?string $openpayToken = null,
        ?string $deviceSessionId = null
    ): array {
        $product = $this->products->find($productId);
        if ($product === null || !(int) $product['is_active'] || !(int) $product['is_public']) {
            throw new \InvalidArgumentException('Producto no disponible.');
        }

        $allowedPay = ['transfer_proof', 'openpay_spei', 'openpay_card'];
        if (!in_array($paymentMethod, $allowedPay, true)) {
            throw new \InvalidArgumentException('Método de pago inválido.');
        }

        $required = CheckoutRequirements::docsForProduct($product);
        $this->assertRequiredDocs($required, $files);

        $quote = $this->pricing->quoteProduct($product, $promoCode);
        $partnerId = $quote['partner_id'];

        // Partner logueado adquiriendo al precio de su nivel (sin código)
        $session = Auth::user();
        if ($session !== null && ($session['role'] ?? '') === 'partner' && $partnerId === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM partners WHERE user_id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([(int) $session['id']]);
            $partner = $stmt->fetch();
            if ($partner) {
                $tierPrice = $this->pricing->partnerPriceForProduct($product, (string) $partner['tier']);
                $quote['partner_id'] = (int) $partner['id'];
                $quote['partner_price'] = $tierPrice;
                $quote['charged'] = $tierPrice;
                $quote['partner_credit'] = 0.0;
                $quote['label'] = 'Precio partner (' . strtoupper((string) $partner['tier']) . ')';
                $partnerId = (int) $partner['id'];
            }
        }


        $cardMsiMonths = max(1, $cardMsiMonths);
        $chargeAmount = (float) $quote['charged'];
        $storedMsiMonths = null;

        if ($paymentMethod === 'openpay_card') {
            if (!CardMsiCalculator::isValidMonths($chargeAmount, $product, $cardMsiMonths)) {
                throw new \InvalidArgumentException('El plan MSI seleccionado no aplica para este producto.');
            }
            $token = trim((string) $openpayToken);
            if ($token === '') {
                throw new \InvalidArgumentException('No se recibió el token de tarjeta. Intenta de nuevo.');
            }
            $storedMsiMonths = $cardMsiMonths > 1 ? $cardMsiMonths : null;
        } elseif ($cardMsiMonths > 1) {
            throw new \InvalidArgumentException('Los meses sin intereses solo aplican al pagar con tarjeta.');
        }

        $account = null;
        $purchaseId = 0;
        $studentUserId = 0;
        $openpay = null;
        $cardChargeCompleted = false;

        $this->pdo->beginTransaction();
        try {
            $account = $this->students->findOrCreate($buyer);
            $studentUserId = (int) $account['user']['id'];

            $matricula = $this->purchases->nextMatricula();
            $status = $paymentMethod === 'transfer_proof' ? 'payment_review' : 'awaiting_payment';

            $purchaseId = $this->purchases->create([
                'matricula' => $matricula,
                'student_user_id' => $studentUserId,
                'partner_id' => $partnerId,
                'discount_code_id' => $quote['discount_code_id'],
                'combo_id' => null,
                'status' => $status,
                'payment_method' => $paymentMethod,
                'currency' => 'MXN',
                'catalog_amount' => $quote['catalog'],
                'charged_amount' => $quote['charged'],
                'card_msi_months' => $storedMsiMonths,
                'partner_price_amount' => $quote['partner_price'],
                'partner_credit_earned' => $quote['partner_credit'],
            ]);

            $itemId = $this->purchases->addItem(
                $purchaseId,
                $productId,
                (float) $product['public_price'],
                (float) $quote['charged']
            );

            $pipelineId = $this->resolvePipelineId((string) $product['type']);
            $stepCode = TrackingService::initialStepCode((string) $product['type'], $required);
            $trackStatus = TrackingService::initialStatus($paymentMethod);
            $trackingId = $this->trackings->create([
                'purchase_id' => $purchaseId,
                'purchase_item_id' => $itemId,
                'product_id' => $productId,
                'student_user_id' => $studentUserId,
                'partner_id' => $partnerId,
                'pipeline_template_id' => $pipelineId,
                'current_step_code' => $stepCode,
                'status' => $trackStatus,
            ]);

            $this->pdo->prepare(
                'INSERT INTO tracking_step_logs (tracking_id, step_code, note, actor_user_id)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                $trackingId,
                $stepCode,
                'Compra registrada · matrícula ' . $matricula,
                $studentUserId,
            ]);

            $this->saveDocuments($required, $files, $purchaseId, $trackingId, $studentUserId);

            if ($paymentMethod === 'transfer_proof') {
                $proof = $files['payment_proof'] ?? null;
                if ($proof === null || ($proof['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new \InvalidArgumentException('Sube el comprobante de transferencia.');
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
                $openpay = $this->createCardCharge(
                    $purchaseId,
                    $matricula,
                    $chargeAmount,
                    (string) $product['name'],
                    $account['user'],
                    (string) $openpayToken,
                    $deviceSessionId,
                    $cardMsiMonths
                );
                $cardChargeCompleted = strtolower((string) ($openpay['status'] ?? '')) === 'completed';
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($cardChargeCompleted) {
            $this->confirmPayment(
                $purchaseId,
                $studentUserId,
                'Pago con tarjeta OpenPay' . ($storedMsiMonths ? " ({$storedMsiMonths} MSI)" : '')
            );
        }

        $purchase = $this->purchases->find($purchaseId);
        if ($purchase === null) {
            throw new \RuntimeException('No se pudo leer la compra creada.');
        }

        $this->sendWelcomeEmail(
            $account['user'],
            $purchase,
            (string) $product['name'],
            $account['created'] ? $account['plain_password'] : null,
            $paymentMethod
        );

        if ($account['created']) {
            $this->students->loginAs($account['user']);
        } elseif (Auth::role() === 'student' || !Auth::check()) {
            // Si ya existía y no hay sesión admin/partner, inicia sesión del alumno
            if (!Auth::check() || Auth::role() === 'student') {
                $this->students->loginAs($account['user']);
            }
        }

        return [
            'purchase' => $purchase,
            'created_account' => $account['created'],
            'plain_password' => $account['plain_password'],
            'openpay' => $openpay,
        ];
    }

    public function confirmPayment(int $purchaseId, int $adminUserId, ?string $notes = null): void
    {
        $purchase = $this->purchases->find($purchaseId);
        if ($purchase === null) {
            throw new \InvalidArgumentException('Compra no encontrada.');
        }
        if ((string) $purchase['status'] === 'paid') {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->purchases->markPaid($purchaseId);

            $credit = (float) $purchase['partner_credit_earned'];
            if ($credit > 0 && !empty($purchase['partner_id'])) {
                $this->pdo->prepare(
                    'UPDATE partners SET credit_balance = credit_balance + ? WHERE id = ?'
                )->execute([$credit, (int) $purchase['partner_id']]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        (new TrackingService())->onPaymentConfirmed($purchaseId, $adminUserId, $notes);

        $fresh = $this->purchases->find($purchaseId);
        if ($fresh) {
            $this->sendPaymentConfirmedEmail($fresh);
        }
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
    private function createCardCharge(
        int $purchaseId,
        string $matricula,
        float $amount,
        string $productName,
        array $user,
        string $token,
        ?string $deviceSessionId,
        int $msiMonths
    ): array {
        $merchant = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? ''));
        $key = trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? ''));
        $public = trim((string) (Env::get('OPENPAY_PUBLIC_KEY', '') ?? ''));
        if ($merchant === '' || $key === '' || $public === '') {
            throw new \RuntimeException('OpenPay no está configurado para pagos con tarjeta.');
        }

        $client = new OpenPayClient();
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name_p'] ?? ''));
        $payload = [
            'amount' => $amount,
            'description' => 'DOCEO ' . $matricula . ' · ' . mb_substr($productName, 0, 80),
            'order_id' => 'doceo-' . $matricula . '-' . time(),
            'source_id' => $token,
            'customer' => [
                'name' => $name !== '' ? $name : 'Alumno DOCEO',
                'email' => (string) $user['email'],
                'phone_number' => (string) ($user['phone'] ?? ''),
            ],
            'payments' => $msiMonths > 1 ? $msiMonths : null,
        ];
        if ($deviceSessionId !== null && trim($deviceSessionId) !== '') {
            $payload['device_session_id'] = trim($deviceSessionId);
        }

        $charge = $client->createCardCharge($payload);
        $chargeId = (string) ($charge['id'] ?? '');
        if ($chargeId === '') {
            throw new \RuntimeException('OpenPay no devolvió identificador de cargo.');
        }

        $this->purchases->setOpenPay($purchaseId, $chargeId, null);

        return [
            'charge_id' => $chargeId,
            'status' => (string) ($charge['status'] ?? ''),
            'authorization' => (string) ($charge['authorization'] ?? ''),
        ];
    }

    private function resolvePipelineId(string $productType): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM pipeline_templates WHERE product_type = ? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$productType]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $purchase */
    private function sendWelcomeEmail(
        array $user,
        array $purchase,
        string $productName,
        ?string $plainPassword,
        string $paymentMethod
    ): void {
        try {
            $mailer = new Mailer();
            $loginUrl = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/') . '/login';
            $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name_p'] ?? ''));
            $matricula = (string) $purchase['matricula'];
            $amount = money($purchase['charged_amount']);

            $passText = $plainPassword !== null
                ? "Usuario: {$user['email']}\nContraseña temporal: {$plainPassword}\nCámbiala después de iniciar sesión.\n"
                : "Usa tu cuenta existente para seguir el caso.\n";

            $payText = match ($paymentMethod) {
                'transfer_proof' => "Recibimos tu comprobante. Validaremos el pago y te avisaremos.\n",
                'openpay_card' => "Recibimos tu pago con tarjeta. Te avisaremos cuando quede confirmado en el sistema.\n",
                default => "Tu solicitud quedó registrada. Completa el pago SPEI con los datos de tu caso.\n",
            };

            $text = "Hola {$fullName},\n\n"
                . "Registramos tu adquisición de {$productName}.\n"
                . "Matrícula / caso: {$matricula}\n"
                . "Monto: {$amount} MXN\n\n"
                . $payText
                . $passText
                . "\nInicia sesión: {$loginUrl}\n\n— Instituto DOCEO\n";

            $html = '<p>Hola ' . htmlspecialchars($fullName) . ',</p>'
                . '<p>Registramos tu adquisición de <strong>' . htmlspecialchars($productName) . '</strong>.</p>'
                . '<p><strong>Matrícula:</strong> ' . htmlspecialchars($matricula) . '<br>'
                . '<strong>Monto:</strong> ' . htmlspecialchars($amount) . ' MXN</p>'
                . '<p>' . nl2br(htmlspecialchars(trim($payText))) . '</p>'
                . ($plainPassword !== null
                    ? '<p><strong>Usuario:</strong> ' . htmlspecialchars((string) $user['email'])
                      . '<br><strong>Contraseña temporal:</strong> ' . htmlspecialchars($plainPassword) . '</p>'
                    : '<p>Usa tu cuenta existente para seguir el caso.</p>')
                . '<p><a href="' . htmlspecialchars($loginUrl) . '">Iniciar sesión</a></p>'
                . '<p>— Instituto DOCEO</p>';

            $mailer->send(
                (string) $user['email'],
                'Tu caso ' . $matricula . ' — Instituto DOCEO',
                $text,
                ['html' => true, 'body_html' => $html]
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] Welcome email: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $purchase */
    private function sendPaymentConfirmedEmail(array $purchase): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT u.email, u.first_name, u.last_name_p,
                        (SELECT pr.name FROM purchase_items pi
                         JOIN products pr ON pr.id = pi.product_id
                         WHERE pi.purchase_id = pu.id LIMIT 1) AS product_name
                 FROM purchases pu
                 JOIN users u ON u.id = pu.student_user_id
                 WHERE pu.id = ?'
            );
            $stmt->execute([(int) $purchase['id']]);
            $row = $stmt->fetch();
            if (!$row) {
                return;
            }
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name_p'] ?? ''));
            $text = "Hola {$name},\n\nConfirmamos el pago de tu caso {$purchase['matricula']} "
                . "({$row['product_name']}).\nYa puedes dar seguimiento desde tu portal.\n\n— Instituto DOCEO\n";
            $html = '<p>Hola ' . htmlspecialchars($name) . ',</p>'
                . '<p>Confirmamos el pago de tu caso <strong>' . htmlspecialchars((string) $purchase['matricula']) . '</strong>'
                . ' (' . htmlspecialchars((string) $row['product_name']) . ').</p>'
                . '<p>Ya puedes dar seguimiento desde tu portal.</p><p>— Instituto DOCEO</p>';

            (new Mailer())->send(
                (string) $row['email'],
                'Pago confirmado — caso ' . $purchase['matricula'],
                $text,
                ['html' => true, 'body_html' => $html]
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] Payment confirm email: ' . $e->getMessage());
        }
    }
}

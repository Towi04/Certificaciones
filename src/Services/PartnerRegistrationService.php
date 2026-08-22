<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use PDO;

/**
 * Partner registra un alumno a precio de su nivel, con fecha de examen.
 */
final class PartnerRegistrationService
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

    /** @return array<string, mixed> */
    public function partnerForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM partners WHERE user_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('No hay ficha de partner activa para este usuario.');
        }

        return $row;
    }

    /**
     * @param array{
     *   email:string,first_name:string,last_name_p:string,last_name_m?:string,phone?:string
     * } $buyer
     * @param array{
     *   exam_date?:string,exam_time?:string
     * } $exam
     * @param array<string, array{tmp_name:string,name:string,error:int,size:int}> $files
     * @return array{purchase_id:int,tracking_id:int,matricula:string,created_account:bool,plain_password:?string}
     */
    public function register(int $partnerUserId, int $productId, array $buyer, array $exam, array $files = []): array
    {
        $partner = $this->partnerForUser($partnerUserId);
        $product = $this->products->find($productId);
        if ($product === null || !(int) $product['is_active']) {
            throw new \InvalidArgumentException('Producto no disponible.');
        }

        $tierPrice = $this->pricing->partnerPriceForProduct($product, (string) $partner['tier']);
        $catalog = (float) ($product['catalog_price'] ?? 0);
        if ($catalog <= 0) {
            $catalog = \App\Support\Settings::catalogPriceFromPublic((float) $product['public_price']);
        }

        $phone = trim((string) ($buyer['phone'] ?? ''));
        if ($phone === '') {
            throw new \InvalidArgumentException('El teléfono del alumno es obligatorio.');
        }
        $buyer['phone'] = $phone;

        $needsExam = in_array((string) $product['type'], ['certification', 'procedure'], true);
        if ($needsExam && trim((string) ($exam['exam_date'] ?? '')) === '') {
            throw new \InvalidArgumentException('Indica la fecha de examen del alumno.');
        }
        if ($needsExam && trim((string) ($exam['exam_time'] ?? '')) === '') {
            throw new \InvalidArgumentException('Indica la hora del examen.');
        }

        $proof = $files['payment_proof'] ?? null;
        if ($proof === null || ($proof['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('Sube el comprobante de pago (transferencia a DOCEO).');
        }

        $this->pdo->beginTransaction();
        try {
            $account = $this->students->findOrCreate($buyer);
            $studentUserId = (int) $account['user']['id'];
            $matricula = $this->purchases->nextMatricula();

            $purchaseId = $this->purchases->create([
                'matricula' => $matricula,
                'student_user_id' => $studentUserId,
                'partner_id' => (int) $partner['id'],
                'discount_code_id' => null,
                'combo_id' => null,
                'status' => 'payment_review',
                'payment_method' => 'transfer_proof',
                'currency' => 'MXN',
                'catalog_amount' => $catalog,
                'charged_amount' => $tierPrice,
                'partner_price_amount' => $tierPrice,
                'partner_credit_earned' => 0.0,
            ]);

            $stored = $this->documents->storeUploaded($proof, 'payments/' . $purchaseId, '.pdf,.jpg,.jpeg,.png');
            $this->purchases->setPaymentProof($purchaseId, $stored['path']);

            $itemId = $this->purchases->addItem(
                $purchaseId,
                $productId,
                (float) $product['public_price'],
                $tierPrice
            );

            $pipelineId = $this->resolvePipelineId($product);
            $step = TrackingService::initialStepCode((string) $product['type'], []);

            $trackingId = $this->trackings->create([
                'purchase_id' => $purchaseId,
                'purchase_item_id' => $itemId,
                'product_id' => $productId,
                'student_user_id' => $studentUserId,
                'partner_id' => (int) $partner['id'],
                'pipeline_template_id' => $pipelineId,
                'current_step_code' => $step,
                'status' => TrackingService::initialStatus('transfer_proof'),
            ]);

            $this->pdo->prepare(
                'INSERT INTO tracking_step_logs (tracking_id, step_code, note, actor_user_id)
                 VALUES (?,?,?,?)'
            )->execute([
                $trackingId,
                $step,
                'Alta partner ' . $partner['code'] . ' · matrícula ' . $matricula
                    . ' · $' . number_format($tierPrice, 2) . ' · comprobante en revisión',
                $partnerUserId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($needsExam) {
            // Guarda fecha/hora; aviso al alumno cuando admin confirme el pago.
            (new TrackingService())->saveExamSchedule($trackingId, [
                'exam_date' => $exam['exam_date'] ?? null,
                'exam_time' => $exam['exam_time'] ?? null,
                'notify' => false,
            ], $partnerUserId);
        }

        return [
            'purchase_id' => $purchaseId,
            'tracking_id' => $trackingId,
            'matricula' => $matricula,
            'created_account' => $account['created'],
            'plain_password' => $account['plain_password'],
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

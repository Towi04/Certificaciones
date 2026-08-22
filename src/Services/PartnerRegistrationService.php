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

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->products = new ProductRepository();
        $this->purchases = new PurchaseRepository();
        $this->trackings = new TrackingRepository();
        $this->pricing = new PricingService();
        $this->students = new StudentAccountService();
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
     * @return array{purchase_id:int,tracking_id:int,matricula:string,created_account:bool,plain_password:?string}
     */
    public function register(int $partnerUserId, int $productId, array $buyer, array $exam): array
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
                'status' => 'paid',
                'payment_method' => 'partner_account',
                'currency' => 'MXN',
                'catalog_amount' => $catalog,
                'charged_amount' => $tierPrice,
                'partner_price_amount' => $tierPrice,
                'partner_credit_earned' => 0.0,
            ]);
            $this->pdo->prepare('UPDATE purchases SET paid_at = NOW() WHERE id = ?')->execute([$purchaseId]);

            $itemId = $this->purchases->addItem(
                $purchaseId,
                $productId,
                (float) $product['public_price'],
                $tierPrice
            );

            $pipelineId = $this->resolvePipelineId((string) $product['type']);
            $step = match ((string) $product['type']) {
                'course' => 'alta_moodle',
                'procedure' => 'revision',
                default => 'examen',
            };

            $trackingId = $this->trackings->create([
                'purchase_id' => $purchaseId,
                'purchase_item_id' => $itemId,
                'product_id' => $productId,
                'student_user_id' => $studentUserId,
                'partner_id' => (int) $partner['id'],
                'pipeline_template_id' => $pipelineId,
                'current_step_code' => $step,
                'status' => $needsExam ? 'waiting_student' : 'waiting_admin',
            ]);

            $this->pdo->prepare(
                'INSERT INTO tracking_step_logs (tracking_id, step_code, note, actor_user_id)
                 VALUES (?,?,?,?)'
            )->execute([
                $trackingId,
                'registro',
                'Alta partner ' . $partner['code'] . ' · matrícula ' . $matricula . ' · $' . number_format($tierPrice, 2),
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
            // Solo fecha/hora principal en el alta. Reagenda (2ª fecha) y Zoom los carga admin.
            (new TrackingService())->saveExamSchedule($trackingId, [
                'exam_date' => $exam['exam_date'] ?? null,
                'exam_time' => $exam['exam_time'] ?? null,
                'notify' => true,
            ], $partnerUserId);
        }

        if ((string) $product['type'] === 'course' && ($product['platform_type'] ?? '') === 'moodle') {
            try {
                (new MoodleEnrolmentService())->syncTracking($trackingId, $partnerUserId, true);
            } catch (\Throwable $e) {
                error_log('[Doceo] Partner Moodle: ' . $e->getMessage());
            }
        }

        return [
            'purchase_id' => $purchaseId,
            'tracking_id' => $trackingId,
            'matricula' => $matricula,
            'created_account' => $account['created'],
            'plain_password' => $account['plain_password'],
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
}

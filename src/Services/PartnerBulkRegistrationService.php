<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use PDO;

/**
 * Registro masivo partner: 1 producto + 1 fecha + CSV + 1 comprobante.
 * Monto esperado = precio partner × N.
 */
final class PartnerBulkRegistrationService
{
    public const CSV_HEADERS = [
        'email',
        'first_name',
        'last_name_p',
        'last_name_m',
        'phone',
        'curp',
        'birth_date',
        'sex',
        'nationality',
    ];

    private PDO $pdo;
    private ProductRepository $products;
    private PurchaseRepository $purchases;
    private TrackingRepository $trackings;
    private PricingService $pricing;
    private StudentAccountService $students;
    private DocumentService $documents;
    private PartnerRegistrationService $partnerReg;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->products = new ProductRepository();
        $this->purchases = new PurchaseRepository();
        $this->trackings = new TrackingRepository();
        $this->pricing = new PricingService();
        $this->students = new StudentAccountService();
        $this->documents = new DocumentService();
        $this->partnerReg = new PartnerRegistrationService();
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS partner_registration_batches (
                  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  partner_id BIGINT UNSIGNED NOT NULL,
                  product_id BIGINT UNSIGNED NOT NULL,
                  exam_date DATE NULL,
                  exam_time TIME NULL,
                  expected_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                  student_count INT UNSIGNED NOT NULL DEFAULT 0,
                  proof_path VARCHAR(255) NULL,
                  status ENUM('payment_review','paid','cancelled') NOT NULL DEFAULT 'payment_review',
                  notes TEXT NULL,
                  created_by BIGINT UNSIGNED NULL,
                  paid_at DATETIME NULL,
                  paid_by BIGINT UNSIGNED NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                  KEY idx_prb_partner (partner_id),
                  KEY idx_prb_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $stmt = $this->pdo->query("SHOW COLUMNS FROM purchases LIKE 'batch_id'");
            if (!$stmt || !$stmt->fetch()) {
                $this->pdo->exec(
                    'ALTER TABLE purchases ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER partner_id'
                );
                try {
                    $this->pdo->exec('ALTER TABLE purchases ADD KEY idx_purchases_batch (batch_id)');
                } catch (\Throwable) {
                    // índice ya existe
                }
            }
        } catch (\Throwable $e) {
            error_log('[Doceo] PartnerBulkRegistrationService::ensureSchema: ' . $e->getMessage());
            throw $e;
        }
        $done = true;
    }

    /** @return array<string, mixed> */
    public function partnerForUser(int $userId): array
    {
        return $this->partnerReg->partnerForUser($userId);
    }

    /**
     * @return array{
     *   rows:list<array<string,string>>,
     *   errors:list<string>,
     *   valid_count:int
     * }
     */
    public function parseCsv(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('No se pudo leer el CSV.');
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \InvalidArgumentException('No se pudo abrir el CSV.');
        }
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $header = fgetcsv($fh);
        if (!is_array($header) || $header === []) {
            fclose($fh);
            throw new \InvalidArgumentException('El CSV está vacío o no tiene encabezados.');
        }
        $map = [];
        foreach ($header as $i => $col) {
            $key = strtolower(trim((string) $col));
            $key = str_replace([' ', '-'], '_', $key);
            $aliases = [
                'correo' => 'email',
                'e_mail' => 'email',
                'nombre' => 'first_name',
                'apellido_paterno' => 'last_name_p',
                'apellido_materno' => 'last_name_m',
                'telefono' => 'phone',
                'tel' => 'phone',
                'fecha_nacimiento' => 'birth_date',
                'sexo' => 'sex',
                'nacionalidad' => 'nationality',
            ];
            $key = $aliases[$key] ?? $key;
            if (in_array($key, self::CSV_HEADERS, true)) {
                $map[$key] = (int) $i;
            }
        }
        foreach (['email', 'first_name', 'last_name_p', 'phone'] as $required) {
            if (!isset($map[$required])) {
                fclose($fh);
                throw new \InvalidArgumentException(
                    'Falta la columna obligatoria «' . $required . '» en el CSV.'
                );
            }
        }

        $rows = [];
        $errors = [];
        $line = 1;
        $seenEmails = [];
        while (($data = fgetcsv($fh)) !== false) {
            $line++;
            if (!is_array($data)) {
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
            foreach (self::CSV_HEADERS as $key) {
                $idx = $map[$key] ?? null;
                $row[$key] = $idx !== null ? trim((string) ($data[$idx] ?? '')) : '';
            }
            $email = strtolower($row['email']);
            $row['email'] = $email;
            $rowErrors = [];
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = 'correo inválido';
            } elseif (isset($seenEmails[$email])) {
                $rowErrors[] = 'correo duplicado en el CSV';
            }
            if ($row['first_name'] === '') {
                $rowErrors[] = 'nombre vacío';
            }
            if ($row['last_name_p'] === '') {
                $rowErrors[] = 'apellido paterno vacío';
            }
            if ($row['phone'] === '') {
                $rowErrors[] = 'teléfono vacío';
            }
            if ($row['birth_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['birth_date'])) {
                // permitir DD/MM/YYYY
                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $row['birth_date'], $m)) {
                    $row['birth_date'] = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
                } else {
                    $rowErrors[] = 'fecha de nacimiento inválida (usa YYYY-MM-DD)';
                }
            }
            if ($rowErrors !== []) {
                $errors[] = 'Fila ' . $line . ': ' . implode('; ', $rowErrors);
                continue;
            }
            $seenEmails[$email] = true;
            $rows[] = $row;
        }
        fclose($fh);

        return [
            'rows' => $rows,
            'errors' => $errors,
            'valid_count' => count($rows),
        ];
    }

    public function csvTemplate(): string
    {
        $fh = fopen('php://temp', 'r+b');
        if ($fh === false) {
            return implode(',', self::CSV_HEADERS) . "\n";
        }
        csv_put($fh, self::CSV_HEADERS);
        csv_put($fh, [
            'alumno@ejemplo.com',
            'Ana',
            'García',
            'López',
            '5512345678',
            'GALA900101MDFRRN09',
            '1990-01-01',
            'F',
            'Mexicana',
        ]);
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $csv;
    }

    /**
     * @param list<array<string,string>> $rows
     * @param array{tmp_name:string,name:string,error:int,size:int} $proof
     * @return array{batch_id:int,student_count:int,expected_amount:float,purchase_ids:list<int>}
     */
    public function createBatch(
        int $partnerUserId,
        int $productId,
        string $examDate,
        string $examTime,
        array $rows,
        array $proof
    ): array {
        $this->ensureSchema();
        $this->purchases->ensureSchema();

        $partner = $this->partnerForUser($partnerUserId);
        $product = $this->products->find($productId);
        if ($product === null || !(int) $product['is_active']) {
            throw new \InvalidArgumentException('Producto no disponible.');
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('No hay filas válidas en el CSV.');
        }
        if (($proof['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('Sube el comprobante de pago del grupo.');
        }

        $needsExam = in_array((string) $product['type'], ['certification', 'procedure'], true);
        if ($needsExam) {
            $examDate = ExamScheduleService::normalizeDateStatic($examDate) ?? '';
            $examTime = self::normalizeTime($examTime);
            if ($examDate === '' || $examTime === '') {
                throw new \InvalidArgumentException('Indica fecha y hora de examen válidas.');
            }
            if (ExamScheduleService::needsExamAtCheckout($product)) {
                (new ExamScheduleService())->validateSelection($product, $examDate, $examTime);
            }
        } else {
            $examDate = '';
            $examTime = '';
        }

        $unitPrice = $this->pricing->partnerPriceForProduct($product, (string) $partner['tier']);
        $catalog = (float) ($product['catalog_price'] ?? 0);
        if ($catalog <= 0) {
            $catalog = \App\Support\Settings::catalogPriceFromPublic((float) $product['public_price']);
        }
        $count = count($rows);
        $expected = round($unitPrice * $count, 2);
        $pipelineId = $this->resolvePipelineId($product);
        $step = TrackingService::initialStepCode($product, (string) $product['type'], []);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO partner_registration_batches
                    (partner_id, product_id, exam_date, exam_time, expected_amount, unit_price,
                     student_count, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                (int) $partner['id'],
                $productId,
                $examDate !== '' ? $examDate : null,
                $examTime !== '' ? $examTime : null,
                $expected,
                $unitPrice,
                $count,
                'payment_review',
                $partnerUserId,
            ]);
            $batchId = (int) $this->pdo->lastInsertId();

            $stored = $this->documents->storeUploaded($proof, 'payments/batch_' . $batchId, '.pdf,.jpg,.jpeg,.png');
            $this->pdo->prepare(
                'UPDATE partner_registration_batches SET proof_path = ? WHERE id = ?'
            )->execute([$stored['path'], $batchId]);

            $purchaseIds = [];
            foreach ($rows as $row) {
                $account = $this->students->findOrCreate([
                    'email' => $row['email'],
                    'first_name' => $row['first_name'],
                    'last_name_p' => $row['last_name_p'],
                    'last_name_m' => $row['last_name_m'] ?? '',
                    'phone' => $row['phone'],
                    'curp' => $row['curp'] ?? '',
                    'birth_date' => $row['birth_date'] ?? '',
                    'sex' => $row['sex'] ?? '',
                    'nationality' => $row['nationality'] ?? '',
                ]);
                $studentUserId = (int) $account['user']['id'];
                $matricula = $this->purchases->nextMatricula();

                $purchaseId = $this->createPurchaseWithBatch([
                    'matricula' => $matricula,
                    'student_user_id' => $studentUserId,
                    'partner_id' => (int) $partner['id'],
                    'batch_id' => $batchId,
                    'discount_code_id' => null,
                    'combo_id' => null,
                    'status' => 'payment_review',
                    'payment_method' => 'transfer_proof',
                    'currency' => 'MXN',
                    'catalog_amount' => $catalog,
                    'charged_amount' => $unitPrice,
                    'partner_price_amount' => $unitPrice,
                    'partner_credit_earned' => 0.0,
                ]);
                $this->purchases->setPaymentProof($purchaseId, $stored['path']);
                $itemId = $this->purchases->addItem(
                    $purchaseId,
                    $productId,
                    (float) $product['public_price'],
                    $unitPrice
                );
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
                    'Alta masiva partner ' . $partner['code'] . ' · lote #' . $batchId
                        . ' · matrícula ' . $matricula
                        . ' · $' . number_format($unitPrice, 2) . ' · comprobante en revisión',
                    $partnerUserId,
                ]);
                $purchaseIds[] = $purchaseId;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($needsExam) {
            $trackingSvc = new TrackingService();
            foreach ($purchaseIds as $purchaseId) {
                $trackings = $this->trackings->forPurchase($purchaseId);
                foreach ($trackings as $t) {
                    try {
                        $trackingSvc->saveExamSchedule((int) $t['id'], [
                            'exam_date' => $examDate,
                            'exam_time' => $examTime,
                            'notify' => false,
                        ], $partnerUserId);
                    } catch (\Throwable $e) {
                        error_log('[Doceo] bulk exam schedule: ' . $e->getMessage());
                    }
                }
            }
        }

        return [
            'batch_id' => $batchId,
            'student_count' => $count,
            'expected_amount' => $expected,
            'purchase_ids' => $purchaseIds,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listForPartner(int $partnerId): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT b.*, pr.name AS product_name
             FROM partner_registration_batches b
             JOIN products pr ON pr.id = b.product_id
             WHERE b.partner_id = ?
             ORDER BY b.created_at DESC, b.id DESC'
        );
        $stmt->execute([$partnerId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return list<array<string, mixed>> */
    public function adminList(?string $status = null, int $limit = 100): array
    {
        $this->ensureSchema();
        $sql = 'SELECT b.*, pr.name AS product_name, p.code AS partner_code, p.display_name AS partner_name
                FROM partner_registration_batches b
                JOIN products pr ON pr.id = b.product_id
                JOIN partners p ON p.id = b.partner_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE b.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY b.created_at DESC, b.id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string, mixed>|null */
    public function find(int $batchId): ?array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT b.*, pr.name AS product_name, p.code AS partner_code, p.display_name AS partner_name
             FROM partner_registration_batches b
             JOIN products pr ON pr.id = b.product_id
             JOIN partners p ON p.id = b.partner_id
             WHERE b.id = ?
             LIMIT 1'
        );
        $stmt->execute([$batchId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function purchasesForBatch(int $batchId): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT pu.*, u.first_name, u.last_name_p, u.email,
                    t.id AS tracking_id, t.status AS tracking_status, t.exam_date, t.exam_time
             FROM purchases pu
             JOIN users u ON u.id = pu.student_user_id
             LEFT JOIN trackings t ON t.purchase_id = pu.id
             WHERE pu.batch_id = ?
             ORDER BY pu.id ASC'
        );
        $stmt->execute([$batchId]);

        return $stmt->fetchAll() ?: [];
    }

    /** @return array{confirmed:int,already_paid:int,errors:list<string>} */
    public function confirmBatchPayment(int $batchId, int $adminUserId, ?string $notes = null): array
    {
        $batch = $this->find($batchId);
        if ($batch === null) {
            throw new \InvalidArgumentException('Lote no encontrado.');
        }
        $purchases = $this->purchasesForBatch($batchId);
        if ($purchases === []) {
            throw new \InvalidArgumentException('El lote no tiene compras.');
        }
        $checkout = new CheckoutService();
        $confirmed = 0;
        $already = 0;
        $errors = [];
        foreach ($purchases as $pu) {
            try {
                $result = $checkout->confirmPayment((int) $pu['id'], $adminUserId, $notes);
                if (!empty($result['already_paid'])) {
                    $already++;
                } else {
                    $confirmed++;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Compra #' . (int) $pu['id'] . ': ' . $e->getMessage();
            }
        }
        $this->pdo->prepare(
            'UPDATE partner_registration_batches
             SET status = ?, paid_at = COALESCE(paid_at, NOW()), paid_by = COALESCE(paid_by, ?), notes = ?
             WHERE id = ?'
        )->execute([
            $errors === [] ? 'paid' : 'payment_review',
            $adminUserId,
            $notes,
            $batchId,
        ]);

        return ['confirmed' => $confirmed, 'already_paid' => $already, 'errors' => $errors];
    }

    /** @param array<string, mixed> $data */
    private function createPurchaseWithBatch(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchases (
                matricula, student_user_id, partner_id, batch_id, discount_code_id, combo_id,
                status, payment_method, currency, catalog_amount, charged_amount,
                partner_price_amount, partner_credit_earned, partner_credit_used
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $data['matricula'],
            $data['student_user_id'],
            $data['partner_id'],
            $data['batch_id'],
            $data['discount_code_id'],
            $data['combo_id'],
            $data['status'],
            $data['payment_method'],
            $data['currency'],
            $data['catalog_amount'],
            $data['charged_amount'],
            $data['partner_price_amount'],
            $data['partner_credit_earned'],
            0.0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private static function normalizeTime(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
        }

        return '';
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

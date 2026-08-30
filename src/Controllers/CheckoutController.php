<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Config\Env;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Services\CheckoutRequirements;
use App\Services\CheckoutService;
use App\Services\ComboAdminService;
use App\Services\ExamScheduleService;
use App\Services\PricingService;
use App\Support\Settings;

final class CheckoutController
{
    public function show(string $slug): void
    {
        $product = $this->loadProduct($slug);
        $user = Auth::user();
        $prefill = [
            'email' => $user['email'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name_p' => $user['last_name_p'] ?? '',
            'last_name_m' => $user['last_name_m'] ?? '',
            'phone' => $user['phone'] ?? '',
        ];

        $offers = (new ComboAdminService())->offersForProduct((int) $product['id']);
        view('checkout/acquire', [
            'title' => 'Adquirir · ' . $product['name'],
            'product' => $product,
            'fields' => CheckoutRequirements::fieldsForProduct($product),
            'docs' => CheckoutRequirements::docsForProduct($product),
            'reglamento' => CheckoutRequirements::reglamentoForProduct($product),
            'prefill' => $prefill,
            'user' => $user,
            'quote' => (new PricingService())->quoteProduct($product, null),
            'comboOffers' => $offers,
            'openpayReady' => $this->openPayConfigured(),
            'bank' => $this->bankTransferInfo(),
            'depositCard' => $this->depositCardNumber(),
            'needsExam' => ExamScheduleService::needsExamAtCheckout($product),
            'examMinDate' => ExamScheduleService::needsExamAtCheckout($product)
                ? (new ExamScheduleService())->minSelectableDate($product)
                : null,
        ]);
    }

    public function submit(string $slug): void
    {
        csrf_verify();
        $product = $this->loadProduct($slug);
        $fieldCodes = array_column(CheckoutRequirements::fieldsForProduct($product), 'code');

        $buyer = [
            'email' => trim((string) ($_POST['email'] ?? '')),
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name_p' => trim((string) ($_POST['last_name_p'] ?? '')),
            'last_name_m' => in_array('last_name_m', $fieldCodes, true) ? trim((string) ($_POST['last_name_m'] ?? '')) : '',
            'phone' => in_array('phone', $fieldCodes, true) ? trim((string) ($_POST['phone'] ?? '')) : '',
            'curp' => in_array('curp', $fieldCodes, true) ? strtoupper(trim((string) ($_POST['curp'] ?? ''))) : '',
            'birth_date' => in_array('birth_date', $fieldCodes, true) ? trim((string) ($_POST['birth_date'] ?? '')) : '',
            'sex' => in_array('sex', $fieldCodes, true) ? trim((string) ($_POST['sex'] ?? '')) : '',
            'nationality' => in_array('nationality', $fieldCodes, true)
                ? trim((string) ($_POST['nationality'] ?? 'México'))
                : '',
        ];

        $paymentMethod = (string) ($_POST['payment_method'] ?? 'transfer_proof');
        $promoCode = trim((string) ($_POST['promo_code'] ?? ''));
        $cardMsiMonths = max(1, (int) ($_POST['card_msi_months'] ?? 1));

        try {
            foreach (CheckoutRequirements::fieldsForProduct($product) as $field) {
                if (!$field['required']) {
                    continue;
                }
                $code = $field['code'];
                if (($buyer[$code] ?? '') === '') {
                    throw new \InvalidArgumentException('Completa el campo: ' . $field['label']);
                }
            }

            $result = (new CheckoutService())->complete(
                (int) $product['id'],
                $buyer,
                $_FILES,
                $paymentMethod,
                $promoCode !== '' ? $promoCode : null,
                $cardMsiMonths,
                ExamScheduleService::needsExamAtCheckout($product)
                    ? [
                        'exam_date' => trim((string) ($_POST['exam_date'] ?? '')),
                        'exam_time' => trim((string) ($_POST['exam_time'] ?? '')),
                    ]
                    : null,
                !empty($_POST['combo_id']) ? (int) $_POST['combo_id'] : null
            );

            $matricula = (string) $result['purchase']['matricula'];
            if ($result['created_account'] && $result['plain_password']) {
                flash('info', 'Cuenta creada. Contraseña temporal: ' . $result['plain_password']);
            }

            if (!empty($result['redirect_url'])) {
                if ($result['created_account']) {
                    $_SESSION['_doceo_pending_flash'] = ['success', 'Matrícula ' . $matricula . ' — completa tu pago en OpenPay.'];
                }
                header('Location: ' . $result['redirect_url']);
                exit;
            }

            flash('success', 'Compra registrada. Matrícula ' . $matricula);
            redirect('/compra/' . rawurlencode($matricula));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/adquirir/' . rawurlencode($slug));
        } catch (\Throwable $e) {
            error_log('[Doceo] Checkout: ' . $e->getMessage());
            flash('error', 'No se pudo completar la compra: ' . $e->getMessage());
            redirect('/adquirir/' . rawurlencode($slug));
        }
    }

    public function quote(string $slug): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $product = (new ProductRepository())->findBySlug($slug);
        if (!$product || !(int) $product['is_active']) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);

            return;
        }
        $code = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
        try {
            $quote = (new PricingService())->quoteProduct($product, $code);
            echo json_encode(['ok' => true, 'quote' => $quote], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }


    public function quoteCombo(string $slug): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $product = (new ProductRepository())->findBySlug($slug);
        if (!$product || !(int) $product['is_active']) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);

            return;
        }

        $addonIds = [];
        if (isset($_GET['addons']) && is_string($_GET['addons']) && $_GET['addons'] !== '') {
            foreach (explode(',', $_GET['addons']) as $raw) {
                $id = (int) trim($raw);
                if ($id > 0) {
                    $addonIds[] = $id;
                }
            }
        }
        $comboId = isset($_GET['combo_id']) ? (int) $_GET['combo_id'] : 0;
        $code = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
        $pricing = new PricingService();

        try {
            $comboRepo = new \App\Repositories\ComboRepository();
            $combo = null;
            if ($comboId > 0) {
                $combo = $comboRepo->find($comboId);
            } else {
                $set = array_values(array_unique(array_merge([(int) $product['id']], $addonIds)));
                $combo = $comboRepo->findActiveByExactProductSet($set);
            }

            if ($combo === null || !(int) ($combo['is_active'] ?? 0)) {
                echo json_encode([
                    'ok' => true,
                    'matched' => false,
                    'combo_id' => null,
                    'quote' => $pricing->quoteProduct($product, $code !== '' ? $code : null),
                    'message' => 'Sin combo para esa combinación; precio del producto solo.',
                ], JSON_UNESCAPED_UNICODE);

                return;
            }

            $ids = $comboRepo->productIds((int) $combo['id']);
            if (!in_array((int) $product['id'], $ids, true)) {
                throw new \InvalidArgumentException('El combo no incluye este producto.');
            }

            $quote = $pricing->quoteCombo($combo, $code !== '' ? $code : null);
            $session = Auth::user();
            if ($session !== null && ($session['role'] ?? '') === 'partner' && empty($quote['partner_id'])) {
                $pdo = \App\Database\Connection::get();
                $st = $pdo->prepare('SELECT * FROM partners WHERE user_id = ? AND is_active = 1 LIMIT 1');
                $st->execute([(int) $session['id']]);
                $partner = $st->fetch();
                if ($partner) {
                    $tierPrice = $pricing->partnerPriceForProduct($combo, (string) $partner['tier']);
                    $quote['partner_id'] = (int) $partner['id'];
                    $quote['partner_price'] = $tierPrice;
                    $quote['base'] = $tierPrice;
                    $quote['charged'] = $tierPrice;
                    $quote['partner_credit'] = 0.0;
                    $quote['label'] = 'Precio partner (' . strtoupper((string) $partner['tier']) . ')';
                }
            }

            echo json_encode([
                'ok' => true,
                'matched' => true,
                'combo_id' => (int) $combo['id'],
                'combo' => [
                    'id' => (int) $combo['id'],
                    'code' => $combo['code'],
                    'name' => $combo['name'],
                    'item_ids' => $ids,
                ],
                'quote' => $quote,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function examSlots(string $slug): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $product = (new ProductRepository())->findBySlug($slug);
        if (!$product || !(int) $product['is_active']) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Producto no encontrado']);

            return;
        }

        if (!ExamScheduleService::needsExamAtCheckout($product)) {
            echo json_encode(['ok' => true, 'slots' => [], 'min_date' => null]);

            return;
        }

        $service = new ExamScheduleService();
        $date = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';

        if ($date === '') {
            echo json_encode([
                'ok' => true,
                'min_date' => $service->minSelectableDate($product),
                'dates' => $service->selectableDates($product),
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        echo json_encode([
            'ok' => true,
            'slots' => $service->slotsForDate($product, $date),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function success(string $matricula): void
    {
        $purchase = (new PurchaseRepository())->findByMatricula($matricula);
        if ($purchase === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Compra no encontrada']);

            return;
        }

        $user = Auth::user();
        $isOwner = $user && (
            ((int) $user['id'] === (int) $purchase['student_user_id'])
            || ($user['role'] ?? '') === 'admin'
            || ($user['role'] ?? '') === 'partner'
        );
        if (!$isOwner) {
            flash('error', 'Inicia sesión para ver el detalle de tu compra.');
            redirect('/login');
        }

        $chargeId = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null;
        if ($chargeId === null && isset($_GET['openpay_return'])) {
            $chargeId = (string) ($purchase['openpay_charge_id'] ?? '');
        }
        if ($chargeId !== null && $chargeId !== '') {
            if ((new CheckoutService())->finalizeOpenPayReturn($matricula, $chargeId)) {
                flash('success', 'Pago con tarjeta confirmado.');
                $purchase = (new PurchaseRepository())->findByMatricula($matricula) ?? $purchase;
            }
        }

        if (isset($_SESSION['_doceo_pending_flash'])) {
            [$type, $msg] = $_SESSION['_doceo_pending_flash'];
            unset($_SESSION['_doceo_pending_flash']);
            flash($type, $msg);
        }

        $repo = new PurchaseRepository();
        $items = $repo->items((int) $purchase['id']);
        $openpayPdf = null;
        if (!empty($purchase['openpay_charge_id']) && $this->openPayConfigured()
            && ($purchase['payment_method'] ?? '') === 'openpay_spei') {
            try {
                $openpayPdf = (new \App\Integrations\OpenPayClient())->speiPdfUrl((string) $purchase['openpay_charge_id']);
            } catch (\Throwable) {
                $openpayPdf = null;
            }
        }

        view('checkout/success', [
            'title' => 'Caso ' . $purchase['matricula'],
            'purchase' => $purchase,
            'items' => $items,
            'bank' => $this->bankTransferInfo(),
            'depositCard' => $this->depositCardNumber(),
            'openpayPdf' => $openpayPdf,
            'user' => $user,
        ]);
    }

    /** @return array<string, mixed> */
    private function loadProduct(string $slug): array
    {
        $product = (new ProductRepository())->findBySlug($slug);
        if (!$product || !(int) $product['is_active'] || !(int) $product['is_public']) {
            http_response_code(404);
            view('errors/404', ['title' => 'Producto no encontrado']);
            exit;
        }

        return $product;
    }

    /** @return array{bank:string,clabe:string,holder:string,concept:string} */
    private function bankTransferInfo(): array
    {
        return [
            'bank' => Settings::get('bank_transfer_bank', Env::get('BANK_TRANSFER_BANK', 'BBVA')) ?? 'BBVA',
            'clabe' => Settings::get('bank_transfer_clabe', Env::get('BANK_TRANSFER_CLABE', '')) ?? '',
            'holder' => Settings::get('bank_transfer_holder', Env::get('BANK_TRANSFER_HOLDER', 'Instituto DOCEO')) ?? 'Instituto DOCEO',
            'concept' => Settings::get('bank_transfer_concept', 'Matrícula DOCEO') ?? 'Matrícula DOCEO',
        ];
    }

    private function depositCardNumber(): string
    {
        return Settings::get('oxxo_deposit_card', Env::get('OXXO_DEPOSIT_CARD', '4555113010972414')) ?? '4555113010972414';
    }

    private function openPayConfigured(): bool
    {
        $m = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? ''));
        $k = trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? ''));

        return $m !== '' && $k !== '';
    }
}

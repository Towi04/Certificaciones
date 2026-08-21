<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Config\Env;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Services\CheckoutService;
use App\Services\PricingService;
use App\Services\RequiredDocuments;
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

        view('checkout/acquire', [
            'title' => 'Adquirir · ' . $product['name'],
            'product' => $product,
            'docs' => RequiredDocuments::forProduct($product),
            'prefill' => $prefill,
            'user' => $user,
            'quote' => (new PricingService())->quoteProduct($product, null),
            'bank' => $this->bankTransferInfo(),
            'openpayReady' => $this->openPayConfigured(),
        ]);
    }

    public function submit(string $slug): void
    {
        csrf_verify();
        $product = $this->loadProduct($slug);

        $buyer = [
            'email' => trim((string) ($_POST['email'] ?? '')),
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name_p' => trim((string) ($_POST['last_name_p'] ?? '')),
            'last_name_m' => trim((string) ($_POST['last_name_m'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'curp' => strtoupper(trim((string) ($_POST['curp'] ?? ''))),
            'birth_date' => trim((string) ($_POST['birth_date'] ?? '')),
            'sex' => trim((string) ($_POST['sex'] ?? '')),
            'nationality' => trim((string) ($_POST['nationality'] ?? 'México')),
        ];

        $paymentMethod = (string) ($_POST['payment_method'] ?? 'transfer_proof');
        $promoCode = trim((string) ($_POST['promo_code'] ?? ''));

        try {
            if ($buyer['first_name'] === '' || $buyer['last_name_p'] === '') {
                throw new \InvalidArgumentException('Nombre y apellido paterno son obligatorios.');
            }

            $result = (new CheckoutService())->complete(
                (int) $product['id'],
                $buyer,
                $_FILES,
                $paymentMethod,
                $promoCode !== '' ? $promoCode : null
            );

            $matricula = (string) $result['purchase']['matricula'];
            if ($result['created_account'] && $result['plain_password']) {
                flash('info', 'Cuenta creada. Contraseña temporal: ' . $result['plain_password']);
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

        $items = (new PurchaseRepository())->items((int) $purchase['id']);
        $openpayPdf = null;
        if (!empty($purchase['openpay_charge_id']) && $this->openPayConfigured()) {
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

    private function openPayConfigured(): bool
    {
        $m = trim((string) (Env::get('OPENPAY_MERCHANT_ID', '') ?? ''));
        $k = trim((string) (Env::get('OPENPAY_PRIVATE_KEY', '') ?? ''));

        return $m !== '' && $k !== '';
    }
}

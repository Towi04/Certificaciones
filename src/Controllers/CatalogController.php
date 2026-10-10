<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ComboRepository;
use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PartnerRepository;
use App\Services\CatalogFilterService;
use App\Services\ComboCatalogPresenter;
use App\Services\PartnerDirectoryService;
use App\Services\PartnerRegistrationService;
use App\Services\PricingService;
use App\Support\Pagination;

final class CatalogController
{
    public function home(): void
    {
        $repo = new ProductRepository();
        $comboRepo = new ComboRepository();
        $presenter = new ComboCatalogPresenter($comboRepo);
        $stars = [];
        $products = [];
        $pagination = null;
        $dbOk = true;
        $sectionCounts = ['certificaciones' => 0, 'cursos' => 0, 'combos' => 0];
        $section = 'certificaciones';
        $sort = 'relevantes';
        $catalogFilters = [];
        try {
            $section = ProductRepository::normalizeCatalogSection(
                is_string($_GET['seccion'] ?? null) ? (string) $_GET['seccion'] : 'certificaciones'
            );
            if ($section === 'all') {
                $section = 'certificaciones';
            }
            $filter = $_GET['filtro'] ?? $_GET['categoria'] ?? 'all';
            $filter = is_string($filter) ? $filter : 'all';
            $q = $_GET['q'] ?? null;
            $q = is_string($q) ? $q : null;
            $sort = ProductRepository::normalizeCatalogSort(
                is_string($_GET['orden'] ?? null) ? (string) $_GET['orden'] : 'relevantes'
            );

            $sectionCounts['certificaciones'] = $repo->publicCatalogCount('all', null, false, 'certificaciones');
            $sectionCounts['cursos'] = $repo->publicCatalogCount('all', null, false, 'cursos');
            $sectionCounts['combos'] = $comboRepo->publicCatalogCount(null, false);

            if ($section === 'combos') {
                // Filtros laterales de producto no aplican a combos.
                $filter = 'all';
                $stars = $presenter->presentCards($comboRepo->starCombos(null));
                $total = $comboRepo->publicCatalogCount($q, false);
                $pagination = Pagination::fromRequest($total, 'all');
                $products = $presenter->presentCards($comboRepo->publicCatalog(
                    $q,
                    false,
                    $pagination['limit'],
                    $pagination['offset'],
                    $sort
                ));
                $catalogFilters = [];
            } else {
                $stars = $repo->starProducts(null, $section);
                $total = $repo->publicCatalogCount($filter, $q, false, $section);
                $pagination = Pagination::fromRequest($total, 'all');
                $products = $repo->publicCatalog(
                    $filter,
                    $q,
                    false,
                    $pagination['limit'],
                    $pagination['offset'],
                    $section,
                    $sort
                );
                $catalogFilters = (new CatalogFilterService())->catalogFilters($section);
            }
        } catch (\Throwable $e) {
            $dbOk = false;
            $catalogFilters = [];
            $section = 'certificaciones';
            $sort = 'relevantes';
            error_log('[Doceo] Catalog: ' . $e->getMessage());
        }

        $user = Auth::user();
        $partner = null;
        if (($user['role'] ?? '') === 'partner') {
            try {
                $partner = (new PartnerRegistrationService())->partnerForUser((int) Auth::id());
                $pricing = new PricingService();
                $annotate = static function (array $list) use ($pricing, $partner): array {
                    $out = [];
                    foreach ($list as $p) {
                        $p['partner_price'] = $pricing->partnerPriceForProduct($p, (string) $partner['tier']);
                        $out[] = $p;
                    }

                    return $out;
                };
                if (in_array($sort ?? 'relevantes', ['precio_asc', 'precio_desc'], true)
                    && ($pagination['limit'] ?? null) === null
                ) {
                    $products = $annotate($products);
                    $dir = ($sort === 'precio_desc') ? -1 : 1;
                    usort($products, static function (array $a, array $b) use ($dir): int {
                        $pa = (float) ($a['partner_price'] ?? 0);
                        $pb = (float) ($b['partner_price'] ?? 0);

                        return $pa === $pb ? 0 : ($pa < $pb ? -1 * $dir : 1 * $dir);
                    });
                } elseif (in_array($sort ?? 'relevantes', ['precio_asc', 'precio_desc'], true)) {
                    if (($section ?? '') === 'combos') {
                        $all = $presenter->presentCards($comboRepo->publicCatalog($q, false, null, null, 'relevantes'));
                    } else {
                        $all = $repo->publicCatalog($filter, $q, false, null, null, $section ?? 'certificaciones', 'relevantes');
                    }
                    $all = $annotate($all);
                    $dir = ($sort === 'precio_desc') ? -1 : 1;
                    usort($all, static function (array $a, array $b) use ($dir): int {
                        $pa = (float) ($a['partner_price'] ?? 0);
                        $pb = (float) ($b['partner_price'] ?? 0);

                        return $pa === $pb ? 0 : ($pa < $pb ? -1 * $dir : 1 * $dir);
                    });
                    $offset = (int) ($pagination['offset'] ?? 0);
                    $limit = $pagination['limit'];
                    $products = $limit === null ? $all : array_slice($all, $offset, (int) $limit);
                } else {
                    $products = $annotate($products);
                }
                $stars = $annotate($stars);
            } catch (\Throwable) {
                $partner = null;
            }
        }

        $distributors = [];
        try {
            $distributors = (new PartnerDirectoryService())->publicCards();
        } catch (\Throwable $e) {
            error_log('[Doceo] partner directory public: ' . $e->getMessage());
        }

        $referralPartner = null;
        $rawPartnerCode = $_GET['partner'] ?? $_GET['codigo'] ?? null;
        if (is_string($rawPartnerCode) && trim($rawPartnerCode) !== '') {
            try {
                $referralPartner = (new PartnerRepository())->findActiveByCode(trim($rawPartnerCode));
                if ($referralPartner) {
                    if (session_status() !== PHP_SESSION_ACTIVE) {
                        session_start();
                    }
                    $_SESSION['partner_referral_code'] = strtoupper((string) $referralPartner['code']);
                    $_SESSION['partner_referral_name'] = (string) ($referralPartner['display_name'] ?? '');
                }
            } catch (\Throwable $e) {
                error_log('[Doceo] partner referral: ' . $e->getMessage());
            }
        } elseif (!empty($_SESSION['partner_referral_code'])) {
            $referralPartner = [
                'code' => (string) $_SESSION['partner_referral_code'],
                'display_name' => (string) ($_SESSION['partner_referral_name'] ?? $_SESSION['partner_referral_code']),
            ];
        }

        $title = match ($section ?? 'certificaciones') {
            'cursos' => 'Cursos',
            'combos' => 'Combos',
            default => 'Certificaciones',
        };

        view('catalog/home', [
            'title' => $title,
            'stars' => $stars,
            'products' => $products,
            'pagination' => $pagination,
            'paginationPerPageOptions' => ['all' => 'Todas', '20' => '20', '40' => '40'],
            'catalogFilters' => $catalogFilters ?? [],
            'filter' => $_GET['filtro'] ?? $_GET['categoria'] ?? 'all',
            'q' => $_GET['q'] ?? '',
            'sort' => $sort ?? 'relevantes',
            'sortOptions' => ProductRepository::catalogSortOptions(),
            'section' => $section ?? 'certificaciones',
            'sectionCounts' => $sectionCounts,
            'dbOk' => $dbOk,
            'user' => $user,
            'partner' => $partner,
            'distributors' => $distributors,
            'referralPartner' => $referralPartner,
        ]);
    }

    public function show(string $slug): void
    {
        $repo = new ProductRepository();
        $product = $repo->findBySlug($slug);
        if (!$product || !(int) $product['is_active'] || !(int) $product['is_public']) {
            http_response_code(404);
            view('errors/404', ['title' => 'Producto no encontrado']);

            return;
        }

        $media = [];
        try {
            $media = (new ProductMediaRepository())->forProduct((int) $product['id'], true);
        } catch (\Throwable $e) {
            error_log('[Doceo] Product media: ' . $e->getMessage());
        }

        $user = Auth::user();
        $partner = null;
        $partnerPrice = null;
        if (($user['role'] ?? '') === 'partner') {
            try {
                $partner = (new PartnerRegistrationService())->partnerForUser((int) Auth::id());
                $partnerPrice = (new PricingService())->partnerPriceForProduct($product, (string) $partner['tier']);
            } catch (\Throwable) {
                $partner = null;
            }
        }

        view('catalog/show', [
            'title' => $product['name'],
            'product' => $product,
            'media' => $media,
            'user' => $user,
            'partner' => $partner,
            'partnerPrice' => $partnerPrice,
        ]);
    }

    public function showCombo(string $slug): void
    {
        $comboRepo = new ComboRepository();
        $combo = $comboRepo->findPublicBySlug($slug);
        if ($combo === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Paquete no encontrado']);

            return;
        }

        $presented = (new ComboCatalogPresenter($comboRepo))->presentCard($combo);
        $user = Auth::user();
        $partner = null;
        $partnerPrice = null;
        if (($user['role'] ?? '') === 'partner') {
            try {
                $partner = (new PartnerRegistrationService())->partnerForUser((int) Auth::id());
                $partnerPrice = (new PricingService())->partnerPriceForProduct($presented, (string) $partner['tier']);
            } catch (\Throwable) {
                $partner = null;
            }
        }

        $acquireSlug = (string) ($presented['acquire_slug'] ?? '');
        $ctaUrl = $acquireSlug !== ''
            ? url('/adquirir/' . rawurlencode($acquireSlug) . '?combo_id=' . (int) $presented['id'])
            : url('/catalogo?seccion=combos');

        view('catalog/combo_show', [
            'title' => $presented['name'],
            'combo' => $presented,
            'user' => $user,
            'partner' => $partner,
            'partnerPrice' => $partnerPrice,
            'ctaUrl' => $ctaUrl,
            'layout' => (($user['role'] ?? '') === 'partner') ? 'partner' : 'main',
        ]);
    }
}

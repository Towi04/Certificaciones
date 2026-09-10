<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use App\Services\CatalogFilterService;
use App\Services\PartnerRegistrationService;
use App\Services\PricingService;
use App\Support\Pagination;
use App\Support\Settings;

final class CatalogController
{
    public function home(): void
    {
        $repo = new ProductRepository();
        $stars = [];
        $products = [];
        $pagination = null;
        $dbOk = true;
        $sectionCounts = ['certificaciones' => 0, 'cursos' => 0];
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

            $sectionCounts['certificaciones'] = $repo->publicCatalogCount('all', null, false, 'certificaciones');
            $sectionCounts['cursos'] = $repo->publicCatalogCount('all', null, false, 'cursos');

            $stars = $repo->starProducts(null, $section);
            $total = $repo->publicCatalogCount($filter, $q, false, $section);
            // Por defecto mostrar todas: si solo salen 20 parece que no hay más.
            $pagination = Pagination::fromRequest($total, 'all');
            $products = $repo->publicCatalog(
                $filter,
                $q,
                false,
                $pagination['limit'],
                $pagination['offset'],
                $section
            );
            $catalogFilters = (new CatalogFilterService())->catalogFilters($section);
        } catch (\Throwable $e) {
            $dbOk = false;
            $catalogFilters = [];
            $section = 'certificaciones';
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
                $products = $annotate($products);
                $stars = $annotate($stars);
            } catch (\Throwable) {
                $partner = null;
            }
        }

        view('catalog/home', [
            'title' => $section === 'cursos' ? 'Cursos' : 'Certificaciones',
            'stars' => $stars,
            'products' => $products,
            'pagination' => $pagination,
            'paginationPerPageOptions' => ['all' => 'Todas', '20' => '20', '40' => '40'],
            'catalogFilters' => $catalogFilters ?? [],
            'filter' => $_GET['filtro'] ?? $_GET['categoria'] ?? 'all',
            'q' => $_GET['q'] ?? '',
            'section' => $section ?? 'certificaciones',
            'sectionCounts' => $sectionCounts,
            'dbOk' => $dbOk,
            'user' => $user,
            'partner' => $partner,
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
}


<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use App\Support\Settings;

final class CatalogController
{
    public function home(): void
    {
        $repo = new ProductRepository();
        $stars = [];
        $products = [];
        $dbOk = true;
        try {
            $stars = $repo->starProducts(8);
            $category = $_GET['categoria'] ?? 'all';
            $q = $_GET['q'] ?? null;
            $products = $repo->publicCatalog(is_string($category) ? $category : 'all', is_string($q) ? $q : null);
        } catch (\Throwable $e) {
            $dbOk = false;
            error_log('[Doceo] Catalog: ' . $e->getMessage());
        }

        view('catalog/home', [
            'title' => 'Catálogo',
            'stars' => $stars,
            'products' => $products,
            'category' => $_GET['categoria'] ?? 'all',
            'q' => $_GET['q'] ?? '',
            'dbOk' => $dbOk,
            'user' => Auth::user(),
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

        view('catalog/show', [
            'title' => $product['name'],
            'product' => $product,
            'media' => $media,
            'user' => Auth::user(),
        ]);
    }
}


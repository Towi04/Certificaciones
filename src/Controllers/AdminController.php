<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use App\Support\Settings;

final class AdminController
{
    public function dashboard(): void
    {
        Auth::requireRole(['admin']);
        $stats = [
            'products' => 0,
            'paid' => 0,
            'awaiting_payment' => 0,
            'waiting_admin' => 0,
        ];
        $upcoming = [];
        $queue = [];
        try {
            $stats['products'] = (new ProductRepository())->countActive();
            $purchases = new PurchaseRepository();
            $stats['paid'] = $purchases->countByStatus('paid');
            $stats['awaiting_payment'] = $purchases->countByStatus('awaiting_payment')
                + $purchases->countByStatus('payment_review');
            $track = new TrackingRepository();
            $queue = $track->waitingAdmin(10);
            $stats['waiting_admin'] = count($queue);
            $upcoming = $track->upcomingExams(14);
        } catch (\Throwable $e) {
            flash('error', 'Base de datos no lista: ejecuta bin/install.php — ' . $e->getMessage());
        }

        view('admin/dashboard', [
            'title' => 'Admin',
            'stats' => $stats,
            'queue' => $queue,
            'upcoming' => $upcoming,
            'layout' => 'admin',
        ]);
    }

    public function products(): void
    {
        Auth::requireRole(['admin']);
        $q = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : null;
        $products = (new ProductRepository())->adminList($q);
        view('admin/products', [
            'title' => 'Productos',
            'products' => $products,
            'q' => $q ?? '',
            'layout' => 'admin',
        ]);
    }

    public function master(): void
    {
        Auth::requireRole(['admin']);
        $filters = [
            'q' => isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : null,
            'status' => isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : null,
        ];
        $rows = (new PurchaseRepository())->masterList($filters);
        view('admin/master', [
            'title' => 'Tabla maestra',
            'rows' => $rows,
            'filters' => $filters,
            'layout' => 'admin',
        ]);
    }

    public function suppliers(): void
    {
        Auth::requireRole(['admin']);
        $suppliers = (new \App\Repositories\SupplierRepository())->all();
        view('admin/suppliers', [
            'title' => 'Proveedores',
            'suppliers' => $suppliers,
            'layout' => 'admin',
        ]);
    }

    public function health(): void
    {
        Auth::requireRole(['admin']);
        $checker = new \App\Integrations\HealthChecker();
        $results = $checker->runAll();
        view('admin/health', [
            'title' => 'Salud del sistema',
            'results' => $results,
            'layout' => 'admin',
        ]);
    }
}


<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database\Connection;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use App\Services\CheckoutService;
use App\Services\TrackingService;

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
        $paymentQueue = [];
        try {
            $stats['products'] = (new ProductRepository())->countActive();
            $purchases = new PurchaseRepository();
            $stats['paid'] = $purchases->countByStatus('paid');
            $stats['awaiting_payment'] = $purchases->countByStatus('awaiting_payment')
                + $purchases->countByStatus('payment_review');
            $paymentQueue = $purchases->awaitingPaymentList(10);
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
            'paymentQueue' => $paymentQueue,
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

    public function payments(): void
    {
        Auth::requireRole(['admin']);
        $rows = (new PurchaseRepository())->awaitingPaymentList(100);
        view('admin/payments', [
            'title' => 'Pagos por confirmar',
            'rows' => $rows,
            'layout' => 'admin',
        ]);
    }

    public function purchaseShow(string $id): void
    {
        Auth::requireRole(['admin']);
        $purchaseId = (int) $id;
        $repo = new PurchaseRepository();
        $purchase = $repo->detail($purchaseId);
        if ($purchase === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Compra no encontrada', 'layout' => 'admin']);

            return;
        }
        $items = $repo->items($purchaseId);
        $trackings = (new TrackingRepository())->forPurchase($purchaseId);
        $docs = Connection::get()->prepare(
            'SELECT * FROM documents WHERE purchase_id = ? ORDER BY created_at'
        );
        $docs->execute([$purchaseId]);

        view('admin/purchase', [
            'title' => 'Compra ' . $purchase['matricula'],
            'purchase' => $purchase,
            'items' => $items,
            'trackings' => $trackings,
            'documents' => $docs->fetchAll(),
            'layout' => 'admin',
        ]);
    }

    public function confirmPayment(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $purchaseId = (int) $id;
        try {
            (new CheckoutService())->confirmPayment($purchaseId, (int) Auth::id(), trim((string) ($_POST['notes'] ?? '')) ?: null);
            flash('success', 'Pago confirmado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/compras/' . $purchaseId);
    }

    public function paymentProof(string $id): void
    {
        Auth::requireRole(['admin']);
        $purchase = (new PurchaseRepository())->find((int) $id);
        if ($purchase === null || empty($purchase['payment_proof_path'])) {
            http_response_code(404);
            exit('Comprobante no encontrado');
        }

        $docs = new \App\Services\DocumentService();
        $path = $docs->absolutePath((string) $purchase['payment_proof_path']);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Archivo no disponible en disco');
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $name = basename((string) $purchase['payment_proof_path']);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function trackingShow(string $id): void
    {
        Auth::requireRole(['admin']);
        $svc = new TrackingService();
        $tracking = $svc->find((int) $id);
        if ($tracking === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Seguimiento no encontrado', 'layout' => 'admin']);

            return;
        }
        $pipelineId = (int) ($tracking['pipeline_template_id'] ?? 0);
        view('admin/tracking', [
            'title' => 'Caso ' . $tracking['matricula'],
            'tracking' => $tracking,
            'steps' => $pipelineId > 0 ? $svc->steps($pipelineId) : [],
            'logs' => $svc->logs((int) $tracking['id']),
            'documents' => $svc->documentsForTracking((int) $tracking['id']),
            'moodleConfigured' => \App\Services\MoodleEnrolmentService::isConfigured(),
            'layout' => 'admin',
        ]);
    }

    public function trackingAdvance(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            $note = trim((string) ($_POST['note'] ?? '')) ?: null;
            $to = trim((string) ($_POST['step_code'] ?? ''));
            $svc = new TrackingService();
            if ($to !== '') {
                $svc->setStep($trackingId, $to, (int) Auth::id(), $note);
                flash('success', 'Paso actualizado a ' . $to);
            } else {
                $code = $svc->advance($trackingId, (int) Auth::id(), $note);
                flash('success', 'Avanzó a ' . $code);
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingSyncMoodle(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            $sendEmail = !empty($_POST['send_email']);
            $result = (new \App\Services\MoodleEnrolmentService())->syncTracking(
                $trackingId,
                (int) Auth::id(),
                $sendEmail
            );
            if (!empty($result['skipped'])) {
                flash('info', 'Moodle omitido: ' . ($result['reason'] ?? ''));
            } elseif (!empty($result['ok'])) {
                flash(
                    'success',
                    'Moodle OK · usuario ' . ($result['username'] ?? '')
                    . (!empty($result['created_user']) ? ' (nuevo)' : ' (existente)')
                );
            } else {
                flash('error', 'Moodle: ' . ($result['reason'] ?? 'falló'));
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function documentApprove(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $docId = (int) $id;
        $svc = new TrackingService();
        $doc = $svc->findDocument($docId);
        try {
            $svc->approveDocument($docId, (int) Auth::id());
            flash('success', 'Documento aprobado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $tid = $doc['tracking_id'] ?? null;
        redirect($tid ? '/admin/seguimientos/' . $tid : '/admin');
    }

    public function documentReject(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $docId = (int) $id;
        $svc = new TrackingService();
        $doc = $svc->findDocument($docId);
        try {
            $svc->rejectDocument($docId, (int) Auth::id(), trim((string) ($_POST['reason'] ?? '')));
            flash('success', 'Documento rechazado. Se notificó al alumno.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $tid = $doc['tracking_id'] ?? null;
        redirect($tid ? '/admin/seguimientos/' . $tid : '/admin');
    }

    public function documentDownload(string $id): void
    {
        Auth::requireRole(['admin']);
        $svc = new TrackingService();
        $doc = $svc->findDocument((int) $id);
        if ($doc === null) {
            http_response_code(404);
            exit('No encontrado');
        }
        $path = $svc->absoluteDocumentPath($doc);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Archivo no disponible');
        }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename((string) $doc['original_name']) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
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
        $deployedAt = null;
        $stamp = BASE_PATH . '/storage/DEPLOYED_AT.txt';
        if (is_file($stamp)) {
            $deployedAt = trim((string) file_get_contents($stamp));
        }
        // Señal rápida: el fix de maestra usa partners.display_name
        $repoFile = BASE_PATH . '/src/Repositories/PurchaseRepository.php';
        $repoSrc = is_file($repoFile) ? (string) file_get_contents($repoFile) : '';
        $maestraFix = str_contains($repoSrc, 'p.display_name AS partner_name');
        view('admin/health', [
            'title' => 'Salud del sistema',
            'results' => $results,
            'deployedAt' => $deployedAt,
            'maestraFix' => $maestraFix,
            'layout' => 'admin',
        ]);
    }
}

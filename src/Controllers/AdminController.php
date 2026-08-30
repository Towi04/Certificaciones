<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database\Connection;
use App\Repositories\CertifierRepository;
use App\Repositories\ProductGroupRepository;
use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TrackingRepository;
use App\Services\CatalogFilterService;
use App\Services\CheckoutService;
use App\Services\CheckoutRequirements;
use App\Services\ExportService;
use App\Services\ImportService;
use App\Integrations\Mailer;
use App\Services\MailTemplateService;
use App\Services\ProductAdminService;
use App\Services\ExamScheduleService;
use App\Services\ProductMediaService;
use App\Services\TrackingService;
use App\Services\UksEletService;
use App\Support\Pagination;
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
        $repo = new ProductRepository();
        $pagination = Pagination::fromRequest($repo->adminCount($q));
        $products = $repo->adminList($q, $pagination['limit'], $pagination['offset']);
        $groupsCount = 0;
        try {
            $groupsCount = count((new ProductGroupRepository())->all());
        } catch (\Throwable $e) {
            // ignore
        }
        view('admin/products', [
            'title' => 'Productos',
            'products' => $products,
            'pagination' => $pagination,
            'q' => $q ?? '',
            'groupsCount' => $groupsCount,
            'layout' => 'admin',
        ]);
    }

    public function productCreateForm(): void
    {
        Auth::requireRole(['admin']);
        view('admin/product_form', $this->productFormData(null));
    }

    public function productCreate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $id = (new ProductAdminService())->createProduct($_POST);
            (new CatalogFilterService())->syncProductFilters($id, $_POST['catalog_filter_ids'] ?? []);
            flash('success', 'Producto creado. Ya puedes subir logo/galería y asignarlo al catálogo.');
            redirect('/admin/productos/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/productos/nuevo');
        }
    }

    public function productEdit(string $id): void
    {
        Auth::requireRole(['admin']);
        $product = (new ProductRepository())->find((int) $id);
        if ($product === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Producto no encontrado', 'layout' => 'admin']);

            return;
        }
        $media = [];
        try {
            $media = (new ProductMediaRepository())->forProduct((int) $product['id']);
        } catch (\Throwable $e) {
            error_log('[Doceo] Product media admin: ' . $e->getMessage());
        }

        $data = $this->productFormData($product);
        $data['media'] = $media;
        $data['title'] = 'Editar · ' . $product['name'];
        view('admin/product_form', $data);
    }

    public function productUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $productId = (int) $id;
        if ((new ProductRepository())->find($productId) === null) {
            flash('error', 'Producto no encontrado.');
            redirect('/admin/productos');
        }

        try {
            (new ProductAdminService())->updateProduct($productId, $_POST);
            (new CatalogFilterService())->syncProductFilters($productId, $_POST['catalog_filter_ids'] ?? []);
            flash('success', 'Producto actualizado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/productos/' . $productId);
    }


    public function combos(): void
    {
        Auth::requireRole(['admin']);
        view('admin/combos', [
            'title' => 'Combos',
            'combos' => (new \App\Repositories\ComboRepository())->all(),
            'layout' => 'admin',
        ]);
    }

    public function comboCreateForm(): void
    {
        Auth::requireRole(['admin']);
        $products = (new \App\Repositories\ProductRepository())->adminList();
        view('admin/combo_form', [
            'title' => 'Nuevo combo',
            'combo' => null,
            'products' => $products,
            'selectedIds' => [],
            'layout' => 'admin',
        ]);
    }

    public function comboCreate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $id = (new \App\Services\ComboAdminService())->create($_POST, $_POST['product_ids'] ?? []);
            flash('success', 'Combo creado. Ya aparecerá en el checkout de sus productos.');
            redirect('/admin/combos/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/combos/nuevo');
        }
    }

    public function comboEdit(string $id): void
    {
        Auth::requireRole(['admin']);
        $repo = new \App\Repositories\ComboRepository();
        $combo = $repo->find((int) $id);
        if ($combo === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Combo no encontrado', 'layout' => 'admin']);

            return;
        }
        view('admin/combo_form', [
            'title' => 'Combo · ' . $combo['name'],
            'combo' => $combo,
            'products' => (new \App\Repositories\ProductRepository())->adminList(),
            'selectedIds' => $repo->productIds((int) $combo['id']),
            'layout' => 'admin',
        ]);
    }

    public function comboUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $comboId = (int) $id;
        try {
            (new \App\Services\ComboAdminService())->update($comboId, $_POST, $_POST['product_ids'] ?? []);
            flash('success', 'Combo actualizado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/combos/' . $comboId);
    }

    public function comboDelete(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new \App\Services\ComboAdminService())->delete((int) $id);
            flash('success', 'Combo eliminado.');
            redirect('/admin/combos');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/combos/' . (int) $id);
        }
    }

    public function productGroups(): void
    {
        Auth::requireRole(['admin']);
        $repo = new ProductGroupRepository();
        $pagination = Pagination::fromRequest($repo->countAll());
        $groups = $repo->all($pagination['limit'], $pagination['offset']);
        $counts = [];
        foreach ($groups as $g) {
            $counts[(int) $g['id']] = $repo->countProducts((int) $g['id']);
        }
        view('admin/product_groups', [
            'title' => 'Grupos de producto',
            'groups' => $groups,
            'counts' => $counts,
            'pagination' => $pagination,
            'layout' => 'admin',
        ]);
    }

    public function productGroupCreateForm(): void
    {
        Auth::requireRole(['admin']);
        $defaultConfig = json_encode(
            ProductGroupRepository::defaultCheckoutConfig(true),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        view('admin/product_group_form', [
            'title' => 'Nuevo grupo de proceso',
            'group' => null,
            'suppliers' => (new SupplierRepository())->all(),
            'defaultConfig' => $defaultConfig,
            'usedDocCodes' => (new ProductAdminService())->usedReglamentoDocCodes(null),
            'extras' => ProductAdminService::groupFormExtrasFromConfig((string) $defaultConfig),
            'layout' => 'admin',
        ]);
    }

    public function productGroupCreate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $id = (new ProductAdminService())->createGroup($_POST);
            flash('success', 'Grupo creado. Ya puedes asignarlo a productos.');
            redirect('/admin/grupos/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/grupos/nuevo');
        }
    }

    public function productGroupEdit(string $id): void
    {
        Auth::requireRole(['admin']);
        $group = (new ProductGroupRepository())->find((int) $id);
        if ($group === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Grupo no encontrado', 'layout' => 'admin']);

            return;
        }
        $config = (string) ($group['config_json'] ?? '');
        if ($config !== '') {
            $decoded = json_decode($config, true);
            if (is_array($decoded)) {
                $config = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        }
        $defaultConfig = $config !== '' ? $config : json_encode(
            ProductGroupRepository::defaultCheckoutConfig(true),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        view('admin/product_group_form', [
            'usedDocCodes' => (new ProductAdminService())->usedReglamentoDocCodes((int) $id),
            'title' => 'Editar grupo · ' . $group['name'],
            'group' => $group,
            'suppliers' => (new SupplierRepository())->all(),
            'defaultConfig' => $defaultConfig,
            'extras' => ProductAdminService::groupFormExtrasFromConfig((string) ($group['config_json'] ?? '')),
            'layout' => 'admin',
        ]);
    }

    public function productGroupUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $groupId = (int) $id;
        try {
            (new ProductAdminService())->updateGroup($groupId, $_POST);
            flash('success', 'Grupo actualizado. Los productos del grupo heredan estos cambios.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/grupos/' . $groupId);
    }

    public function productGroupsSeed(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $log = (new ProductAdminService())->ensureSuggestedGroups();
            flash('success', 'Grupos sugeridos listos: ' . implode(' · ', $log));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/grupos');
    }

    /**
     * @param array<string, mixed>|null $product
     * @return array<string, mixed>
     */
    private function productFormData(?array $product): array
    {
        $filterSvc = new CatalogFilterService();
        $productId = $product !== null ? (int) $product['id'] : 0;

        return [
            'title' => $product ? ('Editar · ' . $product['name']) : 'Nuevo producto',
            'product' => $product,
            'groups' => (new ProductGroupRepository())->all(),
            'suppliers' => (new SupplierRepository())->all(),
            'certifiers' => (new CertifierRepository())->all(),
            'catalogFilters' => $filterSvc->adminFilters(),
            'selectedFilterIds' => $productId > 0 ? $filterSvc->productFilterIds($productId) : [],
            'typeOptions' => ProductAdminService::typeOptions(),
            'categoryOptions' => ProductAdminService::categoryOptions(),
            'audienceOptions' => ProductAdminService::audienceOptions(),
            'platformOptions' => ProductAdminService::platformOptions(),
            'cefrOptions' => ProductAdminService::cefrOptions(),
            'cenniOptions' => ProductAdminService::cenniOptions(),
            'levelExam' => ProductAdminService::levelExamFromConfig(
                $product !== null ? (string) ($product['config_json'] ?? '') : null
            ),
            'layout' => 'admin',
        ];
    }

    public function productLogoUpload(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $productId = (int) $id;
        try {
            $file = $_FILES['logo'] ?? null;
            if ($file === null || !is_array($file)) {
                throw new \InvalidArgumentException('Selecciona una imagen para el logo.');
            }
            (new ProductMediaService())->uploadLogo($productId, $file);
            flash('success', 'Logo del producto actualizado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/productos/' . $productId);
    }

    public function productMediaStore(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $productId = (int) $id;
        try {
            $service = new ProductMediaService();
            $youtubeUrl = trim((string) ($_POST['youtube_url'] ?? ''));
            if ($youtubeUrl !== '') {
                $service->addYoutubeVideo(
                    $productId,
                    $youtubeUrl,
                    trim((string) ($_POST['title'] ?? '')),
                    trim((string) ($_POST['caption'] ?? '')),
                    (int) ($_POST['sort_order'] ?? 0),
                    !empty($_POST['is_active'])
                );
                flash('success', 'Video de YouTube agregado al producto.');
                redirect('/admin/productos/' . $productId);
            }

            $file = $_FILES['media_file'] ?? null;
            if ($file === null || !is_array($file)) {
                throw new \InvalidArgumentException('Sube una imagen o pega un link de YouTube.');
            }
            $service->addMedia(
                $productId,
                $file,
                trim((string) ($_POST['title'] ?? '')),
                trim((string) ($_POST['caption'] ?? '')),
                (int) ($_POST['sort_order'] ?? 0),
                !empty($_POST['is_active'])
            );
            flash('success', 'Imagen agregada al producto.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/productos/' . $productId);
    }

    public function productMediaDelete(string $id, string $mediaId): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $productId = (int) $id;
        try {
            (new ProductMediaService())->deleteMedia($productId, (int) $mediaId);
            flash('success', 'Multimedia eliminada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/productos/' . $productId);
    }

    public function productMediaUpdate(string $id, string $mediaId): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $productId = (int) $id;
        try {
            (new ProductMediaService())->updateMedia(
                $productId,
                (int) $mediaId,
                trim((string) ($_POST['title'] ?? '')),
                trim((string) ($_POST['caption'] ?? '')),
                (int) ($_POST['sort_order'] ?? 0),
                !empty($_POST['is_active']),
                isset($_FILES['media_file']) && is_array($_FILES['media_file']) ? $_FILES['media_file'] : null
            );
            flash('success', 'Multimedia actualizada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/productos/' . $productId);
    }

    public function master(): void
    {
        Auth::requireRole(['admin']);
        $filters = [
            'q' => isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : null,
            'status' => isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : null,
        ];
        $repo = new PurchaseRepository();
        $pagination = Pagination::fromRequest($repo->masterCount($filters));
        $rows = $repo->masterList($filters, $pagination['limit'], $pagination['offset']);
        view('admin/master', [
            'title' => 'Tabla maestra',
            'rows' => $rows,
            'filters' => $filters,
            'pagination' => $pagination,
            'layout' => 'admin',
        ]);
    }

    public function masterExport(): void
    {
        Auth::requireRole(['admin']);
        $filters = [
            'q' => isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : null,
            'status' => isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : null,
            'date_from' => isset($_GET['date_from']) && is_string($_GET['date_from']) ? trim($_GET['date_from']) : null,
            'date_to' => isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : null,
        ];
        if ($filters['date_from'] === '') {
            $filters['date_from'] = null;
        }
        if ($filters['date_to'] === '') {
            $filters['date_to'] = null;
        }

        $rows = (new PurchaseRepository())->masterList($filters);
        $filename = 'tabla-maestra-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fputcsv($out, [
            'Matrícula', 'Nombre', 'Apellido paterno', 'Apellido materno', 'Email', 'Teléfono',
            'Partner código', 'Partner nombre', 'Monto', 'Moneda', 'Estatus', 'Método pago', 'Creado',
        ]);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['matricula'] ?? '',
                $r['first_name'] ?? '',
                $r['last_name_p'] ?? '',
                $r['last_name_m'] ?? '',
                $r['student_email'] ?? '',
                $r['student_phone'] ?? '',
                $r['partner_code'] ?? '',
                $r['partner_name'] ?? '',
                $r['charged_amount'] ?? '',
                $r['currency'] ?? '',
                $r['status'] ?? '',
                $r['payment_method'] ?? '',
                $r['created_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    public function payments(): void
    {
        Auth::requireRole(['admin']);
        $repo = new PurchaseRepository();
        $pagination = Pagination::fromRequest($repo->awaitingPaymentCount());
        $rows = $repo->awaitingPaymentList($pagination['limit'], $pagination['offset']);
        view('admin/payments', [
            'title' => 'Pagos por confirmar',
            'rows' => $rows,
            'pagination' => $pagination,
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
        $productCfg = CheckoutRequirements::config($tracking);
        $uksReport = \App\Services\ImportService::uksReportFromTracking($tracking);
        $uksElet = new UksEletService();
        $isEletUks = $uksElet->isEletUksTracking($tracking);
        $accessKeyHint = null;
        if ($isEletUks && !empty($tracking['exam_date'])) {
            $accessKeyHint = $uksElet->accessKeyHintForDate((string) $tracking['exam_date'], (int) $tracking['id']);
        }
        view('admin/tracking', [
            'title' => 'Caso ' . $tracking['matricula'],
            'tracking' => $tracking,
            'steps' => $pipelineId > 0 ? $svc->steps($pipelineId) : [],
            'logs' => $svc->logs((int) $tracking['id']),
            'documents' => $svc->documentsForTracking((int) $tracking['id']),
            'moodleConfigured' => \App\Services\MoodleEnrolmentService::isConfigured(),
            'exportTemplateCode' => $productCfg['export_template_code'] ?? null,
            'importTemplateCode' => $productCfg['import_template_code'] ?? null,
            'uksReport' => $uksReport,
            'isEletUks' => $isEletUks,
            'eletExamUrl' => $uksElet->examUrl(),
            'accessKeyHint' => $accessKeyHint,
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

    public function trackingUpdateExam(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            (new TrackingService())->saveExamSchedule($trackingId, [
                'exam_date' => $_POST['exam_date'] ?? null,
                'exam_time' => $_POST['exam_time'] ?? null,
                'exam_date_2' => $_POST['exam_date_2'] ?? null,
                'exam_time_2' => $_POST['exam_time_2'] ?? null,
                'zoom_url' => $_POST['zoom_url'] ?? null,
                'notify' => !empty($_POST['notify']),
            ], (int) Auth::id());
            flash('success', 'Fecha de examen guardada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingPublishEletAccess(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            (new UksEletService())->publishExamAccess(
                $trackingId,
                trim((string) ($_POST['folio'] ?? '')),
                trim((string) ($_POST['access_key'] ?? '')),
                (int) Auth::id(),
                !empty($_POST['notify'])
            );
            flash('success', 'Accesos publicados y alumno notificado por correo.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingResendUksRequest(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        $svc = new TrackingService();
        $tracking = $svc->find($trackingId);
        if ($tracking === null) {
            flash('error', 'Seguimiento no encontrado.');
            redirect('/admin');
        }
        try {
            $uks = new UksEletService();
            if (($tracking['current_step_code'] ?? '') !== 'solicitud_uks') {
                $svc->setStep(
                    $trackingId,
                    'solicitud_uks',
                    (int) Auth::id(),
                    'Solicitud UKS (manual)',
                    'waiting_provider'
                );
            }
            $includeProof = !empty($_POST['include_payment_proof']);
            $uks->sendSolicitudEmail($trackingId, (int) $tracking['purchase_id'], $includeProof);
            $msg = 'Correo enviado a UKS (reglamento firmado';
            $msg .= $includeProof ? ' + comprobante)' : ')';
            if (($tracking['current_step_code'] ?? '') !== 'solicitud_uks') {
                $msg .= ' · caso en solicitud UKS';
            }
            flash('success', $msg);
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

    public function partners(): void
    {
        Auth::requireRole(['admin']);
        $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $repo = new \App\Repositories\PartnerRepository();
        $pagination = Pagination::fromRequest($repo->countAll($q !== '' ? $q : null));
        $partners = $repo->adminList($q !== '' ? $q : null, $pagination['limit'], $pagination['offset']);
        view('admin/partners', [
            'title' => 'Partners',
            'partners' => $partners,
            'pagination' => $pagination,
            'q' => $q,
            'tierLabels' => \App\Services\PartnerAdminService::tierLabels(),
            'layout' => 'admin',
        ]);
    }

    public function partnerCreateForm(): void
    {
        Auth::requireRole(['admin']);
        view('admin/partner_form', [
            'title' => 'Nuevo partner',
            'partner' => null,
            'tierLabels' => \App\Services\PartnerAdminService::tierLabels(),
            'layout' => 'admin',
        ]);
    }

    public function partnerCreate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $result = (new \App\Services\PartnerAdminService())->create([
                'email' => (string) ($_POST['email'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
                'first_name' => (string) ($_POST['first_name'] ?? ''),
                'last_name_p' => (string) ($_POST['last_name_p'] ?? ''),
                'last_name_m' => (string) ($_POST['last_name_m'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'code' => (string) ($_POST['code'] ?? ''),
                'display_name' => (string) ($_POST['display_name'] ?? ''),
                'tier' => (string) ($_POST['tier'] ?? 'c'),
                'notes' => (string) ($_POST['notes'] ?? ''),
                'is_active' => !empty($_POST['is_active']),
                'must_change_password' => !empty($_POST['must_change_password']),
                'send_email' => !empty($_POST['send_email']),
            ]);
            $msg = 'Partner creado. Contraseña temporal: ' . $result['plain_password'];
            if (!empty($result['email_sent'])) {
                $msg .= ' · Correo de acceso enviado.';
            } elseif (!empty($_POST['send_email'])) {
                $msg .= ' · No se pudo enviar el correo'
                    . (!empty($result['email_error']) ? ': ' . $result['email_error'] : '.')
                    . ' Comparte la contraseña manualmente.';
            } else {
                $msg .= ' · Correo no solicitado; guárdala y compártela.';
            }
            flash('success', $msg);
            redirect('/admin/partners/' . $result['partner_id']);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/partners/nuevo');
        }
    }

    public function partnerEdit(string $id): void
    {
        Auth::requireRole(['admin']);
        $partner = (new \App\Repositories\PartnerRepository())->find((int) $id);
        if ($partner === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Partner no encontrado', 'layout' => 'admin']);

            return;
        }
        view('admin/partner_form', [
            'title' => 'Editar partner',
            'partner' => $partner,
            'tierLabels' => \App\Services\PartnerAdminService::tierLabels(),
            'layout' => 'admin',
        ]);
    }

    public function partnerUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $partnerId = (int) $id;
        try {
            $result = (new \App\Services\PartnerAdminService())->update($partnerId, [
                'email' => (string) ($_POST['email'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
                'first_name' => (string) ($_POST['first_name'] ?? ''),
                'last_name_p' => (string) ($_POST['last_name_p'] ?? ''),
                'last_name_m' => (string) ($_POST['last_name_m'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'code' => (string) ($_POST['code'] ?? ''),
                'display_name' => (string) ($_POST['display_name'] ?? ''),
                'tier' => (string) ($_POST['tier'] ?? 'c'),
                'notes' => (string) ($_POST['notes'] ?? ''),
                'is_active' => !empty($_POST['is_active']),
                'must_change_password' => !empty($_POST['must_change_password']),
                'send_email' => !empty($_POST['send_email']),
            ]);
            $msg = 'Partner actualizado.';
            if (!empty($result['plain_password'])) {
                $msg .= ' Nueva contraseña: ' . $result['plain_password'];
                if (!empty($result['email_sent'])) {
                    $msg .= ' · Correo enviado.';
                } elseif (!empty($_POST['send_email'])) {
                    $msg .= ' · No se pudo enviar el correo'
                        . (!empty($result['email_error']) ? ': ' . $result['email_error'] : '.');
                }
            }
            flash('success', $msg);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/partners/' . $partnerId);
    }

    public function partnerResendAccess(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $partnerId = (int) $id;
        try {
            $password = trim((string) ($_POST['password'] ?? ''));
            $result = (new \App\Services\PartnerAdminService())->resetPasswordAndEmail(
                $partnerId,
                $password !== '' ? $password : null
            );
            $msg = 'Contraseña temporal: ' . $result['plain_password'];
            if (!empty($result['email_sent'])) {
                $msg = 'Correo de acceso reenviado. ' . $msg;
                flash('success', $msg);
            } else {
                flash(
                    'error',
                    'No se pudo enviar el correo'
                    . (!empty($result['email_error']) ? ': ' . $result['email_error'] : '.')
                    . ' ' . $msg
                );
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/partners/' . $partnerId);
    }

    public function suppliers(): void
    {
        Auth::requireRole(['admin']);
        $repo = new SupplierRepository();
        $pagination = Pagination::fromRequest($repo->countAll());
        $suppliers = $repo->all($pagination['limit'], $pagination['offset']);
        $counts = [];
        foreach ($suppliers as $s) {
            $sid = (int) $s['id'];
            $counts[$sid] = [
                'products' => $repo->countProducts($sid),
                'groups' => $repo->countGroups($sid),
            ];
        }
        view('admin/suppliers', [
            'title' => 'Proveedores',
            'suppliers' => $suppliers,
            'counts' => $counts,
            'pagination' => $pagination,
            'layout' => 'admin',
        ]);
    }

    public function supplierCreateForm(): void
    {
        Auth::requireRole(['admin']);
        view('admin/supplier_form', [
            'title' => 'Nuevo proveedor',
            'supplier' => null,
            'layout' => 'admin',
        ]);
    }

    public function supplierCreate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $id = (new \App\Services\SupplierAdminService())->create($_POST);
            flash('success', 'Proveedor creado.');
            redirect('/admin/proveedores/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/proveedores/nuevo');
        }
    }

    public function supplierShow(string $id): void
    {
        Auth::requireRole(['admin']);
        $repo = new SupplierRepository();
        $supplier = $repo->find((int) $id);
        if ($supplier === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Proveedor no encontrado', 'layout' => 'admin']);

            return;
        }
        $sid = (int) $supplier['id'];
        $allGroups = (new ProductGroupRepository())->all();
        $groups = array_values(array_filter(
            $allGroups,
            static fn (array $g): bool => (int) ($g['supplier_id'] ?? 0) === $sid
        ));
        $products = array_values(array_filter(
            (new ProductRepository())->adminList(),
            static fn (array $p): bool => (int) ($p['supplier_id'] ?? 0) === $sid
        ));
        $products = array_slice($products, 0, 40);
        $revealedPasswords = [];
        $revealed = flash('_revealed_account');
        if (is_array($revealed) && isset($revealed['id'])) {
            $revealedPasswords[(int) $revealed['id']] = (string) ($revealed['password'] ?? '');
        }
        view('admin/supplier_show', [
            'title' => 'Proveedor · ' . $supplier['name'],
            'supplier' => $supplier,
            'groups' => $groups,
            'products' => $products,
            'contacts' => $repo->contacts($sid),
            'accounts' => $repo->accounts($sid),
            'revealedPasswords' => $revealedPasswords,
            'productCount' => $repo->countProducts($sid),
            'groupCount' => $repo->countGroups($sid),
            'layout' => 'admin',
        ]);
    }

    public function supplierUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        try {
            (new \App\Services\SupplierAdminService())->update($supplierId, $_POST);
            flash('success', 'Proveedor actualizado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId);
    }

    public function supplierLogo(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        $svc = new \App\Services\SupplierAdminService();
        try {
            if (!empty($_POST['remove_logo'])) {
                $svc->clearLogo($supplierId);
                flash('success', 'Logo eliminado.');
            } else {
                $file = $_FILES['logo'] ?? null;
                if (!is_array($file)) {
                    throw new \InvalidArgumentException('Selecciona una imagen de logo.');
                }
                $svc->uploadLogo($supplierId, $file);
                flash('success', 'Logo actualizado.');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#general');
    }

    public function supplierContactCreate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        try {
            (new \App\Services\SupplierAdminService())->addContact($supplierId, $_POST);
            flash('success', 'Contacto agregado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#contacts');
    }

    public function supplierContactDelete(string $id, string $contactId): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        try {
            (new \App\Services\SupplierAdminService())->deleteContact($supplierId, (int) $contactId);
            flash('success', 'Contacto eliminado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#contacts');
    }

    public function supplierAccountCreate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        try {
            (new \App\Services\SupplierAdminService())->addAccount($supplierId, $_POST);
            flash('success', 'Acceso a plataforma guardado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#accounts');
    }

    public function supplierAccountUpdate(string $id, string $accountId): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        try {
            (new \App\Services\SupplierAdminService())->updateAccount($supplierId, (int) $accountId, $_POST);
            flash('success', 'Acceso actualizado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#accounts');
    }

    public function supplierAccountDelete(string $id, string $accountId): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        try {
            (new \App\Services\SupplierAdminService())->deleteAccount($supplierId, (int) $accountId);
            flash('success', 'Acceso eliminado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#accounts');
    }

    public function supplierAccountReveal(string $id, string $accountId): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        $aid = (int) $accountId;
        try {
            $password = (new \App\Services\SupplierAdminService())->revealAccountPassword($supplierId, $aid);
            flash('_revealed_account', ['id' => $aid, 'password' => $password]);
            flash('info', 'Contraseña revelada abajo (solo en esta pantalla).');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#accounts');
    }

    public function supplierDelete(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new \App\Services\SupplierAdminService())->delete((int) $id);
            flash('success', 'Proveedor eliminado.');
            redirect('/admin/proveedores');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/proveedores/' . (int) $id);
        }
    }

    public function certifiers(): void
    {
        Auth::requireRole(['admin']);
        $repo = new CertifierRepository();
        $pagination = Pagination::fromRequest($repo->countAll());
        $certifiers = $repo->all($pagination['limit'], $pagination['offset']);
        $counts = [];
        foreach ($certifiers as $c) {
            $counts[(int) $c['id']] = $repo->countProducts((int) $c['id']);
        }
        view('admin/certifiers', [
            'title' => 'Casas certificadoras',
            'certifiers' => $certifiers,
            'counts' => $counts,
            'pagination' => $pagination,
            'layout' => 'admin',
        ]);
    }

    public function certifierCreateForm(): void
    {
        Auth::requireRole(['admin']);
        view('admin/certifier_form', [
            'title' => 'Nueva certificadora',
            'certifier' => null,
            'layout' => 'admin',
        ]);
    }

    public function certifierCreate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $id = (new \App\Services\CertifierAdminService())->create($_POST);
            flash('success', 'Certificadora creada.');
            redirect('/admin/certificadoras/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/certificadoras/nueva');
        }
    }

    public function certifierShow(string $id): void
    {
        Auth::requireRole(['admin']);
        $repo = new CertifierRepository();
        $certifier = $repo->find((int) $id);
        if ($certifier === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Certificadora no encontrada', 'layout' => 'admin']);

            return;
        }
        $cid = (int) $certifier['id'];
        $products = array_values(array_filter(
            (new ProductRepository())->adminList(),
            static fn (array $p): bool => (int) ($p['certifier_id'] ?? 0) === $cid
        ));
        $products = array_slice($products, 0, 40);
        view('admin/certifier_show', [
            'title' => 'Certificadora · ' . $certifier['name'],
            'certifier' => $certifier,
            'products' => $products,
            'productCount' => $repo->countProducts($cid),
            'layout' => 'admin',
        ]);
    }

    public function certifierUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $certifierId = (int) $id;
        try {
            (new \App\Services\CertifierAdminService())->update($certifierId, $_POST);
            flash('success', 'Certificadora actualizada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/certificadoras/' . $certifierId);
    }

    public function certifierLogo(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $certifierId = (int) $id;
        $svc = new \App\Services\CertifierAdminService();
        try {
            if (!empty($_POST['remove_logo'])) {
                $svc->clearLogo($certifierId);
                flash('success', 'Logo eliminado.');
            } else {
                $file = $_FILES['logo'] ?? null;
                if (!is_array($file)) {
                    throw new \InvalidArgumentException('Selecciona una imagen de logo.');
                }
                $svc->uploadLogo($certifierId, $file);
                flash('success', 'Logo actualizado.');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/certificadoras/' . $certifierId);
    }

    public function certifierDelete(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new \App\Services\CertifierAdminService())->delete((int) $id);
            flash('success', 'Certificadora eliminada.');
            redirect('/admin/certificadoras');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/certificadoras/' . (int) $id);
        }
    }

    public function supplierBulkProducts(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Selecciona un archivo CSV válido.');
            redirect('/admin/proveedores/' . $supplierId);

            return;
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        if (!str_ends_with($name, '.csv')) {
            flash('error', 'El archivo debe ser CSV.');
            redirect('/admin/proveedores/' . $supplierId);

            return;
        }
        $groupId = !empty($_POST['product_group_id']) ? (int) $_POST['product_group_id'] : null;
        try {
            $result = (new ProductAdminService())->importProductsFromCsv(
                (string) $file['tmp_name'],
                $groupId,
                $supplierId
            );
            $msg = 'Certificaciones creadas: ' . $result['created'] . '. Omitidas: ' . $result['skipped'] . '.';
            if ($result['errors'] !== []) {
                $msg .= ' ' . implode(' ', array_slice($result['errors'], 0, 5));
                flash('error', $msg);
            } else {
                flash('success', $msg);
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId);
    }

    public function supplierBulkTemplate(string $id): void
    {
        Auth::requireRole(['admin']);
        if ((new SupplierRepository())->find((int) $id) === null) {
            http_response_code(404);
            echo 'Proveedor no encontrado';

            return;
        }
        (new ProductAdminService())->sendProductBulkTemplateCsv();
    }

    public function prices(): void
    {
        Auth::requireRole(['admin']);
        $filterSupplierId = !empty($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
        $repo = new ProductRepository();
        $allProducts = $repo->adminList();
        if ($filterSupplierId !== null) {
            $allProducts = array_values(array_filter(
                $allProducts,
                static fn (array $p): bool => (int) ($p['supplier_id'] ?? 0) === $filterSupplierId
            ));
        }
        $pagination = Pagination::fromRequest(count($allProducts));
        $products = array_slice($allProducts, $pagination['offset'], $pagination['limit']);
        view('admin/prices', [
            'title' => 'Precios masivos',
            'products' => $products,
            'pagination' => $pagination,
            'suppliers' => (new SupplierRepository())->all(),
            'filterSupplierId' => $filterSupplierId,
            'layout' => 'admin',
        ]);
    }

    public function pricesSave(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $rows = $_POST['prices'] ?? [];
        if (!is_array($rows)) {
            flash('error', 'No se recibieron precios.');
            redirect('/admin/precios');

            return;
        }
        try {
            $n = (new ProductAdminService())->updatePricesBulk($rows);
            flash('success', "Precios actualizados: {$n} producto(s).");
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $qs = !empty($_POST['supplier_id']) ? ('?supplier_id=' . (int) $_POST['supplier_id']) : '';
        redirect('/admin/precios' . $qs);
    }

    public function pricesTemplate(): void
    {
        Auth::requireRole(['admin']);
        $filterSupplierId = !empty($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
        $products = (new ProductRepository())->adminList();
        if ($filterSupplierId !== null) {
            $products = array_values(array_filter(
                $products,
                static fn (array $p): bool => (int) ($p['supplier_id'] ?? 0) === $filterSupplierId
            ));
        }
        (new ProductAdminService())->sendPriceTemplateCsv($products);
    }

    public function pricesImport(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Selecciona un archivo CSV válido.');
            redirect('/admin/precios');

            return;
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        if (!str_ends_with($name, '.csv')) {
            flash('error', 'El archivo debe ser CSV.');
            redirect('/admin/precios');

            return;
        }
        try {
            $result = (new ProductAdminService())->importPricesFromCsv((string) $file['tmp_name']);
            $msg = 'Precios actualizados: ' . $result['updated'] . '. Omitidos: ' . $result['skipped'] . '.';
            if ($result['errors'] !== []) {
                $msg .= ' ' . implode(' ', array_slice($result['errors'], 0, 5));
                flash('error', $msg);
            } else {
                flash('success', $msg);
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/precios');
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

    public function exports(): void
    {
        Auth::requireRole(['admin']);
        $templates = (new \App\Repositories\ExportTemplateRepository())->listActive();
        $importTemplates = (new \App\Repositories\ImportTemplateRepository())->listActive();
        view('admin/exports', [
            'title' => 'Exportaciones UKS',
            'templates' => $templates,
            'importTemplates' => $importTemplates,
            'layout' => 'admin',
        ]);
    }

    public function exportDownload(string $code): void
    {
        Auth::requireRole(['admin']);
        $options = [];
        if (!empty($_GET['tracking_id'])) {
            $options['tracking_id'] = (int) $_GET['tracking_id'];
        }
        if (!empty($_GET['exam_date']) && is_string($_GET['exam_date'])) {
            $options['exam_date'] = trim($_GET['exam_date']);
        }
        if (!empty($_GET['all_paid'])) {
            $options['step_codes'] = [];
        }

        try {
            (new ExportService())->sendDownload($code, $options);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/exportaciones');
        }
    }

    public function importUpload(string $code): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();

        $file = $_FILES['csv_file'] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Selecciona un archivo CSV válido.');
            redirect('/admin/exportaciones');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            flash('error', 'El archivo debe ser CSV.');
            redirect('/admin/exportaciones');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_readable($tmp)) {
            flash('error', 'No se pudo leer el archivo subido.');
            redirect('/admin/exportaciones');
        }

        try {
            $result = (new ImportService())->importUksReport($code, $tmp, (int) Auth::id());
            $msg = sprintf(
                'Importación completada: %d filas, %d actualizadas, %d omitidas.',
                $result['processed'],
                $result['updated'],
                $result['skipped']
            );
            if ($result['notifications'] !== []) {
                $msg .= ' Avisos: ' . implode('; ', array_slice($result['notifications'], 0, 5));
            }
            if ($result['errors'] !== []) {
                flash('info', $msg);
                flash('error', 'Errores: ' . implode(' | ', array_slice($result['errors'], 0, 5)));
            } else {
                flash('success', $msg);
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/exportaciones');
    }

    
    public function vacations(): void
    {
        Auth::requireRole(['admin']);
        $dates = ExamScheduleService::globalVacationDates();
        view('admin/vacations', [
            'title' => 'Vacaciones globales',
            'dates' => $dates,
            'raw' => implode("\n", $dates),
            'layout' => 'admin',
        ]);
    }

    public function vacationsSave(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            ExamScheduleService::saveGlobalVacationDates((string) ($_POST['vacation_dates'] ?? ''));
            flash('success', 'Vacaciones globales guardadas.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/vacaciones');
    }

public function promoCode(): void
    {
        Auth::requireRole(['admin']);
        $pdo = Connection::get();
        $currentCode = Settings::get('doceo_promo_code', 'DOCEO26') ?? 'DOCEO26';
        $stmt = $pdo->prepare(
            'SELECT * FROM discount_codes WHERE type = ? AND is_active = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['promo_doceo']);
        $active = $stmt->fetch() ?: null;

        view('admin/promo_code', [
            'title' => 'Código promocional DOCEO',
            'currentCode' => $active ? (string) $active['code'] : $currentCode,
            'active' => $active,
            'layout' => 'admin',
        ]);
    }

    public function promoCodeUpdate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();

        $newCode = strtoupper(trim((string) ($_POST['code'] ?? '')));
        if ($newCode === '' || !preg_match('/^[A-Z0-9_-]{3,40}$/', $newCode)) {
            flash('error', 'El código debe tener entre 3 y 40 caracteres (letras, números, guión o guión bajo).');
            redirect('/admin/promo');
        }

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE discount_codes SET is_active = 0 WHERE type = ? AND is_active = 1'
            )->execute(['promo_doceo']);

            $stmt = $pdo->prepare('SELECT id FROM discount_codes WHERE code = ? LIMIT 1');
            $stmt->execute([$newCode]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                $pdo->prepare(
                    'UPDATE discount_codes SET type = ?, discount_mode = ?, is_active = 1, partner_id = NULL WHERE id = ?'
                )->execute(['promo_doceo', 'to_public', (int) $existingId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO discount_codes (code, type, discount_mode, is_active) VALUES (?, ?, ?, 1)'
                )->execute([$newCode, 'promo_doceo', 'to_public']);
            }

            Settings::set('doceo_promo_code', $newCode);
            $pdo->commit();
            flash('success', 'Código promocional actualizado a ' . $newCode . '.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'No se pudo actualizar el código: ' . $e->getMessage());
        }

        redirect('/admin/promo');
    }

    private function isUksSolicitudTemplate(string $code): bool
    {
        return in_array($code, [MailTemplateService::UKS_SOLICITUD, MailTemplateService::UKS_SOLICITUD_LEGACY], true);
    }

    public function mailTemplates(): void
    {
        Auth::requireRole(['admin']);
        $svc = new MailTemplateService();
        $svc->ensureDefaults();
        $repo = new \App\Repositories\MailTemplateRepository();
        $pagination = Pagination::fromRequest($repo->countAll());
        view('admin/mail_templates', [
            'title' => 'Plantillas de correo',
            'templates' => $repo->all($pagination['limit'], $pagination['offset']),
            'pagination' => $pagination,
            'layout' => 'admin',
        ]);
    }

    public function catalogFilters(): void
    {
        Auth::requireRole(['admin']);
        view('admin/catalog_filters', [
            'title' => 'Filtros del catálogo',
            'filters' => (new CatalogFilterService())->adminFilters(),
            'layout' => 'admin',
        ]);
    }

    public function catalogFilterCreateForm(): void
    {
        Auth::requireRole(['admin']);
        view('admin/catalog_filter_form', [
            'title' => 'Nuevo filtro',
            'filter' => null,
            'layout' => 'admin',
        ]);
    }

    public function catalogFilterCreate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $id = (new CatalogFilterService())->create($_POST);
            flash('success', 'Filtro creado.');
            redirect('/admin/filtros-catalogo/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/filtros-catalogo/nuevo');
        }
    }

    public function catalogFilterEdit(string $id): void
    {
        Auth::requireRole(['admin']);
        $filter = (new \App\Repositories\CatalogFilterRepository())->find((int) $id);
        if ($filter === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Filtro no encontrado', 'layout' => 'admin']);

            return;
        }
        view('admin/catalog_filter_form', [
            'title' => 'Editar filtro · ' . $filter['label'],
            'filter' => $filter,
            'layout' => 'admin',
        ]);
    }

    public function catalogFilterUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $filterId = (int) $id;
        try {
            (new CatalogFilterService())->update($filterId, $_POST);
            flash('success', 'Filtro actualizado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/filtros-catalogo/' . $filterId);
    }

    public function catalogFilterDelete(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new CatalogFilterService())->delete((int) $id);
            flash('success', 'Filtro eliminado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/filtros-catalogo');
    }

    public function mailTemplateCreate(): void
    {
        Auth::requireRole(['admin']);
        $template = [
            'code' => '',
            'name' => 'Nueva plantilla',
            'subject' => '',
            'body_html' => '<p>Hola {{name}},</p>' . "\n" . '<p>Escribe aquí el contenido de tu correo.</p>',
            'is_active' => 1,
            'trigger_mode' => 'manual',
            'required_fields_json' => null,
        ];

        view('admin/mail_template_edit', [
            'title' => 'Nueva plantilla de correo',
            'template' => $template,
            'placeholders' => [],
            'selectedPlaceholders' => [],
            'availablePlaceholders' => MailTemplateService::availablePlaceholderOptions(),
            'routing' => ['to' => '', 'cc' => ''],
            'requiresFixedRecipient' => false,
            'testEmailDefault' => trim((string) (Auth::user()['email'] ?? '')),
            'previewVars' => [],
            'isUksSolicitud' => false,
            'isNew' => true,
            'layout' => 'admin',
        ]);
    }

    public function mailTemplateStore(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $svc = new MailTemplateService();
        $placeholders = $this->mailTemplatePlaceholdersFromPost();
        $code = trim((string) ($_POST['code'] ?? ''));

        try {
            $svc->create(
                $code,
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['subject'] ?? '')),
                (string) ($_POST['body_html'] ?? ''),
                !empty($_POST['is_active']),
                $placeholders,
                (string) ($_POST['trigger_mode'] ?? 'manual')
            );
            flash('success', 'Plantilla creada. Ya puedes editarla o probarla cuando el envío quede corregido.');
            redirect('/admin/correos/' . $code);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/correos/nueva');
        }
    }

    public function mailTemplateEdit(string $code): void
    {
        Auth::requireRole(['admin']);
        $svc = new MailTemplateService();
        $svc->ensureDefaults();
        $svc->migrateUksSolicitudTemplate();

        if ($code === MailTemplateService::UKS_SOLICITUD_LEGACY && $svc->find(MailTemplateService::UKS_SOLICITUD) !== null) {
            redirect('/admin/correos/' . MailTemplateService::UKS_SOLICITUD);
        }

        $template = $svc->find($code);
        if ($template === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Plantilla no encontrada', 'layout' => 'admin']);

            return;
        }

        $adminUser = Auth::user();
        $effectiveCode = $code;
        if ($code === MailTemplateService::UKS_SOLICITUD_LEGACY && $svc->find(MailTemplateService::UKS_SOLICITUD) !== null) {
            $effectiveCode = MailTemplateService::UKS_SOLICITUD;
        }
        $selectedPlaceholders = MailTemplateService::placeholdersForTemplate($template);
        $previewVars = array_merge(
            MailTemplateService::sampleVarsForCode($code),
            MailTemplateService::sampleVarsForPlaceholders($selectedPlaceholders)
        );

        view('admin/mail_template_edit', [
            'title' => 'Editar correo · ' . $template['name'],
            'template' => $template,
            'placeholders' => $selectedPlaceholders,
            'selectedPlaceholders' => $selectedPlaceholders,
            'availablePlaceholders' => MailTemplateService::availablePlaceholderOptions(),
            'routing' => $svc->routing($effectiveCode),
            'requiresFixedRecipient' => $svc->requiresFixedRecipient($code),
            'testEmailDefault' => trim((string) ($adminUser['email'] ?? '')),
            'previewVars' => $previewVars,
            'isUksSolicitud' => $this->isUksSolicitudTemplate($code),
            'isNew' => false,
            'layout' => 'admin',
        ]);
    }

    public function mailTemplateUpdate(string $code): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();

        $svc = new MailTemplateService();
        $svc->ensureDefaults();
        $svc->migrateUksSolicitudTemplate();
        $requiresFixed = $svc->requiresFixedRecipient($code);
        $effectiveCode = $code;
        if ($code === MailTemplateService::UKS_SOLICITUD_LEGACY && $svc->find(MailTemplateService::UKS_SOLICITUD) !== null) {
            $effectiveCode = MailTemplateService::UKS_SOLICITUD;
        }

        try {
            $placeholders = $this->mailTemplatePlaceholdersFromPost();
            $svc->update(
                $code,
                trim((string) ($_POST['subject'] ?? '')),
                (string) ($_POST['body_html'] ?? ''),
                !empty($_POST['is_active']),
                $placeholders
            );

            if ($requiresFixed) {
                $toEmail = trim((string) ($_POST['to_email'] ?? ''));
                if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Indica un correo válido en Para (destino UKS).');
                }
                $ccEmail = trim((string) ($_POST['cc_email'] ?? ''));
                if ($ccEmail !== '' && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+(\s*,\s*[^@\s]+@[^@\s]+\.[^@\s]+)*$/', $ccEmail)) {
                    throw new \InvalidArgumentException('CC inválido. Usa uno o más correos separados por coma.');
                }
                $svc->saveRouting($effectiveCode, $toEmail, $ccEmail);
            }

            $messages = ['Plantilla guardada.'];

            if (!empty($_POST['send_test'])) {
                $testTo = trim((string) ($_POST['test_email'] ?? ''));
                if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Marcaste enviar prueba: indica un correo válido.');
                }
                $result = $this->isUksSolicitudTemplate($code)
                    ? $svc->sendUksSolicitudTest($testTo)
                    : $svc->sendTemplateTest($code, $testTo);
                $endpoint = Mailer::lastEndpoint();
                $transport = $endpoint['transport'] ?? 'mail';
                $transportDetail = $transport;
                if ($transport === 'smtp' && !empty($endpoint['host'])) {
                    $transportDetail .= ' (' . ($endpoint['host'] ?? '') . ':' . ($endpoint['port'] ?? '') . ')';
                }
                $messages[] = 'Prueba enviada a ' . $testTo . ' («' . $result['subject'] . '») vía ' . $transportDetail . '.';
                if (!empty($endpoint['fallback'])) {
                    flash('error', 'SMTP falló; se usó mail() local (no garantiza entrega). '
                        . implode(' | ', Mailer::lastErrors()));
                    redirect('/admin/correos/' . $effectiveCode);
                }
                if ($result['log_path'] !== null) {
                    $messages[] = 'Log: ' . basename($result['log_path']);
                }
            }

            flash('success', implode(' ', $messages));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/correos/' . $effectiveCode);
    }

    /** @return list<string> */
    private function mailTemplatePlaceholdersFromPost(): array
    {
        $raw = $_POST['placeholders'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        return MailTemplateService::sanitizePlaceholders(array_values($raw));
    }
}

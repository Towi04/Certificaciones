<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;

use App\Auth\Auth;
use App\Database\Connection;
use App\Repositories\CertifierRepository;
use App\Repositories\ProductGroupRepository;
use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TrackingRepository;
use App\Services\AdminOpsBoardService;
use App\Services\CatalogFilterService;
use App\Services\CheckoutService;
use App\Services\CheckoutRequirements;
use App\Services\ExportService;
use App\Services\GroupStepConfig;
use App\Services\ImportService;
use App\Integrations\Mailer;
use App\Services\InventoryService;
use App\Services\MailTemplateService;
use App\Services\ProductAdminService;
use App\Services\ExamScheduleService;
use App\Services\ProductMediaService;
use App\Services\ResultsDeliveryService;
use App\Services\DocumentService;
use App\Services\StepMailService;
use App\Services\TrackingService;
use App\Services\UksEletService;
use App\Services\ProviderRequestService;
use App\Support\Pagination;
use App\Support\Settings;

final class AdminController
{
    public function dashboard(): void
    {
        Auth::requireRole(['admin']);
        $filters = [
            'q' => isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : null,
            'view' => isset($_GET['view']) && is_string($_GET['view']) ? trim($_GET['view']) : 'action',
        ];
        if ($filters['q'] === '') {
            $filters['q'] = null;
        }
        if (!isset(AdminOpsBoardService::VIEWS[$filters['view'] ?? ''])) {
            // Compat: «Por pagar» se absorbió en «Por atender».
            if (($filters['view'] ?? '') === 'pay') {
                $filters['view'] = 'action';
            } else {
                $filters['view'] = 'action';
            }
        }

        $board = new AdminOpsBoardService();
        $counts = [];
        foreach (array_keys(AdminOpsBoardService::VIEWS) as $viewKey) {
            try {
                $counts[$viewKey] = $board->count(['q' => $filters['q'], 'view' => $viewKey]);
            } catch (\Throwable) {
                $counts[$viewKey] = 0;
            }
        }

        $pagination = Pagination::fromRequest($counts[$filters['view']] ?? 0, 40);
        $rows = [];
        try {
            $rows = $board->list($filters, $pagination['limit'], $pagination['offset']);
        } catch (\Throwable $e) {
            flash('error', 'No se pudo cargar el tablero: ' . $e->getMessage());
        }

        view('admin/ops', [
            'title' => 'Operación',
            'rows' => $rows,
            'filters' => $filters,
            'views' => AdminOpsBoardService::VIEWS,
            'viewHints' => AdminOpsBoardService::VIEW_HINTS,
            'counts' => $counts,
            'pagination' => $pagination,
            'layout' => 'admin',
        ]);
    }

    public function opsExport(): void
    {
        Auth::requireRole(['admin']);
        $filters = [
            'q' => isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : null,
            'view' => isset($_GET['view']) && is_string($_GET['view']) ? trim($_GET['view']) : 'all',
        ];
        if ($filters['q'] === '') {
            $filters['q'] = null;
        }
        if (!isset(AdminOpsBoardService::VIEWS[$filters['view'] ?? ''])) {
            $filters['view'] = 'all';
        }

        $rows = (new AdminOpsBoardService())->list($filters, null, 0);
        $filename = 'operacion-' . date('Y-m-d-His') . '.csv';
        csv_download_headers($filename);
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        csv_put($out, [
            'Matrícula', 'Alumno', 'Email', 'Teléfono', 'Partner', 'Producto', 'Código producto',
            'Pago', 'Paso', 'Estado caso', 'Examen', 'Folio', 'Clave', 'Extra', 'Proveedor', 'CENNI', 'Actualizado',
        ]);
        foreach ($rows as $r) {
            $exam = trim((string) ($r['exam_date'] ?? '') . ' ' . (string) ($r['exam_time'] ?? ''));
            $provider = !empty($r['provider_sent_at'])
                ? 'enviado'
                : (!empty($r['provider_pending']) ? 'pendiente' : (!empty($r['provider_enabled']) ? 'n/a' : '—'));
            csv_put($out, [
                $r['matricula'] ?? '',
                $r['student_full_name'] ?? '',
                $r['student_email'] ?? '',
                $r['student_phone'] ?? '',
                $r['partner_code'] ?? '',
                $r['product_name'] ?? '',
                $r['product_code'] ?? '',
                $r['purchase_status'] ?? '',
                $r['current_step_code'] ?? '',
                $r['tracking_status'] ?? '',
                $exam,
                $r['folio'] ?? '',
                $r['access_key'] ?? '',
                $r['zoom_url'] ?? '',
                $provider,
                $r['cenni_folio'] ?? '',
                $r['updated_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    public function opsSaveAccess(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        $return = $this->opsReturnQuery();
        try {
            $notify = !empty($_POST['notify']);
            $result = (new AdminOpsBoardService())->saveAccess(
                $trackingId,
                (string) ($_POST['folio'] ?? ''),
                (string) ($_POST['access_key'] ?? ''),
                (int) Auth::id(),
                $notify,
                (string) ($_POST['zoom_url'] ?? '')
            );
            flash(
                $result['notified'] ? 'success' : ($notify ? 'error' : 'success'),
                $result['notified']
                    ? 'Folio/clave/extra guardados y plantilla enviada al alumno.'
                    : ($notify
                        ? 'Datos guardados, pero no se envió el correo. Revisa que el paso de accesos tenga «Enviar correo» y la plantilla.'
                        : 'Folio/clave/extra guardados.')
            );
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin' . $return);
    }

    public function opsBulkAccess(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $return = $this->opsReturnQuery();
        $ids = $_POST['tracking_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $folios = is_array($_POST['folio'] ?? null) ? $_POST['folio'] : [];
        $keys = is_array($_POST['access_key'] ?? null) ? $_POST['access_key'] : [];
        $zooms = is_array($_POST['zoom_url'] ?? null) ? $_POST['zoom_url'] : [];
        $items = [];
        $idSet = [];
        foreach ($ids as $rawId) {
            $tid = (int) $rawId;
            if ($tid > 0) {
                $idSet[$tid] = true;
            }
        }
        // Guardar todo: IDs vienen de las claves de folio/clave/zoom sin checkboxes.
        foreach (array_keys($folios + $keys + $zooms) as $rawId) {
            $tid = (int) $rawId;
            if ($tid > 0) {
                $idSet[$tid] = true;
            }
        }
        foreach (array_keys($idSet) as $tid) {
            $items[] = [
                'tracking_id' => (int) $tid,
                'folio' => (string) ($folios[(string) $tid] ?? $folios[$tid] ?? ''),
                'access_key' => (string) ($keys[(string) $tid] ?? $keys[$tid] ?? ''),
                'zoom_url' => (string) ($zooms[(string) $tid] ?? $zooms[$tid] ?? ''),
            ];
        }
        if ($items === []) {
            flash('error', 'No hay folio, clave o dato extra para guardar en las filas visibles.');
            redirect('/admin' . $return);
        }
        try {
            $result = (new AdminOpsBoardService())->bulkPublishAccess(
                $items,
                (int) Auth::id(),
                !empty($_POST['notify'])
            );
            $msg = 'Procesados: ' . $result['ok'] . ' ok';
            if ($result['fail'] > 0) {
                $msg .= ', ' . $result['fail'] . ' con error';
                if ($result['errors'] !== []) {
                    $msg .= ' · ' . $result['errors'][0];
                }
                flash('error', $msg);
            } else {
                flash('success', $msg . (!empty($_POST['notify']) ? ' · plantillas enviadas.' : '.'));
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin' . $return);
    }

    /** Query string para volver al tablero con los mismos filtros. */
    private function opsReturnQuery(): string
    {
        $view = isset($_POST['return_view']) && is_string($_POST['return_view']) ? trim($_POST['return_view']) : '';
        $q = isset($_POST['return_q']) && is_string($_POST['return_q']) ? trim($_POST['return_q']) : '';
        $params = [];
        if ($view !== '' && isset(AdminOpsBoardService::VIEWS[$view])) {
            $params['view'] = $view;
        }
        if ($q !== '') {
            $params['q'] = $q;
        }

        return $params === [] ? '' : ('?' . http_build_query($params));
    }

    public function products(): void
    {
        Auth::requireRole(['admin']);
        $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $filters = [
            'supplier_id' => isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0,
            'product_group_id' => isset($_GET['product_group_id']) ? (int) $_GET['product_group_id'] : 0,
            'is_public' => array_key_exists('is_public', $_GET) ? (string) $_GET['is_public'] : '',
            'is_star' => array_key_exists('is_star', $_GET) ? (string) $_GET['is_star'] : '',
        ];
        $repo = new ProductRepository();
        $qArg = $q !== '' ? $q : null;
        $pagination = Pagination::fromRequest($repo->adminCount($qArg, $filters));
        $products = $repo->adminList($qArg, $pagination['limit'], $pagination['offset'], $filters);
        $groups = [];
        $suppliers = [];
        try {
            $groups = (new ProductGroupRepository())->all();
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $suppliers = (new SupplierRepository())->all();
        } catch (\Throwable $e) {
            // ignore
        }
        view('admin/products', [
            'title' => 'Productos',
            'products' => $products,
            'pagination' => $pagination,
            'q' => $q,
            'filters' => $filters,
            'groups' => $groups,
            'suppliers' => $suppliers,
            'groupsCount' => count($groups),
            'layout' => 'admin',
        ]);
    }

    public function productCreateForm(): void
    {
        Auth::requireRole(['admin']);
        $product = $this->mergeOldIntoProduct(null);
        $data = $this->productFormData($product);
        if (has_old_input() && is_array(old('catalog_filter_ids'))) {
            $data['selectedFilterIds'] = array_map('intval', (array) old('catalog_filter_ids'));
        }
        view('admin/product_form', $data);
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
            $this->formError($e->getMessage());
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

        $product = $this->mergeOldIntoProduct($product);
        $data = $this->productFormData($product);
        if (has_old_input() && is_array(old('catalog_filter_ids'))) {
            $data['selectedFilterIds'] = array_map('intval', (array) old('catalog_filter_ids'));
        }
        $data['media'] = $media;
        $data['title'] = 'Editar · ' . ($product['name'] ?? '');
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
            $this->formError($e->getMessage());
        }
        redirect('/admin/productos/' . $productId);
    }


    public function productDelete(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new ProductAdminService())->deleteProduct((int) $id);
            flash('success', 'Producto eliminado.');
            redirect('/admin/productos');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/productos/' . (int) $id);
        }
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
        $view = [
            'title' => 'Nuevo grupo de producto',
            'group' => null,
            'suppliers' => (new SupplierRepository())->all(),
            'defaultConfig' => $defaultConfig,
            'usedDocCodes' => (new ProductAdminService())->usedReglamentoDocCodes(null),
            'extras' => ProductAdminService::groupFormExtrasFromConfig((string) $defaultConfig),
            'pipelines' => (new \App\Repositories\PipelineRepository())->allTemplates(),
            'pipelineStepsByCode' => (new \App\Repositories\PipelineRepository())->stepsByTemplateCode(),
            'mailTemplates' => array_values(array_filter(
                (new MailTemplateService())->all(),
                static fn (array $t): bool => !array_key_exists('is_active', $t) || !empty($t['is_active'])
            )),
            'csvTemplates' => (new \App\Repositories\ExportTemplateRepository())->listActive(),
            'layout' => 'admin',
        ];
        view('admin/product_group_form', $this->mergeOldIntoGroupForm($view));
    }

    public function productGroupCreate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $post = $_POST;
            if (isset($_FILES['provider_request_workbook']) && is_array($_FILES['provider_request_workbook'])) {
                $post['_provider_workbook_file'] = $_FILES['provider_request_workbook'];
            }
            if (isset($_FILES['instruction_pdf_file']) && is_array($_FILES['instruction_pdf_file'])) {
                $post['_instruction_pdf_file'] = $_FILES['instruction_pdf_file'];
            }
            $docFiles = $this->normalizeMultiFileUpload($_FILES['instruction_doc_file'] ?? null);
            if ($docFiles !== []) {
                $post['_instruction_doc_files'] = $docFiles;
            }
            $id = (new ProductAdminService())->createGroup($post);
            flash('success', 'Grupo creado. Ya puedes asignarlo a productos.');
            redirect('/admin/grupos/' . $id);
        } catch (\Throwable $e) {
            $this->formError($e->getMessage());
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
        $pipelineRepo = new \App\Repositories\PipelineRepository();
        $view = [
            'usedDocCodes' => (new ProductAdminService())->usedReglamentoDocCodes((int) $id),
            'title' => 'Editar grupo · ' . $group['name'],
            'group' => $group,
            'suppliers' => (new SupplierRepository())->all(),
            'defaultConfig' => $defaultConfig,
            'extras' => ProductAdminService::groupFormExtrasFromConfig((string) ($group['config_json'] ?? '')),
            'pipelines' => $pipelineRepo->allTemplates(),
            'pipelineStepsByCode' => $pipelineRepo->stepsByTemplateCode(),
            'mailTemplates' => array_values(array_filter(
                (new MailTemplateService())->all(),
                static fn (array $t): bool => !array_key_exists('is_active', $t) || !empty($t['is_active'])
            )),
            'csvTemplates' => (new \App\Repositories\ExportTemplateRepository())->listActive(),
            'layout' => 'admin',
        ];
        $view = $this->mergeOldIntoGroupForm($view);
        if (has_old_input() && trim((string) (($view['group']['name'] ?? ''))) !== '') {
            $view['title'] = 'Editar grupo · ' . $view['group']['name'];
        }
        view('admin/product_group_form', $view);
    }

    public function productGroupUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $groupId = (int) $id;
        try {
            $post = $_POST;
            if (isset($_FILES['provider_request_workbook']) && is_array($_FILES['provider_request_workbook'])) {
                $post['_provider_workbook_file'] = $_FILES['provider_request_workbook'];
            }
            if (isset($_FILES['instruction_pdf_file']) && is_array($_FILES['instruction_pdf_file'])) {
                $post['_instruction_pdf_file'] = $_FILES['instruction_pdf_file'];
            }
            $docFiles = $this->normalizeMultiFileUpload($_FILES['instruction_doc_file'] ?? null);
            if ($docFiles !== []) {
                $post['_instruction_doc_files'] = $docFiles;
            }
            (new ProductAdminService())->updateGroup($groupId, $post);
            flash('success', 'Grupo actualizado. Los productos del grupo heredan estos cambios.');
        } catch (\Throwable $e) {
            $this->formError($e->getMessage());
        }
        redirect('/admin/grupos/' . $groupId);
    }

    public function productGroupDelete(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new ProductAdminService())->deleteGroup((int) $id);
            flash('success', 'Grupo eliminado.');
            redirect('/admin/grupos');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/grupos/' . (int) $id);
        }
    }



    public function createCheckoutField(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $label = trim((string) ($_POST['label'] ?? ''));
            $type = (string) ($_POST['type'] ?? 'text');
            $required = !empty($_POST['required']);
            $options = $_POST['options_text'] ?? ($_POST['options'] ?? null);
            $field = CheckoutRequirements::addCustomField($label, $type, $required, null, $options);
            echo json_encode(['ok' => true, 'field' => $field], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function deleteCheckoutField(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $code = (string) ($_POST['code'] ?? '');
            CheckoutRequirements::removeCustomField($code);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function updateCheckoutField(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $code = (string) ($_POST['code'] ?? '');
            $label = trim((string) ($_POST['label'] ?? ''));
            $type = (string) ($_POST['type'] ?? 'text');
            $required = !empty($_POST['required']);
            $options = $_POST['options_text'] ?? ($_POST['options'] ?? null);
            $field = CheckoutRequirements::updateCustomField($code, $label, $type, $required, $options);
            echo json_encode(['ok' => true, 'field' => $field], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
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
                throw new \InvalidArgumentException('Sube una imagen, un documento (PDF/DOC) o pega un link de YouTube.');
            }
            $service->addMedia(
                $productId,
                $file,
                trim((string) ($_POST['title'] ?? '')),
                trim((string) ($_POST['caption'] ?? '')),
                (int) ($_POST['sort_order'] ?? 0),
                !empty($_POST['is_active'])
            );
            flash('success', 'Archivo agregado a la galería del producto.');
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
        redirect('/admin?view=all');
    }

    public function masterExport(): void
    {
        Auth::requireRole(['admin']);
        redirect('/admin/operacion/exportar?view=all');
    }

    public function payments(): void
    {
        Auth::requireRole(['admin']);
        redirect('/admin?view=pay');
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
            $trackingCount = (new TrackingRepository())->forPurchase($purchaseId);
            $n = is_array($trackingCount) ? count($trackingCount) : 0;
            flash(
                'success',
                $n > 1
                    ? 'Pago del paquete confirmado · aplica a ' . $n . ' productos de la matrícula.'
                    : 'Pago confirmado.'
            );
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        if (!empty($_POST['return_ops'])) {
            redirect('/admin' . $this->opsReturnQuery());
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
            'inventoryEnabled' => InventoryService::isEnabledForProduct($tracking),
            'resultsDelivery' => ResultsDeliveryService::fromConfig($productCfg),
            'resultsState' => ResultsDeliveryService::stateFromTracking($tracking),
            'resultsStepCode' => (static function () use ($productCfg): string {
                $delivery = ResultsDeliveryService::fromConfig($productCfg);
                foreach (GroupStepConfig::defsFromConfig($productCfg) as $code => $def) {
                    if ((string) ($def['action'] ?? '') !== GroupStepConfig::ACTION_SEND_MAIL) {
                        continue;
                    }
                    $tpl = trim((string) (($def['email']['template_code'] ?? '')));
                    if (!empty($def['requires_results'])
                        || ResultsDeliveryService::stepRequiresResults($def, $delivery, $tpl)
                    ) {
                        return (string) $code;
                    }
                }

                return '';
            })(),
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
                $svc->markStepDone($trackingId, $to, (int) Auth::id(), $note ?? 'Marcado hecho desde Operación');
                flash('success', 'Paso actualizado a ' . $to);
            } else {
                $code = $svc->advance($trackingId, (int) Auth::id(), $note);
                $svc->markStepDone($trackingId, $code, (int) Auth::id(), $note);
                flash('success', 'Avanzó a ' . $code);
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        if (!empty($_POST['return_ops'])) {
            redirect('/admin' . $this->opsReturnQuery());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingSyncMoodle(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            $result = (new \App\Services\MoodleEnrolmentService())->syncTracking(
                $trackingId,
                (int) Auth::id(),
                false
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
            $stepCode = trim((string) ($_POST['step_code'] ?? ''));
            $wantNotify = !empty($_POST['notify']);
            $useStepMail = false;
            if ($stepCode !== '') {
                $tracking = (new TrackingService())->find($trackingId);
                if ($tracking !== null) {
                    $defs = GroupStepConfig::defsFromConfig(CheckoutRequirements::config([
                        'config_json' => $tracking['config_json'] ?? null,
                        'group_config_json' => $tracking['group_config_json'] ?? null,
                    ]));
                    $emailCfg = is_array(($defs[$stepCode]['email'] ?? null)) ? $defs[$stepCode]['email'] : [];
                    $useStepMail = !empty($emailCfg['enabled'])
                        && trim((string) ($emailCfg['template_code'] ?? '')) !== '';
                }
            }

            $examData = [
                'exam_date' => $_POST['exam_date'] ?? null,
                'exam_time' => $_POST['exam_time'] ?? null,
                // Correos solo vía StepMailService / automatización de pasos.
                'notify' => false,
            ];
            if (array_key_exists('exam_date_2', $_POST)) {
                $examData['exam_date_2'] = $_POST['exam_date_2'];
            }
            if (array_key_exists('exam_time_2', $_POST)) {
                $examData['exam_time_2'] = $_POST['exam_time_2'];
            }
            if (array_key_exists('zoom_url', $_POST)) {
                $examData['zoom_url'] = $_POST['zoom_url'];
            }
            (new TrackingService())->saveExamSchedule($trackingId, $examData, (int) Auth::id());
            // No usar step_done para bloquear reagendas: la fecha se puede cambiar N veces.

            $mailMsg = '';
            if ($wantNotify && $useStepMail) {
                try {
                    $result = (new StepMailService())->sendForStep($trackingId, $stepCode, (int) Auth::id());
                    $mailMsg = ' Correo «' . $result['template'] . '» enviado a ' . $result['to'] . '.';
                } catch (\Throwable $mailError) {
                    flash(
                        'error',
                        'Fecha de examen guardada, pero no se envió el correo: ' . $mailError->getMessage()
                    );
                    if (!empty($_POST['return_ops'])) {
                        redirect('/admin' . $this->opsReturnQuery());
                    }
                    redirect('/admin/seguimientos/' . $trackingId);
                }
            }
            flash('success', 'Fecha de examen guardada.' . $mailMsg);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        if (!empty($_POST['return_ops'])) {
            redirect('/admin' . $this->opsReturnQuery());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingAuthorizeExam(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            (new TrackingService())->authorizeExamSchedule($trackingId, (int) Auth::id(), [
                'exam_date' => $_POST['exam_date'] ?? null,
                'exam_time' => $_POST['exam_time'] ?? null,
                'note' => (string) ($_POST['note'] ?? ''),
            ]);
            flash('success', 'Fecha de examen autorizada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingUpdateStudent(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            $svc = new TrackingService();
            $svc->updateStudentProfile($trackingId, [
                'first_name' => (string) ($_POST['first_name'] ?? ''),
                'last_name_p' => (string) ($_POST['last_name_p'] ?? ''),
                'last_name_m' => (string) ($_POST['last_name_m'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
            ], (int) Auth::id());
            $stepCode = trim((string) ($_POST['step_code'] ?? ''));
            if ($stepCode !== '') {
                $svc->markStepDone($trackingId, $stepCode, (int) Auth::id(), 'Datos del alumno corregidos');
            }
            flash('success', 'Datos del alumno actualizados.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        if (!empty($_POST['return_ops'])) {
            redirect('/admin' . $this->opsReturnQuery());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingPublishEletAccess(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            $notify = !empty($_POST['notify']);
            $notified = (new UksEletService())->publishExamAccess(
                $trackingId,
                trim((string) ($_POST['folio'] ?? '')),
                trim((string) ($_POST['access_key'] ?? '')),
                (int) Auth::id(),
                $notify
            );
            if ($notify && $notified) {
                flash('success', 'Accesos publicados y alumno notificado por correo.');
            } elseif ($notify && !$notified) {
                flash(
                    'error',
                    'Accesos publicados, pero no se envió el correo. Revisa que el paso de folio/clave tenga «Enviar correo» y la plantilla de accesos.'
                );
            } else {
                flash('success', 'Accesos publicados (sin correo).');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        if (!empty($_POST['return_ops'])) {
            redirect('/admin' . $this->opsReturnQuery());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingSaveResults(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            $notify = !empty($_POST['notify']);
            $cancelled = !empty($_POST['cancelled']);
            (new TrackingService())->saveResults($trackingId, [
                'cancelled' => $cancelled,
                'cancel_reason' => trim((string) ($_POST['cancel_reason'] ?? '')),
                'results_value' => is_array($_POST['results_value'] ?? null) ? $_POST['results_value'] : [],
                'results_file' => $_FILES['results_file'] ?? null,
                'results_level' => trim((string) ($_POST['results_level'] ?? '')),
                'results_score' => $_POST['results_score'] ?? '',
                'results_url' => trim((string) ($_POST['results_url'] ?? '')),
                'cenni_folio' => trim((string) ($_POST['cenni_folio'] ?? '')),
                'results_step_code' => trim((string) ($_POST['results_step_code'] ?? '')),
                'notify' => $notify,
            ], (int) Auth::id());
            if ($cancelled) {
                flash('success', $notify
                    ? 'Cancelación guardada y correo enviado.'
                    : 'Cancelación guardada (sin correo).');
            } else {
                flash('success', $notify
                    ? 'Resultados guardados y correo enviado al alumno.'
                    : 'Resultados guardados (sin correo).');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingResultsPdf(string $id): void
    {
        Auth::requireRole(['admin']);
        $tracking = (new TrackingService())->find((int) $id);
        if ($tracking === null) {
            http_response_code(404);
            exit('Seguimiento no encontrado');
        }
        $state = ResultsDeliveryService::stateFromTracking($tracking);
        $fieldCode = ResultsDeliveryService::normalizeCode((string) ($_GET['field'] ?? ''));
        $pathRel = '';
        $name = 'resultados.pdf';
        $values = is_array($state['values'] ?? null) ? $state['values'] : [];

        if ($fieldCode !== '') {
            $raw = $values[$fieldCode] ?? null;
            if (is_array($raw)) {
                $pathRel = trim((string) ($raw['path'] ?? ''));
                $name = trim((string) ($raw['name'] ?? '')) ?: $name;
            } elseif (is_string($raw)) {
                $pathRel = trim($raw);
            }
        } else {
            // Compat: primer PDF en values, o legado pdf_path.
            foreach ($values as $raw) {
                if (is_array($raw) && trim((string) ($raw['path'] ?? '')) !== '') {
                    $pathRel = trim((string) $raw['path']);
                    $name = trim((string) ($raw['name'] ?? '')) ?: $name;
                    break;
                }
            }
            if ($pathRel === '' && !empty($state['pdf_path'])) {
                $pathRel = trim((string) $state['pdf_path']);
                $name = trim((string) ($state['pdf_name'] ?? '')) ?: $name;
            }
        }

        if ($pathRel === '') {
            http_response_code(404);
            exit('PDF de resultados no encontrado');
        }
        $docs = new DocumentService();
        $path = $docs->absolutePath($pathRel);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Archivo no disponible en disco');
        }
        $mime = mime_content_type($path) ?: 'application/pdf';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($name) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
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
            (new ProviderRequestService())->send(
                $trackingId,
                (int) $tracking['purchase_id'],
                (int) Auth::id(),
                $includeProof
            );
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


    
    public function trackingUploadProviderProof(string $id): void
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
            $file = $_FILES['provider_payment_proof'] ?? null;
            if (!is_array($file)) {
                throw new \InvalidArgumentException('Selecciona el comprobante de pago.');
            }
            $result = (new ProviderRequestService())->uploadAdminPaymentProof(
                $trackingId,
                $file,
                (int) Auth::id()
            );
            flash(
                'success',
                !empty($result['sent'])
                    ? 'Comprobante guardado y solicitud enviada al proveedor.'
                    : 'Comprobante guardado. Ya puedes enviar la solicitud al proveedor.'
            );
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        if (!empty($_POST['return_ops'])) {
            redirect('/admin' . $this->opsReturnQuery());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingSendProviderRequest(string $id): void
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
            $includeProof = !empty($_POST['include_payment_proof']);
            (new ProviderRequestService())->send(
                $trackingId,
                (int) $tracking['purchase_id'],
                (int) Auth::id(),
                $includeProof
            );
            flash('success', 'Solicitud enviada al proveedor.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        if (!empty($_POST['return_ops'])) {
            redirect('/admin' . $this->opsReturnQuery());
        }
        redirect('/admin/seguimientos/' . $trackingId);
    }

    public function trackingSendStepMail(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $trackingId = (int) $id;
        $stepCode = trim((string) ($_POST['step_code'] ?? ''));
        try {
            $result = (new StepMailService())->sendForStep($trackingId, $stepCode, (int) Auth::id());
            $who = match ($result['audience']) {
                'partner' => 'partner',
                'provider' => 'proveedor',
                default => 'alumno',
            };
            flash(
                'success',
                'Correo «' . $result['template'] . '» enviado al ' . $who . ' (' . $result['to'] . ').'
            );
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        if (!empty($_POST['return_ops'])) {
            redirect('/admin' . $this->opsReturnQuery());
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
            ]);
            flash(
                'success',
                'Partner creado. Contraseña temporal: ' . $result['plain_password']
                . ' · Guárdala y compártela con el partner (no se envía por correo).'
            );
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
            ]);
            $msg = 'Partner actualizado.';
            if (!empty($result['plain_password'])) {
                $msg .= ' Nueva contraseña: ' . $result['plain_password']
                    . ' · Compártela con el partner (no se envía por correo).';
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
            flash(
                'success',
                'Contraseña temporal: ' . $result['plain_password']
                . ' · Compártela con el partner (no se envía por correo).'
            );
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/partners/' . $partnerId);
    }

    public function suppliers(): void
    {
        Auth::requireRole(['admin']);
        $tab = (string) ($_GET['tab'] ?? 'proveedores');
        if ($tab === 'certificadoras') {
            $repo = new CertifierRepository();
            $pagination = Pagination::fromRequest($repo->countAll());
            $certifiers = $repo->all($pagination['limit'], $pagination['offset']);
            $certifierCounts = [];
            foreach ($certifiers as $c) {
                $certifierCounts[(int) $c['id']] = $repo->countProducts((int) $c['id']);
            }
            view('admin/suppliers', [
                'title' => 'Certificadoras',
                'tab' => 'certificadoras',
                'suppliers' => [],
                'counts' => [],
                'certifiers' => $certifiers,
                'certifierCounts' => $certifierCounts,
                'pagination' => $pagination,
                'layout' => 'admin',
            ]);

            return;
        }

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
            'tab' => 'proveedores',
            'suppliers' => $suppliers,
            'counts' => $counts,
            'certifiers' => [],
            'certifierCounts' => [],
            'pagination' => $pagination,
            'layout' => 'admin',
        ]);
    }

    public function supplierCreateForm(): void
    {
        Auth::requireRole(['admin']);
        $supplier = null;
        $old = old_input();
        if ($old !== []) {
            $supplier = [
                'name' => (string) ($old['name'] ?? ''),
                'code' => (string) ($old['code'] ?? ''),
                'website' => (string) ($old['website'] ?? ''),
                'is_active' => !empty($old['is_active']) ? 1 : 0,
            ];
        }
        view('admin/supplier_form', [
            'title' => 'Nuevo proveedor',
            'supplier' => $supplier,
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
            $this->formError($e->getMessage());
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
        $old = old_input();
        if ($old !== []) {
            foreach (['name', 'code', 'website'] as $k) {
                if (array_key_exists($k, $old)) {
                    $supplier[$k] = $old[$k];
                }
            }
            if (array_key_exists('is_active', $old) || has_old_input()) {
                $supplier['is_active'] = !empty($old['is_active']) ? 1 : 0;
            }
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
        $tab = trim((string) ($_POST['return_tab'] ?? 'general'));
        if ($tab === '') {
            $tab = 'general';
        }
        try {
            $applied = (new \App\Services\SupplierAdminService())->saveAll($supplierId, $_POST, $_FILES);
            flash('success', 'Guardado: ' . implode(' · ', $applied) . '.');
        } catch (\Throwable $e) {
            $this->formError($e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#' . rawurlencode($tab));
    }

    public function supplierLogo(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $supplierId = (int) $id;
        $svc = new \App\Services\SupplierAdminService();
        $variant = (string) ($_POST['logo_variant'] ?? \App\Services\SupplierAdminService::LOGO_MARK);
        if ($variant !== \App\Services\SupplierAdminService::LOGO_WORDMARK) {
            $variant = \App\Services\SupplierAdminService::LOGO_MARK;
        }
        $label = $variant === \App\Services\SupplierAdminService::LOGO_WORDMARK
            ? 'Logo con denominación'
            : 'Logo sin denominación';
        try {
            if (!empty($_POST['remove_logo'])) {
                $svc->clearLogo($supplierId, $variant);
                flash('success', $label . ' eliminado.');
            } else {
                $file = $_FILES['logo'] ?? null;
                if (!is_array($file)) {
                    throw new \InvalidArgumentException('Selecciona una imagen de logo.');
                }
                $svc->uploadLogo($supplierId, $file, $variant);
                flash('success', $label . ' actualizado.');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/proveedores/' . $supplierId . '#logos');
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
        redirect('/admin/proveedores?tab=certificadoras');
    }

    public function certifierCreateForm(): void
    {
        Auth::requireRole(['admin']);
        $certifier = null;
        $old = old_input();
        if ($old !== []) {
            $certifier = [
                'name' => (string) ($old['name'] ?? ''),
                'code' => (string) ($old['code'] ?? ''),
                'website' => (string) ($old['website'] ?? ''),
                'is_active' => !empty($old['is_active']) ? 1 : 0,
            ];
        }
        view('admin/certifier_form', [
            'title' => 'Nueva certificadora',
            'certifier' => $certifier,
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
            $this->formError($e->getMessage());
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
        $old = old_input();
        if ($old !== []) {
            foreach (['name', 'code', 'website'] as $k) {
                if (array_key_exists($k, $old)) {
                    $certifier[$k] = $old[$k];
                }
            }
            $certifier['is_active'] = !empty($old['is_active']) ? 1 : 0;
        }
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
            $this->formError($e->getMessage());
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
            redirect('/admin/proveedores?tab=certificadoras');
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

    public function csvTemplates(): void
    {
        Auth::requireRole(['admin']);
        $templates = (new \App\Repositories\ExportTemplateRepository())->all();
        view('admin/csv_templates', [
            'title' => 'Plantillas CSV',
            'templates' => $templates,
            'layout' => 'admin',
        ]);
    }

    public function csvTemplateCreate(): void
    {
        Auth::requireRole(['admin']);
        $old = old_input();
        $template = [
            'code' => (string) ($old['code'] ?? ''),
            'name' => (string) ($old['name'] ?? ''),
            'batch_by' => (string) ($old['batch_by'] ?? 'none'),
            'is_active' => array_key_exists('is_active', $old) ? (!empty($old['is_active']) ? 1 : 0) : 1,
            'mapping_json' => null,
        ];
        $columns = [];
        if (isset($old['col_header'], $old['col_field']) && is_array($old['col_header']) && is_array($old['col_field'])) {
            foreach ($old['col_header'] as $i => $header) {
                $columns[] = [
                    'header' => (string) $header,
                    'field' => (string) ($old['col_field'][$i] ?? 'first_name'),
                ];
            }
        }
        if ($columns === []) {
            $columns = [
                ['header' => 'Matrícula', 'field' => 'matricula'],
                ['header' => 'Nombre(s)', 'field' => 'first_name'],
                ['header' => 'Apellido Paterno', 'field' => 'last_name_p'],
                ['header' => 'Apellido Materno', 'field' => 'last_name_m'],
                ['header' => 'Correo Electrónico', 'field' => 'email'],
            ];
        }
        $normalize = (string) ($old['normalize'] ?? 'none');
        $template['mapping_json'] = json_encode([
            'normalize' => $normalize,
            'filters' => [
                'product_codes' => array_values(array_filter(preg_split('/[\s,;]+/', (string) ($old['product_codes'] ?? '')) ?: [])),
                'product_group_codes' => array_values(array_filter(preg_split('/[\s,;]+/', (string) ($old['product_group_codes'] ?? '')) ?: [])),
                'purchase_status' => array_values(array_filter(preg_split('/[\s,;]+/', (string) ($old['purchase_status'] ?? 'paid')) ?: [])),
            ],
        ], JSON_UNESCAPED_UNICODE);

        view('admin/csv_template_edit', [
            'title' => 'Nueva plantilla CSV',
            'template' => $template,
            'columns' => $columns,
            'fieldOptions' => ExportService::fieldOptions(),
            'isNew' => true,
            'layout' => 'admin',
        ]);
    }

    public function csvTemplateStore(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $code = (new ExportService())->createFromAdmin($_POST);
            flash('success', 'Plantilla CSV creada.');
            redirect('/admin/plantillas-csv/' . $code);
        } catch (\Throwable $e) {
            $this->formError($e->getMessage());
            redirect('/admin/plantillas-csv/nueva');
        }
    }

    public function csvTemplateEdit(string $code): void
    {
        Auth::requireRole(['admin']);
        $svc = new ExportService();
        $template = $svc->template($code);
        if ($template === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Plantilla no encontrada', 'layout' => 'admin']);

            return;
        }

        $old = old_input();
        if ($old !== []) {
            $template['name'] = (string) ($old['name'] ?? $template['name']);
            $template['batch_by'] = (string) ($old['batch_by'] ?? $template['batch_by']);
            $template['is_active'] = !empty($old['is_active']) ? 1 : 0;
            $map = $svc->mapping($template);
            $map['normalize'] = (string) ($old['normalize'] ?? ($map['normalize'] ?? 'none'));
            $map['filters'] = [
                'product_codes' => array_values(array_filter(preg_split('/[\s,;]+/', (string) ($old['product_codes'] ?? '')) ?: [])),
                'product_group_codes' => array_values(array_filter(preg_split('/[\s,;]+/', (string) ($old['product_group_codes'] ?? '')) ?: [])),
                'purchase_status' => array_values(array_filter(preg_split('/[\s,;]+/', (string) ($old['purchase_status'] ?? 'paid')) ?: [])),
            ];
            $template['mapping_json'] = json_encode($map, JSON_UNESCAPED_UNICODE);
        }

        $mapping = $svc->mapping($template);
        $columns = [];
        if (isset($old['col_header'], $old['col_field']) && is_array($old['col_header']) && is_array($old['col_field'])) {
            foreach ($old['col_header'] as $i => $header) {
                $columns[] = [
                    'header' => (string) $header,
                    'field' => (string) ($old['col_field'][$i] ?? ''),
                ];
            }
        } else {
            foreach (($mapping['columns'] ?? []) as $col) {
                if (is_array($col)) {
                    $columns[] = [
                        'header' => (string) ($col['header'] ?? ''),
                        'field' => (string) ($col['field'] ?? ''),
                    ];
                }
            }
        }

        view('admin/csv_template_edit', [
            'title' => 'Editar CSV · ' . (string) ($template['name'] ?? $code),
            'template' => $template,
            'columns' => $columns,
            'fieldOptions' => ExportService::fieldOptions(),
            'isNew' => false,
            'layout' => 'admin',
        ]);
    }

    public function csvTemplateUpdate(string $code): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new ExportService())->updateFromAdmin($code, $_POST);
            flash('success', 'Plantilla CSV guardada.');
        } catch (\Throwable $e) {
            $this->formError($e->getMessage());
        }
        redirect('/admin/plantillas-csv/' . $code);
    }

    public function csvTemplateDelete(string $code): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new ExportService())->delete($code);
            flash('success', 'Plantilla CSV eliminada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/plantillas-csv');
    }

    public function csvTemplateDownload(string $code): void
    {
        Auth::requireRole(['admin']);
        $svc = new ExportService();
        $trackingId = (int) ($_GET['tracking_id'] ?? 0);
        $scope = (string) ($_GET['scope'] ?? 'student');
        $returnTo = trim((string) ($_GET['return'] ?? ''));
        if ($returnTo === '' || !str_starts_with($returnTo, '/admin')) {
            $returnTo = $trackingId > 0
                ? '/admin/seguimientos/' . $trackingId
                : '/admin/plantillas-csv';
        }

        try {
            if ($trackingId > 0) {
                $svc->sendDownloadForTracking($code, $trackingId, ['scope' => $scope]);
            }

            $options = [];
            if (!empty($_GET['exam_date']) && is_string($_GET['exam_date'])) {
                $options['exam_date'] = trim($_GET['exam_date']);
            }
            if (!empty($_GET['all_paid'])) {
                $options['step_codes'] = [];
            }
            $svc->sendDownload($code, $options);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect($returnTo);
        }
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
            'whatsapp' => Settings::get('school_whatsapp', Env::get('SCHOOL_WHATSAPP', '')) ?? '',
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

            $wa = preg_replace('/\D+/', '', (string) ($_POST['school_whatsapp'] ?? '')) ?? '';
            Settings::set('school_whatsapp', $wa);
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

    public function inventoryIndex(): void
    {
        Auth::requireRole(['admin']);
        $products = (new \App\Repositories\ProductRepository())->adminList(null, 500, 0);
        $invRepo = new \App\Repositories\InventoryRepository();
        $rows = [];
        foreach ($products as $p) {
            $cfg = InventoryService::configForProduct($p);
            if (empty($cfg['enabled'])) {
                // También mostrar si ya tiene códigos aunque el flag se apagara.
                $stock = $invRepo->stockCounts((int) $p['id']);
                if ($stock['total'] < 1) {
                    continue;
                }
            } else {
                $stock = $invRepo->stockCounts((int) $p['id']);
            }
            $rows[] = $p + [
                'stock' => $stock,
                'low_stock_threshold' => (int) $cfg['low_stock_threshold'],
            ];
        }
        view('admin/inventory', [
            'title' => 'Inventario de códigos',
            'products' => $rows,
            'layout' => 'admin',
        ]);
    }

    public function inventoryProduct(string $id): void
    {
        Auth::requireRole(['admin']);
        $productId = (int) $id;
        $product = (new \App\Repositories\ProductRepository())->find($productId);
        if ($product === null) {
            http_response_code(404);
            view('errors/404', ['title' => 'Producto no encontrado', 'layout' => 'admin']);

            return;
        }
        $invRepo = new \App\Repositories\InventoryRepository();
        view('admin/inventory_product', [
            'title' => 'Inventario · ' . (string) ($product['name'] ?? ''),
            'product' => $product,
            'stock' => $invRepo->stockCounts($productId),
            'lots' => $invRepo->lotsForProduct($productId),
            'codes' => $invRepo->codesForProduct($productId, null, 150),
            'pendingRestock' => $invRepo->pendingRestockTrackings($productId),
            'inventoryConfig' => InventoryService::configForProduct($product),
            'layout' => 'admin',
        ]);
    }

    public function inventoryImportLot(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $productId = (int) $id;
        try {
            $rows = InventoryService::parseCodesText((string) ($_POST['codes_text'] ?? ''));
            if ($rows === []) {
                throw new \InvalidArgumentException('Pega al menos un folio,clave por línea.');
            }
            $result = (new InventoryService())->importLot(
                $productId,
                $rows,
                trim((string) ($_POST['label'] ?? '')),
                trim((string) ($_POST['purchased_at'] ?? '')) ?: null,
                isset($_POST['cost_total']) && $_POST['cost_total'] !== ''
                    ? (float) $_POST['cost_total']
                    : null,
                max(0, (int) ($_POST['low_stock_threshold'] ?? 5)),
                (int) Auth::id()
            );
            $msg = 'Importados ' . $result['imported'] . ' códigos';
            if ($result['skipped'] > 0) {
                $msg .= ', omitidos ' . $result['skipped'];
            }
            if ($result['errors'] !== []) {
                $msg .= ' · ' . $result['errors'][0];
                flash('error', $msg);
            } else {
                flash('success', $msg . '.');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/inventario/' . $productId);
    }

    private function isUksSolicitudTemplate(string $code): bool
    {
        return MailTemplateService::isUksSolicitudCode($code);
    }

    public function mailTemplates(): void
    {
        Auth::requireRole(['admin']);
        $svc = new MailTemplateService();
        $svc->ensureDefaults();
        $repo = new \App\Repositories\MailTemplateRepository();
        $pagination = Pagination::fromRequest($repo->countAll());
        $branding = \App\Mail\MailBranding::config();
        view('admin/mail_templates', [
            'title' => 'Plantillas de correo',
            'templates' => $repo->all($pagination['limit'], $pagination['offset']),
            'pagination' => $pagination,
            'branding' => $branding,
            'brandingPreviewHtml' => \App\Mail\MailBranding::wrap(
                '<p style="margin:0 0 8px"><strong>Vista previa</strong></p>'
                . '<p style="margin:0">El contenido de cada plantilla aparece aquí, entre el encabezado y el pie globales.</p>'
            ),
            'layout' => 'admin',
        ]);
    }

    public function mailBrandingUpdate(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $action = trim((string) ($_POST['action'] ?? 'save'));
        try {
            if ($action === 'reset') {
                \App\Mail\MailBranding::reset();
                flash('success', 'Encabezado y pie restablecidos a los valores por defecto.');
                redirect('/admin/correos#branding');
            }

            $input = $_POST;
            $file = $_FILES['logo_file'] ?? null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $input['logo_url'] = \App\Mail\MailBranding::storeLogoUpload($file);
            }
            \App\Mail\MailBranding::save($input);
            flash('success', 'Marca de correo actualizada. Se aplica a todos los correos con envoltura DOCEO.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/correos#branding');
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
        $filter = null;
        $old = old_input();
        if ($old !== []) {
            $filter = [
                'label' => (string) ($old['label'] ?? ''),
                'slug' => (string) ($old['slug'] ?? ''),
                'sort_order' => (int) ($old['sort_order'] ?? 100),
                'is_active' => !empty($old['is_active']) ? 1 : 0,
            ];
        }
        view('admin/catalog_filter_form', [
            'title' => 'Nuevo filtro',
            'filter' => $filter,
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
            $this->formError($e->getMessage());
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
        $old = old_input();
        if ($old !== []) {
            foreach (['label', 'slug', 'sort_order'] as $k) {
                if (array_key_exists($k, $old)) {
                    $filter[$k] = $old[$k];
                }
            }
            $filter['is_active'] = !empty($old['is_active']) ? 1 : 0;
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
            $this->formError($e->getMessage());
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
        $routing = ['to' => '', 'cc' => ''];
        $audience = 'student';
        [$template, $selectedPlaceholders, $routing, $audience] = $this->mergeOldIntoMailTemplate(
            $template,
            $routing,
            $audience
        );

        view('admin/mail_template_edit', [
            'title' => 'Nueva plantilla de correo',
            'template' => $template,
            'placeholders' => $selectedPlaceholders,
            'selectedPlaceholders' => $selectedPlaceholders,
            'availablePlaceholders' => MailTemplateService::availablePlaceholderOptions(),
            'routing' => $routing,
            'audience' => $audience,
            'requiresFixedRecipient' => $audience === 'provider',
            'testEmailDefault' => trim((string) (Auth::user()['email'] ?? '')),
            'previewVars' => MailTemplateService::sampleVarsForPlaceholders($selectedPlaceholders),
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
        $audience = MailTemplateService::normalizeAudience((string) ($_POST['audience'] ?? 'student'));

        try {
            $toEmail = '';
            $ccEmail = trim((string) ($_POST['cc_email'] ?? ''));
            $this->assertMailCcValid($ccEmail);
            if ($audience === 'provider') {
                $toEmail = trim((string) ($_POST['to_email'] ?? ''));
                if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Indica un correo válido en Para (proveedor).');
                }
            }

            $svc->create(
                $code,
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['subject'] ?? '')),
                (string) ($_POST['body_html'] ?? ''),
                !empty($_POST['is_active']),
                $placeholders,
                (string) ($_POST['trigger_mode'] ?? 'manual')
            );
            $svc->saveAudience($code, $audience);
            $svc->saveRouting($code, $toEmail, $ccEmail);
            flash('success', 'Plantilla creada. Ya puedes editarla o probarla.');
            redirect('/admin/correos/' . $code);
        } catch (\Throwable $e) {
            $this->formError($e->getMessage());
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
        $routing = $svc->routing($effectiveCode);
        $audience = $svc->audience($effectiveCode);
        [$template, $selectedPlaceholders, $routing, $audience] = $this->mergeOldIntoMailTemplate(
            $template,
            $routing,
            $audience
        );
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
            'routing' => $routing,
            'audience' => $audience,
            'requiresFixedRecipient' => $audience === 'provider',
            'testEmailDefault' => old('test_email', trim((string) ($adminUser['email'] ?? ''))),
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
        $audience = MailTemplateService::normalizeAudience((string) ($_POST['audience'] ?? 'student'));
        $requiresFixed = $audience === 'provider';
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
            $svc->saveAudience($effectiveCode, $audience);

            $toEmail = '';
            if ($requiresFixed) {
                $toEmail = trim((string) ($_POST['to_email'] ?? ''));
                if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Indica un correo válido en Para (proveedor).');
                }
            }
            $ccEmail = trim((string) ($_POST['cc_email'] ?? ''));
            $this->assertMailCcValid($ccEmail);
            $svc->saveRouting($effectiveCode, $toEmail, $ccEmail);

            // Excel opcional ligado a la plantilla
            $wbPath = trim((string) ($_POST['workbook_template_path'] ?? ''));
            if (!empty($_POST['workbook_clear'])) {
                $wbPath = '';
            }
            if (isset($_FILES['workbook_file']) && is_array($_FILES['workbook_file'])
                && (int) ($_FILES['workbook_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ) {
                $docs = new \App\Services\DocumentService();
                $stored = $docs->storeUploaded($_FILES['workbook_file'], 'mail_workbooks', '.xlsx');
                $wbPath = $stored['path'];
            }
            $cells = $_POST['workbook_cells'] ?? [];
            $fields = $_POST['workbook_fields'] ?? [];
            $cellMap = [];
            if (is_array($cells) && is_array($fields)) {
                foreach ($cells as $i => $cell) {
                    $cellMap[] = [
                        'cell' => (string) $cell,
                        'field' => (string) ($fields[$i] ?? ''),
                    ];
                }
            }
            MailTemplateService::saveWorkbookConfig($effectiveCode, [
                'enabled' => !empty($_POST['workbook_enabled']) && $wbPath !== '',
                'template_path' => $wbPath,
                'sheet' => trim((string) ($_POST['workbook_sheet'] ?? '')),
                'normalize' => (string) ($_POST['workbook_normalize'] ?? 'none'),
                'cell_map' => $cellMap,
            ]);

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
            $this->formError($e->getMessage());
        }

        redirect('/admin/correos/' . $effectiveCode);
    }

    public function mailTemplateDelete(string $code): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $code = trim($code);
        try {
            $repo = new \App\Repositories\MailTemplateRepository();
            if ($repo->findByCode($code) === null) {
                throw new \InvalidArgumentException('Plantilla no encontrada.');
            }
            $repo->deleteByCode($code);
            flash('success', 'Plantilla eliminada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/correos');
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

    /** CC: correos fijos y/o placeholders como {{partner_email}}. */
    private function assertMailCcValid(string $ccEmail): void
    {
        $ccEmail = trim($ccEmail);
        if ($ccEmail === '') {
            return;
        }
        $parts = preg_split('/\s*,\s*/', $ccEmail) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\{\{\s*[a-zA-Z0-9_]+\s*\}\}$/', $part) === 1) {
                continue;
            }
            if (!filter_var($part, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException(
                    'CC inválido. Usa correos, {{partner_email}} o ambos separados por coma.'
                );
            }
        }
    }

    /** Conserva POST y muestra el error al volver al formulario. */
    private function formError(string $message, ?array $input = null): void
    {
        flash_form_error($message, $input);
    }

    /**
     * Normaliza $_FILES['campo'] con índices (campo[0], campo[1], …).
     *
     * @param mixed $files
     * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private function normalizeMultiFileUpload(mixed $files): array
    {
        if (!is_array($files) || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            $err = (int) ($files['error'] ?? \UPLOAD_ERR_NO_FILE);
            if ($err === \UPLOAD_ERR_NO_FILE) {
                return [];
            }

            return [0 => [
                'name' => (string) ($files['name'] ?? ''),
                'type' => (string) ($files['type'] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'] ?? ''),
                'error' => $err,
                'size' => (int) ($files['size'] ?? 0),
            ]];
        }

        $out = [];
        foreach ($files['name'] as $i => $name) {
            $err = (int) ($files['error'][$i] ?? \UPLOAD_ERR_NO_FILE);
            if ($err === \UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[(int) $i] = [
                'name' => (string) $name,
                'type' => (string) ($files['type'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => $err,
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $template
     * @param array{to:string,cc:string} $routing
     * @return array{0: array<string, mixed>, 1: list<string>, 2: array{to:string,cc:string}, 3: string}
     */
    private function mergeOldIntoMailTemplate(
        array $template,
        array $routing = ['to' => '', 'cc' => ''],
        string $audience = 'student'
    ): array {
        $audience = MailTemplateService::normalizeAudience($audience);
        $old = old_input();
        if ($old === []) {
            $selected = MailTemplateService::placeholdersForTemplate($template);

            return [$template, $selected, $routing, $audience];
        }
        foreach (['name', 'code', 'subject', 'body_html', 'trigger_mode'] as $key) {
            if (array_key_exists($key, $old)) {
                $template[$key] = $old[$key];
            }
        }
        if (array_key_exists('is_active', $old) || $old !== []) {
            $template['is_active'] = !empty($old['is_active']) ? 1 : 0;
        }
        $placeholders = [];
        if (isset($old['placeholders']) && is_array($old['placeholders'])) {
            $placeholders = MailTemplateService::sanitizePlaceholders(array_values($old['placeholders']));
        } else {
            $placeholders = MailTemplateService::placeholdersForTemplate($template);
        }
        if (array_key_exists('to_email', $old)) {
            $routing['to'] = (string) $old['to_email'];
        }
        if (array_key_exists('cc_email', $old)) {
            $routing['cc'] = (string) $old['cc_email'];
        }
        if (array_key_exists('audience', $old)) {
            $audience = MailTemplateService::normalizeAudience((string) $old['audience']);
        }

        return [$template, $placeholders, $routing, $audience];
    }

    /**
     * @param array<string, mixed>|null $product
     * @return array<string, mixed>|null
     */
    private function mergeOldIntoProduct(?array $product): ?array
    {
        $old = old_input();
        if ($old === []) {
            return $product;
        }
        $product = is_array($product) ? $product : [];
        $keys = [
            'code', 'name', 'slug', 'type', 'category', 'audience', 'product_group_id',
            'supplier_id', 'certifier_id', 'platform_type', 'moodle_course_id',
            'access_months', 'sort_order', 'short_description', 'description',
            'benefits_html', 'level_label', 'list_price', 'sale_price', 'partner_price',
            'config_json',
        ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $old)) {
                $product[$key] = $old[$key];
            }
        }
        $product['is_active'] = !empty($old['is_active']) ? 1 : 0;
        $product['is_public'] = !empty($old['is_public']) ? 1 : 0;
        $product['is_star'] = !empty($old['is_star']) ? 1 : 0;
        $product['is_level_exam'] = !empty($old['is_level_exam']) ? 1 : 0;
        $product['level_uses_cenni'] = !empty($old['level_uses_cenni']) ? 1 : 0;

        return $product;
    }

    /**
     * @param array<string, mixed> $view
     * @return array<string, mixed>
     */
    private function mergeOldIntoGroupForm(array $view): array
    {
        $old = old_input();
        if ($old === []) {
            return $view;
        }
        $group = is_array($view['group'] ?? null) ? $view['group'] : [];
        foreach (['name', 'code', 'supplier_id'] as $key) {
            if (array_key_exists($key, $old)) {
                $group[$key] = $old[$key];
            }
        }
        $view['group'] = $group;

        if (!empty($old['config_json']) && is_string($old['config_json'])) {
            $pretty = $old['config_json'];
            $decoded = json_decode($pretty, true);
            if (is_array($decoded)) {
                $pretty = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            $view['defaultConfig'] = $pretty;
            $view['extras'] = ProductAdminService::groupFormExtrasFromConfig($pretty);
        }

        $extras = is_array($view['extras'] ?? null) ? $view['extras'] : [];
        $scalarExtras = [
            'exam_slot_minutes', 'exam_validity_months', 'schedule_min_advance_days',
            'schedule_weekdays_start', 'schedule_weekdays_end', 'schedule_saturday_start',
            'schedule_saturday_end', 'reglamento_template_path', 'reglamento_source_url',
            'reglamento_doc_code', 'pipeline_code', 'initial_step_code',
        ];
        foreach ($scalarExtras as $key) {
            if (array_key_exists($key, $old)) {
                $extras[$key] = $old[$key];
            }
        }
        foreach ([
            'exam_choose_at_checkout', 'schedule_available_365', 'reglamento_enabled',
            'pay_transfer', 'pay_oxxo', 'pay_card', 'msi_enabled',
            'email_registration_enabled', 'email_payment_enabled',
        ] as $flag) {
            if ($old !== []) {
                // Si el POST no trae el checkbox, queda apagado.
                if (str_starts_with($flag, 'email_') || str_starts_with($flag, 'pay_') || $flag === 'msi_enabled'
                    || $flag === 'exam_choose_at_checkout' || $flag === 'schedule_available_365'
                    || $flag === 'reglamento_enabled'
                ) {
                    // Solo override flags that exist as form fields when we have old input
                    if (array_key_exists($flag, $old) || in_array($flag, [
                        'exam_choose_at_checkout', 'schedule_available_365', 'reglamento_enabled',
                        'pay_transfer', 'pay_oxxo', 'pay_card', 'msi_enabled',
                        'email_registration_enabled', 'email_payment_enabled',
                    ], true)) {
                        $extras[$flag] = !empty($old[$flag]);
                    }
                }
            }
        }
        if (isset($old['checkout_fields']) && is_array($old['checkout_fields'])) {
            $extras['checkout_fields'] = array_values(array_map('strval', $old['checkout_fields']));
        }
        if (isset($old['msi_months']) && is_array($old['msi_months'])) {
            $extras['msi_months'] = array_map('intval', $old['msi_months']);
        }
        if (isset($old['schedule_days']) && is_array($old['schedule_days'])) {
            $days = [0 => false, 1 => false, 2 => false, 3 => false, 4 => false, 5 => false, 6 => false];
            foreach ($old['schedule_days'] as $d => $on) {
                $days[(int) $d] = !empty($on);
            }
            $extras['schedule_days'] = $days;
        }
        if (array_key_exists('email_registration_template', $old) || array_key_exists('email_payment_template', $old)) {
            $emails = is_array($extras['emails'] ?? null) ? $extras['emails'] : [];
            $reg = is_array($emails['student_registration'] ?? null) ? $emails['student_registration'] : [];
            $pay = is_array($emails['student_payment_confirmed'] ?? null) ? $emails['student_payment_confirmed'] : [];
            $reg['enabled'] = !empty($old['email_registration_enabled']);
            $pay['enabled'] = !empty($old['email_payment_enabled']);
            if (array_key_exists('email_registration_template', $old)) {
                $reg['template_code'] = (string) $old['email_registration_template'];
            }
            if (array_key_exists('email_payment_template', $old)) {
                $pay['template_code'] = (string) $old['email_payment_template'];
            }
            $emails['student_registration'] = $reg;
            $emails['student_payment_confirmed'] = $pay;
            $extras['emails'] = $emails;
        }

        if (isset($old['pipeline_steps']) && is_array($old['pipeline_steps'])) {
            $steps = [];
            $defs = [];
            foreach ($old['pipeline_steps'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = \App\Services\GroupStepConfig::normalizeCode((string) ($row['code'] ?? ''));
                if ($code === '') {
                    $code = \App\Services\GroupStepConfig::normalizeCode((string) ($row['label'] ?? ''));
                }
                if ($code === '') {
                    continue;
                }
                $steps[] = [
                    'code' => $code,
                    'label' => (string) ($row['label'] ?? $code),
                    'actor' => (string) ($row['actor'] ?? 'admin'),
                    'is_terminal' => !empty($row['is_terminal']),
                ];
                $defs[$code] = \App\Services\GroupStepConfig::normalizeDef($code, [
                    'code' => $code,
                    'label' => (string) ($row['label'] ?? $code),
                    'actor' => (string) ($row['actor'] ?? 'admin'),
                    'admin_only' => !empty($row['admin_only']),
                    'ops_button' => !empty($row['ops_button']),
                    'ops_label' => (string) ($row['ops_label'] ?? ''),
                    'action' => (string) ($row['action'] ?? 'none'),
                    'email' => [
                        'enabled' => !empty($row['email_enabled']),
                        'trigger' => (string) ($row['email_trigger'] ?? 'admin'),
                        'template_code' => (string) ($row['email_template'] ?? ''),
                        'audience' => '',
                    ],
                ]);
            }
            $extras['step_defs'] = $defs;
            $pipelineCode = trim((string) ($old['pipeline_code'] ?? ($extras['pipeline_code'] ?? '')));
            if ($pipelineCode !== '') {
                $byCode = is_array($view['pipelineStepsByCode'] ?? null) ? $view['pipelineStepsByCode'] : [];
                $byCode[$pipelineCode] = $steps;
                $view['pipelineStepsByCode'] = $byCode;
                $extras['pipeline_code'] = $pipelineCode;
            }
            if (array_key_exists('initial_step_code', $old)) {
                $extras['initial_step_code'] = (string) $old['initial_step_code'];
            }
        }

        $view['extras'] = $extras;

        return $view;
    }
}

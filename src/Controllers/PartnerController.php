<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductRepository;
use App\Repositories\TrackingRepository;
use App\Services\CatalogFilterService;
use App\Services\CheckoutRequirements;
use App\Services\DocumentService;
use App\Services\GroupStepConfig;
use App\Services\MailLogService;
use App\Services\ExamScheduleService;
use App\Services\PartnerBulkRegistrationService;
use App\Services\PartnerProfileService;
use App\Services\PartnerRegistrationService;
use App\Services\PartnerTierService;
use App\Services\PricingService;
use App\Services\ResultsDeliveryService;
use App\Services\TrackingService;

final class PartnerController
{
    public function dashboard(): void
    {
        $this->studentsBoard();
    }

    public function studentsBoard(): void
    {
        Auth::requireRole(['partner']);
        $partner = $this->requirePartner();
        $this->syncPendingCredits($partner);

        $trackings = (new TrackingRepository())->forPartner((int) $partner['id']);
        $accessMails = (new MailLogService())->latestAccessMailByTrackingIds(
            array_map(static fn (array $t): int => (int) ($t['id'] ?? 0), $trackings)
        );

        view('partner/students', [
            'title' => 'Alumnos',
            'partner' => $partner,
            'trackings' => $trackings,
            'accessMails' => $accessMails,
            'layout' => 'partner',
        ]);
    }

    public function progress(): void
    {
        Auth::requireRole(['partner']);
        $partner = $this->requirePartner();
        $tierProgress = null;
        try {
            $tierProgress = (new PartnerTierService())->progressForPartner($partner);
        } catch (\Throwable $e) {
            error_log('[Doceo] partner tier progress: ' . $e->getMessage());
        }
        view('partner/progress', [
            'title' => 'Avance / niveles',
            'partner' => $partner,
            'tierProgress' => $tierProgress,
            'layout' => 'partner',
        ]);
    }

    public function profileForm(): void
    {
        Auth::requireRole(['partner']);
        try {
            $partner = (new PartnerProfileService())->profileForUser((int) Auth::id());
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/partner');
        }
        view('partner/profile', [
            'title' => 'Mi perfil',
            'partner' => $partner,
            'layout' => 'partner',
        ]);
    }

    public function profileSave(): void
    {
        Auth::requireRole(['partner']);
        csrf_verify();
        try {
            (new PartnerProfileService())->update((int) Auth::id(), [
                'display_name' => (string) ($_POST['display_name'] ?? ''),
                'first_name' => (string) ($_POST['first_name'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'code' => (string) ($_POST['code'] ?? ''),
                'current_password' => (string) ($_POST['current_password'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
                'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
            ]);
            flash('success', 'Perfil actualizado.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/partner/perfil');
    }

    public function schoolPlaceholder(): void
    {
        Auth::requireRole(['partner']);
        $partner = $this->requirePartner();
        view('partner/school_placeholder', [
            'title' => 'Mi escuela',
            'partner' => $partner,
            'layout' => 'partner',
        ]);
    }

    public function bulkForm(): void
    {
        Auth::requireRole(['partner']);
        $partner = $this->requirePartner();
        $repo = new ProductRepository();
        $products = $repo->publicCatalog('all', null, false, null, null, 'certificaciones');
        $pricing = new PricingService();
        $priced = [];
        foreach ($products as $p) {
            if (!in_array((string) ($p['type'] ?? ''), ['certification', 'procedure'], true)) {
                continue;
            }
            $p['partner_price'] = $pricing->partnerPriceForProduct($p, (string) $partner['tier']);
            $priced[] = $p;
        }
        $batches = (new PartnerBulkRegistrationService())->listForPartner((int) $partner['id']);
        $selectedId = (int) ($_GET['producto'] ?? 0);
        $preview = null;
        $sessionPreview = $_SESSION['partner_bulk_preview'] ?? null;
        if (is_array($sessionPreview) && (string) ($_GET['paso'] ?? '') === 'comprobante') {
            $preview = $sessionPreview;
            if ($selectedId < 1) {
                $selectedId = (int) ($preview['product_id'] ?? 0);
            }
        }
        $selected = null;
        foreach ($priced as $p) {
            if ((int) $p['id'] === $selectedId) {
                $selected = $p;
                break;
            }
        }
        view('partner/bulk_register', [
            'title' => 'Registrar grupo',
            'partner' => $partner,
            'products' => $priced,
            'selected' => $selected,
            'batches' => $batches,
            'preview' => $preview,
            'layout' => 'partner',
        ]);
    }

    public function bulkCsvTemplate(): void
    {
        Auth::requireRole(['partner']);
        $csv = (new PartnerBulkRegistrationService())->csvTemplate();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla-alumnos-grupo.csv"');
        echo $csv;
        exit;
    }

    public function bulkPreview(): void
    {
        Auth::requireRole(['partner']);
        csrf_verify();
        $partner = $this->requirePartner();
        $productId = (int) ($_POST['product_id'] ?? 0);
        $examDate = trim((string) ($_POST['exam_date'] ?? ''));
        $examTime = trim((string) ($_POST['exam_time'] ?? ''));
        $file = $_FILES['students_csv'] ?? null;
        if ($productId < 1) {
            flash('error', 'Elige un producto.');
            redirect('/partner/registrar-grupo');
        }
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Sube el CSV de alumnos.');
            redirect('/partner/registrar-grupo?producto=' . $productId);
        }
        try {
            $svc = new PartnerBulkRegistrationService();
            $preview = $svc->parseCsv((string) $file['tmp_name']);
            $product = (new ProductRepository())->find($productId);
            if ($product === null) {
                throw new \InvalidArgumentException('Producto no encontrado.');
            }
            $unit = (new PricingService())->partnerPriceForProduct($product, (string) $partner['tier']);
            if (
                $examDate !== ''
                && $examTime !== ''
                && ExamScheduleService::needsExamAtCheckout($product)
            ) {
                (new ExamScheduleService())->validateSelection($product, $examDate, $examTime);
            } elseif (
                in_array((string) ($product['type'] ?? ''), ['certification', 'procedure'], true)
                && ($examDate === '' || $examTime === '')
            ) {
                throw new \InvalidArgumentException('Indica fecha y hora de examen.');
            }
            // Guardar CSV temporal para el submit final.
            $tmpDir = BASE_PATH . '/storage/tmp/partner_bulk';
            if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
                throw new \RuntimeException('No se pudo preparar almacenamiento temporal.');
            }
            $token = bin2hex(random_bytes(16));
            $dest = $tmpDir . '/' . $token . '.csv';
            if (!@move_uploaded_file((string) $file['tmp_name'], $dest) && !@copy((string) $file['tmp_name'], $dest)) {
                throw new \RuntimeException('No se pudo guardar el CSV temporal.');
            }
            $_SESSION['partner_bulk_preview'] = [
                'token' => $token,
                'product_id' => $productId,
                'exam_date' => $examDate,
                'exam_time' => $examTime,
                'path' => $dest,
                'valid_count' => $preview['valid_count'],
                'errors' => $preview['errors'],
                'unit_price' => $unit,
                'expected_amount' => round($unit * (int) $preview['valid_count'], 2),
                'sample_rows' => array_slice($preview['rows'], 0, 8),
            ];
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/partner/registrar-grupo?producto=' . $productId);
        }
        redirect('/partner/registrar-grupo?producto=' . $productId . '&paso=comprobante');
    }

    public function bulkSubmit(): void
    {
        Auth::requireRole(['partner']);
        csrf_verify();
        $partner = $this->requirePartner();
        $session = $_SESSION['partner_bulk_preview'] ?? null;
        if (!is_array($session) || empty($session['path']) || empty($session['token'])) {
            flash('error', 'La vista previa del CSV expiró. Vuelve a subir el archivo.');
            redirect('/partner/registrar-grupo');
        }
        $token = (string) ($_POST['preview_token'] ?? '');
        if ($token === '' || !hash_equals((string) $session['token'], $token)) {
            flash('error', 'Token de vista previa inválido. Vuelve a subir el CSV.');
            redirect('/partner/registrar-grupo');
        }
        $proof = $_FILES['payment_proof'] ?? null;
        try {
            $svc = new PartnerBulkRegistrationService();
            $parsed = $svc->parseCsv((string) $session['path']);
            if ($parsed['valid_count'] < 1) {
                throw new \InvalidArgumentException('No hay alumnos válidos en el CSV.');
            }
            $result = $svc->createBatch(
                (int) Auth::id(),
                (int) $session['product_id'],
                (string) ($session['exam_date'] ?? ''),
                (string) ($session['exam_time'] ?? ''),
                $parsed['rows'],
                is_array($proof) ? $proof : []
            );
            @unlink((string) $session['path']);
            unset($_SESSION['partner_bulk_preview']);
            flash(
                'success',
                'Lote #' . $result['batch_id'] . ' creado: ' . $result['student_count']
                . ' alumno(s) · monto ' . money($result['expected_amount']) . ' en revisión de pago.'
            );
            redirect('/partner/alumnos');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/partner/registrar-grupo?producto=' . (int) ($session['product_id'] ?? 0) . '&paso=comprobante');
        }
    }

    public function registerForm(): void
    {
        Auth::requireRole(['partner']);
        $svc = new PartnerRegistrationService();
        try {
            $partner = $svc->partnerForUser((int) Auth::id());
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/partner');
        }

        $repo = new ProductRepository();
        $section = ProductRepository::normalizeCatalogSection(
            is_string($_GET['seccion'] ?? null) ? (string) $_GET['seccion'] : 'certificaciones'
        );
        if ($section === 'all') {
            $section = 'certificaciones';
        }
        $filter = $_GET['filtro'] ?? $_GET['categoria'] ?? 'all';
        $filter = is_string($filter) ? $filter : 'all';
        $q = $_GET['q'] ?? '';
        $q = is_string($q) ? trim($q) : '';

        $sectionCounts = [
            'certificaciones' => $repo->publicCatalogCount('all', null, false, 'certificaciones'),
            'cursos' => $repo->publicCatalogCount('all', null, false, 'cursos'),
        ];
        $catalogFilters = (new CatalogFilterService())->catalogFilters($section);
        $products = $repo->publicCatalog($filter, $q !== '' ? $q : null, false, null, null, $section);
        $pricing = new PricingService();
        $priced = [];
        foreach ($products as $p) {
            $p['partner_price'] = $pricing->partnerPriceForProduct($p, (string) $partner['tier']);
            $priced[] = $p;
        }

        view('partner/register', [
            'title' => 'Registrar alumno',
            'partner' => $partner,
            'user' => Auth::user(),
            'products' => $priced,
            'catalogFilters' => $catalogFilters,
            'filter' => $filter,
            'q' => $q,
            'section' => $section,
            'sectionCounts' => $sectionCounts,
            'layout' => 'partner',
        ]);
    }

    public function registerSubmit(): void
    {
        Auth::requireRole(['partner']);
        flash('info', 'Elige el producto desde el catálogo para registrar al alumno con el flujo completo.');
        redirect('/partner/registrar');
    }

    public function caseShow(string $id): void
    {
        Auth::requireRole(['partner']);
        $svc = new TrackingService();
        $tracking = $svc->find((int) $id);
        $partner = $this->requirePartner();
        if ($tracking === null || (int) ($tracking['partner_id'] ?? 0) !== (int) $partner['id']) {
            http_response_code(403);
            view('errors/403', ['title' => 'Sin acceso', 'layout' => 'partner']);

            return;
        }
        $pipelineId = (int) ($tracking['pipeline_template_id'] ?? 0);
        $steps = $pipelineId > 0 ? $svc->steps($pipelineId) : [];
        $cfg = CheckoutRequirements::config($tracking);
        $defs = GroupStepConfig::defsFromConfig($cfg);
        $steps = GroupStepConfig::visibleToStudent($steps, $defs, $cfg);
        $product = [
            'type' => $tracking['product_type'] ?? '',
            'product_type' => $tracking['product_type'] ?? '',
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
        ];
        $studentEmail = (string) ($tracking['student_email'] ?? '');
        $mailLogs = (new MailLogService())->studentMailsForTracking(
            (int) $tracking['id'],
            (int) ($tracking['purchase_id'] ?? 0) ?: null,
            $studentEmail
        );
        $resultsState = ResultsDeliveryService::stateFromTracking($tracking);
        $delivery = ResultsDeliveryService::fromConfig($cfg);

        view('partner/case', [
            'title' => 'Caso ' . $tracking['matricula'],
            'partner' => $partner,
            'tracking' => $tracking,
            'steps' => $steps,
            'registrationFields' => CheckoutRequirements::fieldsForProduct($product),
            'canEditRegistration' => TrackingService::canEditRegistration($tracking),
            'mailLogs' => $mailLogs,
            'resultsState' => $resultsState,
            'resultsDelivery' => $delivery,
            'layout' => 'partner',
        ]);
    }

    public function resultsPdf(string $id): void
    {
        Auth::requireRole(['partner']);
        $tracking = (new TrackingService())->find((int) $id);
        $partner = $this->requirePartner();
        if ($tracking === null || (int) ($tracking['partner_id'] ?? 0) !== (int) $partner['id']) {
            http_response_code(403);
            exit('Sin acceso');
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
        DocumentService::streamFile($path, basename($name), 'application/pdf');
    }

    public function updateRegistration(string $id): void
    {
        Auth::requireRole(['partner']);
        csrf_verify();
        $trackingId = (int) $id;
        $svc = new TrackingService();
        $tracking = $svc->find($trackingId);
        $partner = $this->requirePartner();
        if ($tracking === null || (int) ($tracking['partner_id'] ?? 0) !== (int) $partner['id']) {
            http_response_code(403);
            view('errors/403', ['title' => 'Sin acceso', 'layout' => 'partner']);

            return;
        }
        try {
            $svc->updateStudentProfile($trackingId, [
                'first_name' => (string) ($_POST['first_name'] ?? ''),
                'last_name_p' => (string) ($_POST['last_name_p'] ?? ''),
                'last_name_m' => (string) ($_POST['last_name_m'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'curp' => (string) ($_POST['curp'] ?? ''),
                'birth_date' => (string) ($_POST['birth_date'] ?? ''),
                'sex' => (string) ($_POST['sex'] ?? ''),
                'nationality' => (string) ($_POST['nationality'] ?? ''),
            ], (int) Auth::id());
            flash('success', 'Datos del alumno actualizados.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/partner/caso/' . $trackingId);
    }

    public function updateExam(string $id): void
    {
        Auth::requireRole(['partner']);
        csrf_verify();
        $trackingId = (int) $id;
        flash('error', 'Solo DOCEO puede asignar o reagendar la fecha de examen. Contáctanos para el cambio.');
        redirect('/partner/caso/' . $trackingId);
    }

    /** @return array<string, mixed> */
    private function requirePartner(): array
    {
        try {
            return (new PartnerRegistrationService())->partnerForUser((int) Auth::id());
        } catch (\Throwable) {
            flash('error', 'Tu usuario partner no tiene ficha activa.');
            redirect('/partner');
            exit;
        }
    }

    /** @param array<string, mixed> $partner */
    private function syncPendingCredits(array &$partner): void
    {
        try {
            $sync = (new \App\Services\CheckoutService())
                ->applyPendingPartnerCreditsForPartner((int) $partner['id']);
            if (($sync['applied'] ?? 0) > 0) {
                $pdo = \App\Database\Connection::get();
                $stmt = $pdo->prepare('SELECT * FROM partners WHERE id = ? LIMIT 1');
                $stmt->execute([(int) $partner['id']]);
                $partner = $stmt->fetch() ?: $partner;
                flash(
                    'success',
                    'Se abonó crédito pendiente por ' . money((float) $sync['amount'])
                    . ' (' . (int) $sync['applied'] . ' compra(s)).'
                );
            }
        } catch (\Throwable $e) {
            error_log('[Doceo] Partner credit sync: ' . $e->getMessage());
        }
    }
}

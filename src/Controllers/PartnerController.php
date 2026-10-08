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

    public function bulkPlaceholder(): void
    {
        Auth::requireRole(['partner']);
        $partner = $this->requirePartner();
        view('partner/bulk_placeholder', [
            'title' => 'Registrar grupo',
            'partner' => $partner,
            'layout' => 'partner',
        ]);
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

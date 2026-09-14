<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductRepository;
use App\Repositories\TrackingRepository;
use App\Services\CatalogFilterService;
use App\Services\PartnerRegistrationService;
use App\Services\PricingService;
use App\Services\TrackingService;

final class PartnerController
{
    public function dashboard(): void
    {
        Auth::requireRole(['partner']);
        $pdo = \App\Database\Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM partners WHERE user_id = ? LIMIT 1');
        $stmt->execute([Auth::id()]);
        $partner = $stmt->fetch() ?: null;
        $trackings = [];
        if ($partner) {
            $trackings = (new TrackingRepository())->forPartner((int) $partner['id']);
        }
        view('partner/dashboard', [
            'title' => 'Partner',
            'partner' => $partner,
            'trackings' => $trackings,
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
        // Sin paginación: el partner ve todo el listado filtrado y puede refinar en vivo.
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
        // El registro corto quedó deprecado: el partner usa /adquirir/{slug}.
        Auth::requireRole(['partner']);
        flash('info', 'Elige el producto desde el catálogo para registrar al alumno con el flujo completo.');
        redirect('/partner/registrar');
    }

    public function caseShow(string $id): void
    {
        Auth::requireRole(['partner']);
        $svc = new TrackingService();
        $tracking = $svc->find((int) $id);
        $partner = null;
        try {
            $partner = (new PartnerRegistrationService())->partnerForUser((int) Auth::id());
        } catch (\Throwable) {
            redirect('/partner');
        }
        if ($tracking === null || (int) ($tracking['partner_id'] ?? 0) !== (int) $partner['id']) {
            http_response_code(403);
            view('errors/403', ['title' => 'Sin acceso', 'layout' => 'partner']);

            return;
        }
        $pipelineId = (int) ($tracking['pipeline_template_id'] ?? 0);
        $steps = $pipelineId > 0 ? $svc->steps($pipelineId) : [];
        $cfg = \App\Services\CheckoutRequirements::config($tracking);
        $defs = \App\Services\GroupStepConfig::defsFromConfig($cfg);
        // Ocultar pasos marcados «Solo admin (oculto al alumno)» también en la ficha partner.
        $steps = \App\Services\GroupStepConfig::visibleToStudent($steps, $defs, $cfg);
        view('partner/case', [
            'title' => 'Caso ' . $tracking['matricula'],
            'partner' => $partner,
            'tracking' => $tracking,
            'steps' => $steps,
            'canEditRegistration' => TrackingService::canEditRegistration($tracking),
            'layout' => 'partner',
        ]);
    }

    public function updateRegistration(string $id): void
    {
        Auth::requireRole(['partner']);
        csrf_verify();
        $trackingId = (int) $id;
        $svc = new TrackingService();
        $tracking = $svc->find($trackingId);
        $partner = null;
        try {
            $partner = (new PartnerRegistrationService())->partnerForUser((int) Auth::id());
        } catch (\Throwable) {
            redirect('/partner');
        }
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
        // Reagenda / cambio de fecha solo por administración.
        flash('error', 'Solo DOCEO puede asignar o reagendar la fecha de examen. Contáctanos para el cambio.');
        redirect('/partner/caso/' . $trackingId);
    }
}

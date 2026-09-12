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
        $steps = \App\Services\GroupStepConfig::visibleToStudent($steps, $defs);
        view('partner/case', [
            'title' => 'Caso ' . $tracking['matricula'],
            'partner' => $partner,
            'tracking' => $tracking,
            'steps' => $steps,
            'layout' => 'partner',
        ]);
    }

    public function updateExam(string $id): void
    {
        Auth::requireRole(['partner']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            $partner = (new PartnerRegistrationService())->partnerForUser((int) Auth::id());
            $tracking = (new TrackingService())->find($trackingId);
            if ($tracking === null || (int) ($tracking['partner_id'] ?? 0) !== (int) $partner['id']) {
                throw new \InvalidArgumentException('No puedes editar este caso.');
            }
            // Partner solo define fecha/hora principal; reagenda y Zoom los maneja admin.
            (new TrackingService())->saveExamSchedule($trackingId, [
                'exam_date' => $_POST['exam_date'] ?? null,
                'exam_time' => $_POST['exam_time'] ?? null,
                'notify' => !empty($_POST['notify']),
            ], (int) Auth::id());
            flash('success', 'Fecha de examen guardada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/partner/caso/' . $trackingId);
    }
}

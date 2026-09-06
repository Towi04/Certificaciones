<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\ProductRepository;
use App\Repositories\TrackingRepository;
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

        // Catálogo público (mismo que ve un alumno), con precio del nivel partner.
        $products = (new ProductRepository())->publicCatalog('all', null);
        $pricing = new PricingService();
        $priced = [];
        foreach ($products as $p) {
            $p['partner_price'] = $pricing->partnerPriceForProduct($p, (string) $partner['tier']);
            $priced[] = $p;
        }

        view('partner/register', [
            'title' => 'Registrar alumno',
            'partner' => $partner,
            'products' => $priced,
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
        view('partner/case', [
            'title' => 'Caso ' . $tracking['matricula'],
            'partner' => $partner,
            'tracking' => $tracking,
            'steps' => $pipelineId > 0 ? $svc->steps($pipelineId) : [],
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

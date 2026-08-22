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
        $products = (new ProductRepository())->adminList(null);
        $products = array_values(array_filter(
            $products,
            static fn (array $p) => (int) $p['is_active'] === 1
        ));
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
        Auth::requireRole(['partner']);
        csrf_verify();
        try {
            $result = (new PartnerRegistrationService())->register(
                (int) Auth::id(),
                (int) ($_POST['product_id'] ?? 0),
                [
                    'email' => trim((string) ($_POST['email'] ?? '')),
                    'first_name' => trim((string) ($_POST['first_name'] ?? '')),
                    'last_name_p' => trim((string) ($_POST['last_name_p'] ?? '')),
                    'last_name_m' => trim((string) ($_POST['last_name_m'] ?? '')),
                    'phone' => trim((string) ($_POST['phone'] ?? '')),
                ],
                [
                    'exam_date' => trim((string) ($_POST['exam_date'] ?? '')),
                    'exam_time' => trim((string) ($_POST['exam_time'] ?? '')),
                ]
            );
            $msg = 'Alumno registrado · matrícula ' . $result['matricula'];
            if ($result['created_account'] && $result['plain_password']) {
                $msg .= ' · contraseña temporal ' . $result['plain_password'];
            }
            flash('success', $msg);
            redirect('/partner/caso/' . $result['tracking_id']);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/partner/registrar');
        }
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

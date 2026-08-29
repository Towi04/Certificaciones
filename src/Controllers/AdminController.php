<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Database\Connection;
use App\Repositories\ProductGroupRepository;
use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\TrackingRepository;
use App\Services\CheckoutService;
use App\Services\CheckoutRequirements;
use App\Services\ExportService;
use App\Services\ImportService;
use App\Integrations\Mailer;
use App\Services\MailTemplateService;
use App\Services\ProductMediaService;
use App\Services\TrackingService;
use App\Services\UksEletService;
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
        $products = (new ProductRepository())->adminList($q);
        view('admin/products', [
            'title' => 'Productos',
            'products' => $products,
            'q' => $q ?? '',
            'layout' => 'admin',
        ]);
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

        $groups = (new ProductGroupRepository())->all();

        view('admin/product_edit', [
            'title' => 'Editar · ' . $product['name'],
            'product' => $product,
            'media' => $media,
            'groups' => $groups,
            'layout' => 'admin',
        ]);
    }

    public function productUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $repo = new ProductRepository();
        $product = $repo->find((int) $id);
        if ($product === null) {
            flash('error', 'Producto no encontrado.');
            redirect('/admin/productos');
        }

        $platform = (string) ($_POST['platform_type'] ?? $product['platform_type'] ?? 'none');
        if (!in_array($platform, ['none', 'moodle', 'provider'], true)) {
            $platform = 'none';
        }
        $groupRaw = trim((string) ($_POST['product_group_id'] ?? ''));
        $groupId = $groupRaw === '' ? null : (int) $groupRaw;
        if ($groupId !== null && $groupId < 1) {
            $groupId = null;
        }
        if ($groupId !== null && (new ProductGroupRepository())->find($groupId) === null) {
            $groupId = null;
        }
        $courseIdRaw = trim((string) ($_POST['moodle_course_id'] ?? ''));
        $courseId = $courseIdRaw === '' ? null : (int) $courseIdRaw;
        if ($courseId !== null && $courseId < 1) {
            $courseId = null;
        }
        $months = (int) ($_POST['access_months'] ?? ($product['access_months'] ?? 6));
        if ($months < 1) {
            $months = 6;
        }
        if ($months > 60) {
            $months = 60;
        }

        try {
            $repo->update((int) $id, [
                'platform_type' => $platform,
                'moodle_course_id' => $courseId,
                'access_months' => $months,
                'product_group_id' => $groupId,
            ]);
            flash('success', 'Producto actualizado. Si es Moodle, usa Sincronizar Moodle en el caso o confirma un pago de prueba.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/productos/' . (int) $id);
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
        $partners = (new \App\Repositories\PartnerRepository())->adminList($q !== '' ? $q : null);
        view('admin/partners', [
            'title' => 'Partners',
            'partners' => $partners,
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
        view('admin/mail_templates', [
            'title' => 'Plantillas de correo',
            'templates' => $svc->all(),
            'layout' => 'admin',
        ]);
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

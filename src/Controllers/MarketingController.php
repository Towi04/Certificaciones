<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\CertifierRepository;
use App\Repositories\ProductRepository;
use App\Services\MailTemplateService;
use App\Services\MarketingCampaignService;
use App\Services\MarketingContactService;
use App\Support\Pagination;

final class MarketingController
{
    public function campaigns(): void
    {
        Auth::requireRole(['admin']);
        $svc = new MarketingCampaignService();
        view('admin/marketing_campaigns', [
            'title' => 'Publicidad',
            'layout' => 'admin',
            'campaigns' => $svc->listCampaigns(),
        ]);
    }

    public function campaignCreateForm(): void
    {
        Auth::requireRole(['admin']);
        view('admin/marketing_campaign_form', $this->formData(null));
    }

    public function campaignStore(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $id = (new MarketingCampaignService())->create($_POST, Auth::id());
            flash('success', 'Campaña creada. Revisa la audiencia y pulsa «Iniciar envío».');
            redirect('/admin/publicidad/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/publicidad/nueva');
        }
    }

    public function campaignShow(string $id): void
    {
        Auth::requireRole(['admin']);
        $campaignId = (int) $id;
        $svc = new MarketingCampaignService();
        $campaign = $svc->find($campaignId);
        if ($campaign === null) {
            view('errors/404', ['title' => 'Campaña no encontrada', 'layout' => 'admin']);

            return;
        }
        $audience = json_decode((string) ($campaign['audience_json'] ?? '{}'), true);
        if (!is_array($audience)) {
            $audience = [];
        }
        $preview = ['count' => 0, 'samples' => []];
        if (in_array((string) $campaign['status'], ['draft', 'paused'], true)) {
            try {
                $preview = $svc->previewAudience($audience);
            } catch (\Throwable) {
                $preview = ['count' => 0, 'samples' => []];
            }
        }
        $statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : null;
        if ($statusFilter === '') {
            $statusFilter = null;
        }
        $counts = $svc->recipientCounts($campaignId);
        $pagination = Pagination::fromRequest($counts['total'] ?? 0, 50);

        view('admin/marketing_campaign_show', [
            'title' => (string) $campaign['name'],
            'layout' => 'admin',
            'campaign' => $campaign,
            'audience' => $audience,
            'preview' => $preview,
            'counts' => $counts,
            'recipients' => $svc->recipients($campaignId, $statusFilter, $pagination['limit'], $pagination['offset']),
            'statusFilter' => $statusFilter,
            'pagination' => $pagination,
            'templates' => (new MailTemplateService())->all(),
            'products' => (new ProductRepository())->adminList(null, 500, 0, []),
            'certifiers' => (new CertifierRepository())->all(),
        ]);
    }

    public function campaignEditForm(string $id): void
    {
        Auth::requireRole(['admin']);
        $campaign = (new MarketingCampaignService())->find((int) $id);
        if ($campaign === null) {
            view('errors/404', ['title' => 'Campaña no encontrada', 'layout' => 'admin']);

            return;
        }
        if (!in_array((string) $campaign['status'], ['draft', 'paused'], true)) {
            flash('error', 'Solo se pueden editar campañas en borrador o pausadas.');
            redirect('/admin/publicidad/' . (int) $id);
        }
        view('admin/marketing_campaign_form', $this->formData($campaign));
    }

    public function campaignUpdate(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new MarketingCampaignService())->update((int) $id, $_POST);
            flash('success', 'Campaña actualizada.');
            redirect('/admin/publicidad/' . (int) $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/publicidad/' . (int) $id . '/editar');
        }
    }

    public function campaignStart(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            $result = (new MarketingCampaignService())->start((int) $id);
            flash(
                'success',
                'Envío programado: ' . (int) $result['recipients'] . ' destinatarios · estado '
                . (string) $result['status'] . '. El cron irá enviando de forma escalonada.'
            );
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/publicidad/' . (int) $id);
    }

    public function campaignPause(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new MarketingCampaignService())->pause((int) $id);
            flash('success', 'Campaña pausada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/publicidad/' . (int) $id);
    }

    public function campaignResume(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new MarketingCampaignService())->resume((int) $id);
            flash('success', 'Campaña reanudada.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/publicidad/' . (int) $id);
    }

    public function campaignCancel(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        try {
            (new MarketingCampaignService())->cancel((int) $id);
            flash('success', 'Campaña cancelada. Los pendientes no se enviarán.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/publicidad/' . (int) $id);
    }

    public function campaignPreview(): void
    {
        Auth::requireRole(['admin']);
        header('Content-Type: application/json; charset=utf-8');
        $includeClients = !empty($_GET['include_clients'])
            || !empty($_GET['include_students'])
            || !empty($_GET['include_legacy']);
        $includePartners = !empty($_GET['include_partners']);
        if (!$includeClients && !$includePartners) {
            $includeClients = true;
        }
        $onlyDirect = !empty($_GET['only_direct_clients']) || !empty($_GET['doceo_direct']);
        $audience = [
            'include_clients' => $includeClients,
            'include_partners' => $includePartners,
            'only_direct_clients' => $onlyDirect,
            'doceo_direct' => $onlyDirect,
            'product_id' => (int) ($_GET['product_id'] ?? 0) ?: null,
            'certifier_id' => (int) ($_GET['certifier_id'] ?? 0) ?: null,
        ];
        try {
            $preview = (new MarketingCampaignService())->previewAudience($audience);
            echo json_encode(['ok' => true] + $preview, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function contacts(): void
    {
        Auth::requireRole(['admin']);
        $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $svc = new MarketingContactService();
        $total = $svc->count($q !== '' ? $q : null);
        $pagination = Pagination::fromRequest($total, 50);
        view('admin/marketing_contacts', [
            'title' => 'Clientes anteriores',
            'layout' => 'admin',
            'contacts' => $svc->list($q !== '' ? $q : null, $pagination['limit'], $pagination['offset']),
            'q' => $q,
            'total' => $total,
            'pagination' => $pagination,
        ]);
    }

    public function contactsImport(): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Sube un archivo CSV válido.');
            redirect('/admin/publicidad/contactos');
        }
        try {
            $result = (new MarketingContactService())->importCsv((string) $file['tmp_name']);
            $msg = 'Importación: ' . (int) $result['imported'] . ' nuevos, '
                . (int) $result['updated'] . ' actualizados, '
                . (int) $result['skipped'] . ' omitidos.';
            if ($result['errors'] !== []) {
                flash('error', $msg . ' Errores: ' . implode(' · ', array_slice($result['errors'], 0, 8)));
            } else {
                flash('success', $msg);
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/publicidad/contactos');
    }

    public function contactsTemplate(): void
    {
        Auth::requireRole(['admin']);
        $csv = (new MarketingContactService())->csvTemplate();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clientes_anteriores_plantilla.csv"');
        echo $csv;
        exit;
    }

    public function contactDelete(string $id): void
    {
        Auth::requireRole(['admin']);
        csrf_verify();
        (new MarketingContactService())->delete((int) $id);
        flash('success', 'Contacto eliminado.');
        redirect('/admin/publicidad/contactos');
    }

    /** @param array<string, mixed>|null $campaign */
    private function formData(?array $campaign): array
    {
        $audience = [];
        if ($campaign !== null) {
            $decoded = json_decode((string) ($campaign['audience_json'] ?? '{}'), true);
            $audience = is_array($decoded) ? $decoded : [];
        }

        return [
            'title' => $campaign ? 'Editar campaña' : 'Nueva campaña',
            'layout' => 'admin',
            'campaign' => $campaign,
            'audience' => $audience,
            'templates' => (new MailTemplateService())->all(),
            'products' => (new ProductRepository())->adminList(null, 500, 0, []),
            'certifiers' => (new CertifierRepository())->all(),
        ];
    }
}

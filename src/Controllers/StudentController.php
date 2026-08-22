<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Repositories\TrackingRepository;
use App\Services\ImportService;
use App\Services\TrackingService;

final class StudentController
{
    public function dashboard(): void
    {
        Auth::requireRole(['student']);
        $trackings = (new TrackingRepository())->forStudent((int) Auth::id());
        view('student/dashboard', [
            'title' => 'Mi panel',
            'trackings' => $trackings,
            'layout' => 'student',
        ]);
    }

    public function caseShow(string $id): void
    {
        Auth::requireRole(['student']);
        $svc = new TrackingService();
        $tracking = $svc->find((int) $id);
        if ($tracking === null || (int) $tracking['student_user_id'] !== (int) Auth::id()) {
            http_response_code(404);
            view('errors/404', ['title' => 'Caso no encontrado', 'layout' => 'student']);

            return;
        }
        $product = [
            'type' => $tracking['product_type'] ?? '',
            'config_json' => $tracking['config_json'] ?? null,
        ];
        $checklist = $svc->registrationChecklist((int) $tracking['id'], $product);
        $pipelineId = (int) ($tracking['pipeline_template_id'] ?? 0);
        view('student/case', [
            'title' => 'Caso ' . $tracking['matricula'],
            'tracking' => $tracking,
            'steps' => $pipelineId > 0 ? $svc->steps($pipelineId) : [],
            'documents' => $svc->documentsForTracking((int) $tracking['id']),
            'registrationDocs' => $checklist,
            'logs' => $svc->logs((int) $tracking['id']),
            'uksReport' => ImportService::uksReportFromTracking($tracking),
            'layout' => 'student',
        ]);
    }

    public function uploadRegistrationDocument(string $id): void
    {
        Auth::requireRole(['student']);
        csrf_verify();
        $trackingId = (int) $id;
        try {
            $file = $_FILES['document'] ?? null;
            if ($file === null) {
                throw new \InvalidArgumentException('Selecciona un archivo.');
            }
            $docType = trim((string) ($_POST['doc_type'] ?? ''));
            if ($docType === '') {
                throw new \InvalidArgumentException('Indica el tipo de documento.');
            }
            (new TrackingService())->uploadRegistrationDocument(
                $trackingId,
                (int) Auth::id(),
                $docType,
                $file
            );
            flash('success', 'Documento enviado. Lo revisaremos pronto.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/alumno/caso/' . $trackingId);
    }

    public function reuploadDocument(string $id): void
    {
        Auth::requireRole(['student']);
        csrf_verify();
        $docId = (int) $id;
        $svc = new TrackingService();
        $doc = $svc->findDocument($docId);
        try {
            $file = $_FILES['document'] ?? null;
            if ($file === null) {
                throw new \InvalidArgumentException('Selecciona un archivo.');
            }
            $svc->reuploadDocument($docId, (int) Auth::id(), $file);
            flash('success', 'Documento enviado. Lo revisaremos pronto.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $tid = $doc['tracking_id'] ?? null;
        redirect($tid ? '/alumno/caso/' . $tid : '/alumno');
    }

    public function documentDownload(string $id): void
    {
        Auth::requireRole(['student']);
        $svc = new TrackingService();
        $doc = $svc->findDocument((int) $id);
        if ($doc === null || (int) $doc['student_user_id'] !== (int) Auth::id()) {
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
}

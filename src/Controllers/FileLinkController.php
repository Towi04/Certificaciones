<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PurchaseRepository;
use App\Services\DocumentService;
use App\Services\SignedFileLinkService;
use App\Services\TrackingService;

final class FileLinkController
{
    public function download(string $token): void
    {
        try {
            $resolved = (new SignedFileLinkService())->resolveToken($token);
        } catch (\InvalidArgumentException $e) {
            http_response_code(403);
            view('errors/file_link', [
                'title' => 'Enlace no disponible',
                'message' => $e->getMessage(),
                'layout' => 'main',
            ]);

            return;
        }

        $docs = new DocumentService();

        if ($resolved['type'] === 'doc') {
            $doc = (new TrackingService())->findDocument($resolved['id']);
            if ($doc === null) {
                http_response_code(404);
                exit('Archivo no encontrado');
            }
            $path = $docs->absolutePath((string) $doc['storage_path']);
            $name = (string) ($doc['original_name'] ?? 'documento');
        } else {
            $purchase = (new PurchaseRepository())->find($resolved['id']);
            if ($purchase === null || empty($purchase['payment_proof_path'])) {
                http_response_code(404);
                exit('Comprobante no encontrado');
            }
            $path = $docs->absolutePath((string) $purchase['payment_proof_path']);
            $name = basename((string) $purchase['payment_proof_path']);
        }

        if (!is_file($path)) {
            http_response_code(404);
            exit('Archivo no disponible en el servidor');
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($name) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}

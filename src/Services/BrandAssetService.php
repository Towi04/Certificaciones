<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Subida de logos públicos (proveedores / certificadoras).
 */
final class BrandAssetService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    private const IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
        'text/plain', 'application/xml', 'text/xml',
    ];

    /**
     * @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file
     * @return string ruta pública relativa (/uploads/...)
     */
    public function storeLogo(string $folder, int $entityId, array $file): string
    {
        $folder = preg_replace('/[^a-z0-9_-]+/', '', strtolower($folder)) ?: 'brand';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Selecciona una imagen válida.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_readable($tmp))) {
            throw new \InvalidArgumentException('Archivo de logo inválido.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('El logo no debe superar 5 MB.');
        }

        $original = basename((string) ($file['name'] ?? 'logo'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Usa JPG, PNG, WEBP, GIF o SVG.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: ($file['type'] ?? ''));
        if (!in_array($mime, self::IMAGE_MIMES, true)) {
            throw new \InvalidArgumentException('Tipo de imagen no permitido.');
        }
        if ($extension === 'svg') {
            $this->assertSafeSvg($tmp);
        }

        $relativeDir = '/uploads/' . $folder . '/' . max(1, $entityId);
        $targetDir = BASE_PATH . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear el directorio de logos.');
        }

        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $dest = $targetDir . '/' . $filename;
        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
                throw new \RuntimeException('No se pudo guardar el logo.');
            }
            @unlink($tmp);
        }

        return $relativeDir . '/' . $filename;
    }

    public function deletePublicFile(?string $path): void
    {
        $path = trim((string) $path);
        if ($path === '' || !str_starts_with($path, '/uploads/')) {
            return;
        }
        $absolute = BASE_PATH . '/public' . $path;
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function assertSafeSvg(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new \InvalidArgumentException('No se pudo leer el SVG.');
        }
        $lower = strtolower($content);
        foreach (['<script', '<foreignobject', 'javascript:', 'data:text/html'] as $needle) {
            if (str_contains($lower, $needle)) {
                throw new \InvalidArgumentException('El SVG contiene contenido no permitido.');
            }
        }
        if (preg_match('/\son[a-z]+\s*=/i', $content)) {
            throw new \InvalidArgumentException('El SVG contiene eventos no permitidos.');
        }
        if (!str_contains($lower, '<svg')) {
            throw new \InvalidArgumentException('El archivo no parece ser un SVG válido.');
        }
    }
}

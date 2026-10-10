<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ComboRepository;

/** Logo / imagen de portada de combos para el catálogo. */
final class ComboMediaService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/svg+xml',
        'text/plain',
        'application/xml',
        'text/xml',
    ];

    private ComboRepository $combos;

    public function __construct()
    {
        $this->combos = new ComboRepository();
    }

    /**
     * @param array{tmp_name:string,name:string,error:int,size:int,type?:string} $file
     */
    public function uploadLogo(int $comboId, array $file): string
    {
        $combo = $this->combos->find($comboId);
        if ($combo === null) {
            throw new \InvalidArgumentException('Combo no encontrado.');
        }

        $stored = $this->storePublicUpload($comboId, $file);
        $old = trim((string) ($combo['logo_path'] ?? ''));
        $this->combos->update($comboId, ['logo_path' => $stored['path']]);
        if ($old !== '' && $old !== $stored['path']) {
            $this->unlinkPublicPath($old);
        }

        return $stored['path'];
    }

    public function clearLogo(int $comboId): void
    {
        $combo = $this->combos->find($comboId);
        if ($combo === null) {
            throw new \InvalidArgumentException('Combo no encontrado.');
        }
        $old = trim((string) ($combo['logo_path'] ?? ''));
        $this->combos->update($comboId, ['logo_path' => null]);
        if ($old !== '') {
            $this->unlinkPublicPath($old);
        }
    }

    /**
     * @param array{tmp_name:string,name:string,error:int,size:int,type?:string} $file
     * @return array{path:string,mime:string,extension:string}
     */
    private function storePublicUpload(int $comboId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Selecciona una imagen válida.');
        }
        if (!is_uploaded_file((string) $file['tmp_name']) && !is_readable((string) $file['tmp_name'])) {
            throw new \InvalidArgumentException('Archivo de carga inválido.');
        }

        $size = (int) ($file['size'] ?? 0);
        $maxBytes = 10 * 1024 * 1024;
        if ($size <= 0 || $size > $maxBytes) {
            throw new \InvalidArgumentException('La imagen excede el tamaño permitido (10 MB).');
        }

        $original = basename((string) ($file['name'] ?? 'imagen'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Formato no permitido. Usa JPG, PNG, WEBP, GIF o SVG.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file((string) $file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream'));
        if (!in_array($mime, self::IMAGE_MIMES, true)) {
            throw new \InvalidArgumentException('Tipo MIME no permitido para la imagen del combo.');
        }
        if ($extension === 'svg') {
            $this->assertSafeSvg((string) $file['tmp_name']);
            $mime = 'image/svg+xml';
        }

        $relativeDir = '/uploads/combos/' . $comboId;
        $targetDir = BASE_PATH . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear el directorio de imágenes del combo.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $dest = $targetDir . '/' . $filename;
        if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
            if (!@rename((string) $file['tmp_name'], $dest) && !@copy((string) $file['tmp_name'], $dest)) {
                throw new \RuntimeException('No se pudo guardar la imagen del combo.');
            }
            @unlink((string) $file['tmp_name']);
        }

        return [
            'path' => $relativeDir . '/' . $filename,
            'mime' => $mime,
            'extension' => $extension,
        ];
    }

    private function unlinkPublicPath(string $relativePath): void
    {
        $absolute = $this->absolutePublicPath($relativePath);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function absolutePublicPath(string $relativePath): string
    {
        $relativePath = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        if (!str_starts_with($relativePath, '/uploads/')) {
            return '';
        }

        return BASE_PATH . '/public' . $relativePath;
    }

    private function assertSafeSvg(string $tmpPath): void
    {
        $raw = @file_get_contents($tmpPath);
        if ($raw === false || $raw === '') {
            throw new \InvalidArgumentException('SVG inválido.');
        }
        $lower = strtolower($raw);
        foreach (['<script', 'onload=', 'onclick=', 'javascript:', '<foreignobject'] as $needle) {
            if (str_contains($lower, $needle)) {
                throw new \InvalidArgumentException('SVG no permitido: contiene contenido inseguro.');
            }
        }
    }
}

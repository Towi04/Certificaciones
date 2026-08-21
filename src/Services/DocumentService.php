<?php

declare(strict_types=1);

namespace App\Services;

final class DocumentService
{
    private string $root;

    public function __construct(?string $storageRoot = null)
    {
        $this->root = $storageRoot ?? (BASE_PATH . '/storage/uploads');
        if (!is_dir($this->root)) {
            @mkdir($this->root, 0755, true);
        }
    }

    /**
     * @param array{tmp_name:string,name:string,error:int,size:int,type?:string} $file
     * @param string $accept Extensiones permitidas estilo HTML accept, p.ej. ".pdf" o ".pdf,.jpg,.jpeg"
     * @return array{path:string,original_name:string,mime:string,size:int}
     */
    public function storeUploaded(array $file, string $subdir, string $accept = '.pdf,.jpg,.jpeg,.png,.webp'): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Error al subir el archivo.');
        }
        if (!is_uploaded_file((string) $file['tmp_name']) && !is_readable((string) $file['tmp_name'])) {
            throw new \InvalidArgumentException('Archivo de carga inválido.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('El archivo debe pesar entre 1 byte y 8 MB.');
        }

        $original = basename((string) ($file['name'] ?? 'archivo'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = self::extensionsFromAccept($accept);
        if ($allowed === []) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        }
        if (!in_array($ext, $allowed, true)) {
            $list = strtoupper(implode(', ', $allowed));
            throw new \InvalidArgumentException("Formato no permitido. Usa: {$list}.");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file((string) $file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream'));
        $okMimes = [];
        foreach ($allowed as $a) {
            $okMimes[] = match ($a) {
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'jpg', 'jpeg' => 'image/jpeg',
                default => '',
            };
        }
        $okMimes = array_values(array_filter(array_unique($okMimes)));
        if (!in_array($mime, $okMimes, true)) {
            if ($mime !== 'application/octet-stream') {
                throw new \InvalidArgumentException('Tipo MIME no permitido para este documento.');
            }
            $mime = match ($ext) {
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
        }

        $dir = $this->root . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio de cargas.');
        }

        $safe = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $safe;
        if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
            if (!@rename((string) $file['tmp_name'], $dest) && !@copy((string) $file['tmp_name'], $dest)) {
                throw new \RuntimeException('No se pudo guardar el archivo.');
            }
            @unlink((string) $file['tmp_name']);
        }

        $relative = 'uploads/' . trim($subdir, '/') . '/' . $safe;

        return [
            'path' => $relative,
            'original_name' => $original,
            'mime' => $mime,
            'size' => $size,
        ];
    }

    /** @return list<string> */
    public static function extensionsFromAccept(string $accept): array
    {
        $parts = preg_split('/\s*,\s*/', strtolower($accept)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = ltrim(trim($p), '.');
            if ($p === 'jpeg') {
                $out[] = 'jpg';
                $out[] = 'jpeg';
            } elseif ($p !== '' && !str_contains($p, '/')) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    public function absolutePath(string $relative): string
    {
        $relative = ltrim(str_replace(['..', '\\'], '', $relative), '/');
        if (str_starts_with($relative, 'uploads/')) {
            return BASE_PATH . '/storage/' . $relative;
        }

        return BASE_PATH . '/storage/uploads/' . $relative;
    }
}

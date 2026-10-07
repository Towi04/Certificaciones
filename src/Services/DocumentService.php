<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Almacenamiento seguro de documentos (comprobantes, PDFs, etc.) fuera del docroot público.
 * Los archivos viven en storage/uploads (no ejecutables por el web server).
 */
final class DocumentService
{
    private string $root;

    /** Extensiones que nunca se aceptan, aunque el caller las pida. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com',
        'js', 'mjs', 'html', 'htm', 'shtml', 'svg', 'xml', 'xhtml',
        'htaccess', 'htpasswd', 'ini', 'env',
    ];

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
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_readable($tmp))) {
            throw new \InvalidArgumentException('Archivo de carga inválido.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('El archivo debe pesar entre 1 byte y 8 MB.');
        }

        $original = basename((string) ($file['name'] ?? 'archivo'));
        // Evita nombres con doble extensión tipo factura.pdf.php
        $original = str_replace(["\0", "\r", "\n"], '', $original);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = self::extensionsFromAccept($accept);
        if ($allowed === []) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        }
        $allowed = array_values(array_filter(
            $allowed,
            static fn (string $e): bool => !in_array($e, self::BLOCKED_EXTENSIONS, true)
        ));

        if ($ext === '' || !in_array($ext, $allowed, true) || in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            $list = strtoupper(implode(', ', $allowed));
            throw new \InvalidArgumentException("Formato no permitido. Usa: {$list}.");
        }

        // MIME real del contenido (no el reportado por el navegador).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: '');
        $expectedMime = self::mimeForExtension($ext);
        if ($expectedMime === null || $mime === '' || $mime === 'application/octet-stream') {
            throw new \InvalidArgumentException('No se pudo verificar el tipo del archivo.');
        }
        if ($mime !== $expectedMime) {
            // Algunos PDF vienen como application/x-pdf
            $pdfAliases = ['application/pdf', 'application/x-pdf'];
            $okAlias = $expectedMime === 'application/pdf' && in_array($mime, $pdfAliases, true);
            if (!$okAlias) {
                throw new \InvalidArgumentException('El contenido del archivo no coincide con la extensión.');
            }
            $mime = 'application/pdf';
        }

        self::assertContentMatchesType($tmp, $ext, $mime);

        // Extensión final según MIME validado (ignora trucos en el nombre original).
        $safeExt = self::extensionForMime($mime) ?? $ext;
        if (!in_array($safeExt, $allowed, true) && !($safeExt === 'jpg' && in_array('jpeg', $allowed, true))) {
            throw new \InvalidArgumentException('Tipo de archivo no permitido.');
        }

        $dir = $this->root . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio de cargas.');
        }

        $safe = bin2hex(random_bytes(16)) . '.' . $safeExt;
        $dest = $dir . '/' . $safe;
        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
                throw new \RuntimeException('No se pudo guardar el archivo.');
            }
            @unlink($tmp);
        }
        @chmod($dest, 0640);

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

    /**
     * Sirve un archivo con cabeceras anti-XSS / anti-sniffing.
     * Imágenes y PDF van inline; el resto como attachment.
     */
    public static function streamFile(string $absolutePath, string $downloadName, ?string $mime = null): void
    {
        if (!is_file($absolutePath)) {
            http_response_code(404);
            exit('Archivo no disponible');
        }

        $mime = $mime ?: (mime_content_type($absolutePath) ?: 'application/octet-stream');
        $safeName = basename(str_replace(["\0", "\r", "\n", '"'], '', $downloadName));
        if ($safeName === '') {
            $safeName = 'archivo';
        }

        $inlineMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
        $disposition = in_array($mime, $inlineMimes, true) ? 'inline' : 'attachment';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox; style-src \'unsafe-inline\'; img-src \'self\' data:');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-store');
        readfile($absolutePath);
        exit;
    }

    private static function mimeForExtension(string $ext): ?string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            default => null,
        };
    }

    private static function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'application/pdf', 'application/x-pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/jpeg' => 'jpg',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            default => null,
        };
    }

    private static function assertContentMatchesType(string $tmp, string $ext, string $mime): void
    {
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($tmp);
            if ($info === false) {
                throw new \InvalidArgumentException('La imagen está dañada o no es válida.');
            }
            $imageMime = (string) ($info['mime'] ?? '');
            if ($imageMime !== $mime) {
                throw new \InvalidArgumentException('La imagen no coincide con el tipo declarado.');
            }

            return;
        }

        if ($mime === 'application/pdf' || $ext === 'pdf') {
            $fh = fopen($tmp, 'rb');
            if ($fh === false) {
                throw new \InvalidArgumentException('No se pudo leer el PDF.');
            }
            $head = (string) fread($fh, 5);
            fclose($fh);
            if (!str_starts_with($head, '%PDF-')) {
                throw new \InvalidArgumentException('El archivo no es un PDF válido.');
            }

            // Evita PDF con JavaScript embebido obvio (no es prueba total, sí reduce riesgo).
            $sample = (string) file_get_contents($tmp, false, null, 0, min(512000, (int) filesize($tmp)));
            if (preg_match('/\/JavaScript|\/JS[\s\/]|\/OpenAction/i', $sample)) {
                throw new \InvalidArgumentException('El PDF contiene acciones no permitidas.');
            }

            return;
        }

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $fh = fopen($tmp, 'rb');
            if ($fh === false) {
                throw new \InvalidArgumentException('No se pudo leer el archivo Excel.');
            }
            $head = (string) fread($fh, 4);
            fclose($fh);
            // XLSX = ZIP (PK..); XLS antiguo = OLE (D0 CF 11 E0)
            $ok = str_starts_with($head, 'PK')
                || $head === "\xD0\xCF\x11\xE0";
            if (!$ok) {
                throw new \InvalidArgumentException('El archivo Excel no es válido.');
            }
        }
    }
}

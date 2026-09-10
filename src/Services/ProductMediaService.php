<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductMediaRepository;
use App\Repositories\ProductRepository;

final class ProductMediaService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml', 'text/plain', 'application/xml', 'text/xml'];
    private const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx'];
    private const DOCUMENT_MIMES = [
        'application/pdf',
        'application/octet-stream',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private ProductMediaRepository $media;
    private ProductRepository $products;

    public function __construct()
    {
        $this->media = new ProductMediaRepository();
        $this->products = new ProductRepository();
    }

    /**
     * @param array{tmp_name:string,name:string,error:int,size:int,type?:string} $file
     */
    public function uploadLogo(int $productId, array $file): string
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }

        $stored = $this->storePublicUpload(
            $productId,
            $file,
            self::IMAGE_EXTENSIONS,
            self::IMAGE_MIMES,
            10 * 1024 * 1024,
            true
        );
        $this->products->update($productId, ['logo_path' => $stored['path']]);

        return $stored['path'];
    }

    /**
     * Copia el logo de un producto a otro (archivo nuevo bajo /uploads/products/{toId}/).
     * No reutiliza la misma ruta para que borrar uno no afecte al otro.
     */
    public function copyLogoFromProduct(int $fromProductId, int $toProductId): ?string
    {
        $from = $this->products->find($fromProductId);
        $to = $this->products->find($toProductId);
        if ($from === null || $to === null) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }

        $sourcePath = trim((string) ($from['logo_path'] ?? ''));
        if ($sourcePath === '') {
            return null;
        }

        $absolute = $this->absolutePublicPath($sourcePath);
        if (!is_file($absolute) || !is_readable($absolute)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            $extension = 'png';
        }

        $relativeDir = '/uploads/products/' . $toProductId;
        $targetDir = BASE_PATH . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear el directorio de multimedia.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $dest = $targetDir . '/' . $filename;
        if (!@copy($absolute, $dest)) {
            throw new \RuntimeException('No se pudo copiar el logo del producto.');
        }

        $newPath = $relativeDir . '/' . $filename;
        $this->products->update($toProductId, ['logo_path' => $newPath]);

        return $newPath;
    }

    /**
     * @param array{tmp_name:string,name:string,error:int,size:int,type?:string} $file
     */
    public function addMedia(
        int $productId,
        array $file,
        string $title,
        string $caption,
        int $sortOrder,
        bool $isActive
    ): int {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }

        $extension = strtolower(pathinfo(basename((string) ($file['name'] ?? '')), PATHINFO_EXTENSION));
        $isDocument = in_array($extension, self::DOCUMENT_EXTENSIONS, true);
        if ($isDocument) {
            $stored = $this->storePublicUpload(
                $productId,
                $file,
                self::DOCUMENT_EXTENSIONS,
                self::DOCUMENT_MIMES,
                12 * 1024 * 1024,
                false
            );
            $title = trim($title);
            if ($title === '') {
                $title = 'Documento del producto';
            }

            return $this->media->create([
                'product_id' => $productId,
                'media_type' => 'document',
                'title' => mb_substr($title, 0, 190),
                'caption' => trim($caption) !== '' ? mb_substr(trim($caption), 0, 255) : null,
                'storage_path' => $stored['path'],
                'external_url' => null,
                'mime_type' => $stored['mime'],
                'sort_order' => max(0, $sortOrder),
                'is_active' => $isActive,
            ]);
        }

        $stored = $this->storePublicUpload(
            $productId,
            $file,
            self::IMAGE_EXTENSIONS,
            self::IMAGE_MIMES,
            10 * 1024 * 1024,
            true
        );
        $title = trim($title);
        if ($title === '') {
            $title = 'Imagen del producto';
        }

        return $this->media->create([
            'product_id' => $productId,
            'media_type' => 'image',
            'title' => mb_substr($title, 0, 190),
            'caption' => trim($caption) !== '' ? mb_substr(trim($caption), 0, 255) : null,
            'storage_path' => $stored['path'],
            'external_url' => null,
            'mime_type' => $stored['mime'],
            'sort_order' => max(0, $sortOrder),
            'is_active' => $isActive,
        ]);
    }

    public function addYoutubeVideo(
        int $productId,
        string $youtubeUrl,
        string $title,
        string $caption,
        int $sortOrder,
        bool $isActive
    ): int {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }

        $embedUrl = $this->youtubeEmbedUrl($youtubeUrl);
        $title = trim($title);
        if ($title === '') {
            $title = 'Video del producto';
        }

        return $this->media->create([
            'product_id' => $productId,
            'media_type' => 'video',
            'title' => mb_substr($title, 0, 190),
            'caption' => trim($caption) !== '' ? mb_substr(trim($caption), 0, 255) : null,
            'storage_path' => '',
            'external_url' => $embedUrl,
            'mime_type' => 'text/youtube',
            'sort_order' => max(0, $sortOrder),
            'is_active' => $isActive,
        ]);
    }

    public function deleteMedia(int $productId, int $mediaId): void
    {
        $media = $this->media->find($mediaId);
        if ($media === null || (int) $media['product_id'] !== $productId) {
            throw new \InvalidArgumentException('Multimedia no encontrada para este producto.');
        }

        $this->media->delete($mediaId);
        $path = $this->absolutePublicPath((string) $media['storage_path']);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function updateMedia(
        int $productId,
        int $mediaId,
        string $title,
        string $caption,
        int $sortOrder,
        bool $isActive,
        ?array $replacementFile = null
    ): void {
        $media = $this->media->find($mediaId);
        if ($media === null || (int) $media['product_id'] !== $productId) {
            throw new \InvalidArgumentException('Multimedia no encontrada para este producto.');
        }

        $mediaType = (string) ($media['media_type'] ?? 'image');
        $title = trim($title);
        if ($title === '') {
            $title = match ($mediaType) {
                'video' => 'Video del producto',
                'document' => 'Documento del producto',
                default => 'Imagen del producto',
            };
        }

        $replacement = null;
        $hasReplacement = is_array($replacementFile)
            && (($replacementFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        if ($hasReplacement) {
            if ($mediaType === 'image') {
                $replacement = $this->storePublicUpload(
                    $productId,
                    $replacementFile,
                    self::IMAGE_EXTENSIONS,
                    self::IMAGE_MIMES,
                    10 * 1024 * 1024,
                    true
                );
            } elseif ($mediaType === 'document') {
                $replacement = $this->storePublicUpload(
                    $productId,
                    $replacementFile,
                    self::DOCUMENT_EXTENSIONS,
                    self::DOCUMENT_MIMES,
                    12 * 1024 * 1024,
                    false
                );
            } else {
                throw new \InvalidArgumentException('Solo las imágenes y documentos pueden reemplazarse con archivo.');
            }
        }

        $this->media->update($mediaId, [
            'title' => mb_substr($title, 0, 190),
            'caption' => trim($caption) !== '' ? mb_substr(trim($caption), 0, 255) : null,
            'sort_order' => max(0, $sortOrder),
            'is_active' => $isActive,
            'storage_path' => $replacement['path'] ?? null,
            'mime_type' => $replacement['mime'] ?? null,
        ]);

        if ($replacement !== null) {
            $oldPath = $this->absolutePublicPath((string) ($media['storage_path'] ?? ''));
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
    }

    /**
     * @param array{tmp_name:string,name:string,error:int,size:int,type?:string} $file
     * @param list<string> $allowedExtensions
     * @param list<string> $allowedMimes
     * @return array{path:string,mime:string,extension:string}
     */
    private function storePublicUpload(
        int $productId,
        array $file,
        array $allowedExtensions,
        array $allowedMimes,
        int $maxBytes,
        bool $allowSvgSanitize
    ): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Selecciona un archivo válido.');
        }
        if (!is_uploaded_file((string) $file['tmp_name']) && !is_readable((string) $file['tmp_name'])) {
            throw new \InvalidArgumentException('Archivo de carga inválido.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \InvalidArgumentException('El archivo excede el tamaño permitido.');
        }

        $original = basename((string) ($file['name'] ?? 'archivo'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException('Formato no permitido para multimedia de producto.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file((string) $file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream'));
        if (!in_array($mime, $allowedMimes, true)) {
            throw new \InvalidArgumentException('Tipo MIME no permitido para multimedia de producto.');
        }
        if ($allowSvgSanitize && $extension === 'svg') {
            $this->assertSafeSvg((string) $file['tmp_name']);
            $mime = 'image/svg+xml';
        }

        $relativeDir = '/uploads/products/' . $productId;
        $targetDir = BASE_PATH . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear el directorio de multimedia.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $dest = $targetDir . '/' . $filename;
        if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
            if (!@rename((string) $file['tmp_name'], $dest) && !@copy((string) $file['tmp_name'], $dest)) {
                throw new \RuntimeException('No se pudo guardar el archivo multimedia.');
            }
            @unlink((string) $file['tmp_name']);
        }

        return [
            'path' => $relativeDir . '/' . $filename,
            'mime' => $mime,
            'extension' => $extension,
        ];
    }

    private function youtubeEmbedUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Pega una URL válida de YouTube.');
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $videoId = null;

        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            $videoId = explode('/', $path)[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (!empty($query['v']) && is_string($query['v'])) {
                $videoId = $query['v'];
            } elseif (str_starts_with($path, 'embed/')) {
                $videoId = explode('/', substr($path, 6))[0] ?? null;
            } elseif (str_starts_with($path, 'shorts/')) {
                $videoId = explode('/', substr($path, 7))[0] ?? null;
            }
        }

        $videoId = is_string($videoId) ? trim($videoId) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
            throw new \InvalidArgumentException('No pude identificar el ID del video de YouTube.');
        }

        return 'https://www.youtube.com/embed/' . $videoId;
    }

    private function assertSafeSvg(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new \InvalidArgumentException('No se pudo leer el SVG.');
        }

        $lower = strtolower($content);
        $blocked = [
            '<script',
            '<foreignobject',
            'javascript:',
            'data:text/html',
        ];
        foreach ($blocked as $needle) {
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

    private function absolutePublicPath(string $path): string
    {
        $path = '/' . ltrim(str_replace(['..', '\\'], '', $path), '/');

        return BASE_PATH . '/public' . $path;
    }
}

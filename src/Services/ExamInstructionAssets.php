<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Recursos de instrucciones / docs por grupo (PDF, enlaces, videos)
 * expuestos como placeholders en plantillas de correo.
 */
final class ExamInstructionAssets
{
    /**
     * @param array<string, mixed> $productOrConfig Producto con config_json/group_config_json, o config ya fusionada
     * @return array<string, string>
     */
    public static function mailVars(array $productOrConfig): array
    {
        $cfg = self::resolveConfig($productOrConfig);
        $instr = is_array($cfg['exam_instructions'] ?? null) ? $cfg['exam_instructions'] : [];
        $docs = self::normalizeDocuments($instr);

        $pdfLabel = trim((string) ($instr['pdf_label'] ?? ''));
        if ($pdfLabel === '') {
            $pdfLabel = 'Guía / PDF de instrucciones';
        }
        $videoLabel = trim((string) ($instr['video_label'] ?? ''));
        if ($videoLabel === '') {
            $videoLabel = 'Video de instrucciones';
        }

        $pdfUrl = self::absoluteUrl(
            trim((string) ($instr['pdf_url'] ?? '')) !== ''
                ? (string) $instr['pdf_url']
                : (string) ($instr['pdf_path'] ?? '')
        );
        $videoUrl = self::absoluteUrl(trim((string) ($instr['video_url'] ?? '')));

        // Si hay documents[] y faltan legacy, toma el primero de cada tipo.
        if ($pdfUrl === '') {
            foreach ($docs as $doc) {
                if (($doc['kind'] ?? '') === 'file' || ($doc['kind'] ?? '') === 'link') {
                    $u = self::docPublicUrl($doc);
                    if ($u !== '') {
                        $pdfUrl = $u;
                        if (trim((string) ($doc['label'] ?? '')) !== '') {
                            $pdfLabel = (string) $doc['label'];
                        }
                        break;
                    }
                }
            }
        }
        if ($videoUrl === '') {
            foreach ($docs as $doc) {
                if (($doc['kind'] ?? '') === 'video') {
                    $u = self::docPublicUrl($doc);
                    if ($u !== '') {
                        $videoUrl = $u;
                        if (trim((string) ($doc['label'] ?? '')) !== '') {
                            $videoLabel = (string) $doc['label'];
                        }
                        break;
                    }
                }
            }
        }

        $parts = [];
        $vars = [
            'instruction_pdf_url' => $pdfUrl,
            'instruction_pdf_label' => $pdfLabel,
            'instruction_video_url' => $videoUrl,
            'instruction_video_label' => $videoLabel,
        ];

        if ($docs === []) {
            if ($pdfUrl !== '') {
                $parts[] = self::htmlLink($pdfUrl, $pdfLabel);
            }
            if ($videoUrl !== '') {
                $parts[] = self::htmlLink($videoUrl, $videoLabel);
            }
        } else {
            foreach ($docs as $doc) {
                $url = self::docPublicUrl($doc);
                if ($url === '') {
                    continue;
                }
                $label = trim((string) ($doc['label'] ?? ''));
                if ($label === '') {
                    $label = trim((string) ($doc['code'] ?? 'Documento'));
                }
                $parts[] = self::htmlLink($url, $label);
                $code = self::sanitizeCode((string) ($doc['code'] ?? ''));
                if ($code !== '') {
                    $vars['doc_' . $code . '_url'] = $url;
                    $vars['doc_' . $code . '_label'] = $label;
                }
            }
        }

        $vars['instruction_docs_html'] = $parts === []
            ? ''
            : ('<ul style="margin:0;padding-left:1.1rem">'
                . implode('', array_map(
                    static fn (string $p): string => '<li style="margin:.2rem 0">' . $p . '</li>',
                    $parts
                ))
                . '</ul>');
        $vars['instructions_html'] = $parts === []
            ? ''
            : ('<p>' . implode(' · ', $parts) . '</p>');

        return $vars;
    }

    /**
     * Normaliza documents[] y, si está vacío, deriva de campos legacy.
     *
     * @param array<string, mixed> $instr
     * @return list<array{code:string,label:string,kind:string,path:string,url:string}>
     */
    public static function normalizeDocuments(array $instr): array
    {
        $out = [];
        $raw = is_array($instr['documents'] ?? null) ? $instr['documents'] : [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $doc = self::normalizeOneDocument($row);
            if ($doc !== null) {
                $out[] = $doc;
            }
        }
        if ($out !== []) {
            return $out;
        }

        $pdfPath = trim((string) ($instr['pdf_path'] ?? ''));
        $pdfUrl = trim((string) ($instr['pdf_url'] ?? ''));
        $pdfLabel = trim((string) ($instr['pdf_label'] ?? ''));
        if ($pdfPath !== '' || $pdfUrl !== '') {
            $out[] = [
                'code' => 'guia',
                'label' => $pdfLabel !== '' ? $pdfLabel : 'Guía / PDF de instrucciones',
                'kind' => $pdfPath !== '' ? 'file' : 'link',
                'path' => $pdfPath,
                'url' => $pdfUrl,
            ];
        }
        $videoUrl = trim((string) ($instr['video_url'] ?? ''));
        $videoLabel = trim((string) ($instr['video_label'] ?? ''));
        if ($videoUrl !== '') {
            $out[] = [
                'code' => 'video',
                'label' => $videoLabel !== '' ? $videoLabel : 'Video de instrucciones',
                'kind' => 'video',
                'path' => '',
                'url' => $videoUrl,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{code:string,label:string,kind:string,path:string,url:string}|null
     */
    public static function normalizeOneDocument(array $row): ?array
    {
        $code = self::sanitizeCode((string) ($row['code'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));
        $kind = strtolower(trim((string) ($row['kind'] ?? 'file')));
        if (!in_array($kind, ['file', 'link', 'video'], true)) {
            $kind = 'file';
        }
        $path = trim((string) ($row['path'] ?? ''));
        $url = trim((string) ($row['url'] ?? ''));
        if ($code === '' && $label === '' && $path === '' && $url === '') {
            return null;
        }
        if ($code === '') {
            $base = $label !== '' ? $label : ($kind === 'video' ? 'video' : 'doc');
            $code = self::sanitizeCode($base);
            if ($code === '') {
                $code = 'doc';
            }
        }
        if ($label === '') {
            $label = $code;
        }

        return [
            'code' => mb_substr($code, 0, 40),
            'label' => mb_substr($label, 0, 120),
            'kind' => $kind,
            'path' => mb_substr($path, 0, 255),
            'url' => mb_substr($url, 0, 512),
        ];
    }

    /** @param array{path?:string,url?:string} $doc */
    public static function docPublicUrl(array $doc): string
    {
        $url = trim((string) ($doc['url'] ?? ''));
        if ($url !== '') {
            return self::absoluteUrl($url);
        }

        return self::absoluteUrl(trim((string) ($doc['path'] ?? '')));
    }

    public static function sanitizeCode(string $raw): string
    {
        $code = strtolower(trim($raw));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        return $code;
    }

    /**
     * @param array<string, mixed> $productOrConfig
     * @return array<string, mixed>
     */
    private static function resolveConfig(array $productOrConfig): array
    {
        if (isset($productOrConfig['config_json']) || isset($productOrConfig['group_config_json'])) {
            return CheckoutRequirements::config($productOrConfig);
        }

        return $productOrConfig;
    }

    public static function absoluteUrl(string $pathOrUrl): string
    {
        $v = trim($pathOrUrl);
        if ($v === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $v) === 1) {
            return $v;
        }
        if (str_starts_with($v, '//')) {
            return 'https:' . $v;
        }

        $base = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
        if ($base === '') {
            return $v;
        }
        if (str_starts_with($v, '/')) {
            return $base . $v;
        }

        return $base . '/' . ltrim($v, '/');
    }

    private static function htmlLink(string $url, string $label): string
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
    }

    /**
     * Guarda un archivo de instrucciones (PDF/DOC/DOCX) bajo /uploads/groups/{id}/instructions/.
     *
     * @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file
     * @return string ruta pública relativa (/uploads/...)
     */
    public static function storeFileUpload(int $groupId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Selecciona un archivo de instrucciones válido.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_readable($tmp))) {
            throw new \InvalidArgumentException('Archivo de instrucciones inválido.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 12 * 1024 * 1024) {
            throw new \InvalidArgumentException('El archivo de instrucciones no debe superar 12 MB.');
        }

        $original = basename((string) ($file['name'] ?? 'documento.pdf'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx'];
        if (!in_array($extension, $allowed, true)) {
            throw new \InvalidArgumentException('Solo se permiten PDF, DOC o DOCX.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: ($file['type'] ?? ''));
        $allowedMimes = [
            'application/pdf',
            'application/octet-stream',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            throw new \InvalidArgumentException('El archivo no parece un documento válido.');
        }

        $relativeDir = '/uploads/groups/' . max(1, $groupId) . '/instructions';
        $targetDir = BASE_PATH . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear el directorio de instrucciones.');
        }

        $filename = 'doc-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $dest = $targetDir . '/' . $filename;
        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
                throw new \RuntimeException('No se pudo guardar el archivo de instrucciones.');
            }
            @unlink($tmp);
        }

        return $relativeDir . '/' . $filename;
    }

    /** @deprecated Use storeFileUpload */
    public static function storePdfUpload(int $groupId, array $file): string
    {
        return self::storeFileUpload($groupId, $file);
    }
}

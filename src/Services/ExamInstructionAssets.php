<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Recursos de instrucciones de examen por grupo (PDF / video)
 * expuestos como placeholders en plantillas de correo.
 */
final class ExamInstructionAssets
{
    /**
     * @param array<string, mixed> $productOrConfig Producto con config_json/group_config_json, o config ya fusionada
     * @return array{
     *   instruction_pdf_url:string,
     *   instruction_pdf_label:string,
     *   instruction_video_url:string,
     *   instruction_video_label:string,
     *   instructions_html:string
     * }
     */
    public static function mailVars(array $productOrConfig): array
    {
        $cfg = self::resolveConfig($productOrConfig);
        $instr = is_array($cfg['exam_instructions'] ?? null) ? $cfg['exam_instructions'] : [];

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

        $parts = [];
        if ($pdfUrl !== '') {
            $parts[] = '<a href="' . htmlspecialchars($pdfUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($pdfLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }
        if ($videoUrl !== '') {
            $parts[] = '<a href="' . htmlspecialchars($videoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($videoLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }
        $html = $parts === [] ? '' : ('<p>' . implode(' · ', $parts) . '</p>');

        return [
            'instruction_pdf_url' => $pdfUrl,
            'instruction_pdf_label' => $pdfLabel,
            'instruction_video_url' => $videoUrl,
            'instruction_video_label' => $videoLabel,
            'instructions_html' => $html,
        ];
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

    /**
     * Guarda un PDF público bajo /uploads/groups/{id}/instructions/.
     *
     * @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file
     * @return string ruta pública relativa (/uploads/...)
     */
    public static function storePdfUpload(int $groupId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Selecciona un PDF de instrucciones válido.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_readable($tmp))) {
            throw new \InvalidArgumentException('Archivo de instrucciones inválido.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 12 * 1024 * 1024) {
            throw new \InvalidArgumentException('El PDF de instrucciones no debe superar 12 MB.');
        }

        $original = basename((string) ($file['name'] ?? 'instrucciones.pdf'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            throw new \InvalidArgumentException('Solo se permiten PDF para las instrucciones.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: ($file['type'] ?? ''));
        if (!in_array($mime, ['application/pdf', 'application/octet-stream'], true)) {
            throw new \InvalidArgumentException('El archivo no parece un PDF válido.');
        }

        $relativeDir = '/uploads/groups/' . max(1, $groupId) . '/instructions';
        $targetDir = BASE_PATH . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear el directorio de instrucciones.');
        }

        $filename = 'guide-' . bin2hex(random_bytes(8)) . '.pdf';
        $dest = $targetDir . '/' . $filename;
        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
                throw new \RuntimeException('No se pudo guardar el PDF de instrucciones.');
            }
            @unlink($tmp);
        }

        return $relativeDir . '/' . $filename;
    }
}

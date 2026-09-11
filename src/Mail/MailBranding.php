<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\Env;
use App\Support\Settings;

/**
 * Envoltura visual compartida por todos los correos (logo / encabezado / pie).
 * Editable desde Admin → Plantillas de correo.
 */
final class MailBranding
{
    public const SETTINGS_KEY = 'mail_branding';

    public static function primaryColor(): string
    {
        $cfg = self::config();
        $c = self::normalizeColor((string) ($cfg['footer_bg'] ?? ''), self::defaultFooterBg());

        return $c;
    }

    /** Gris de marca DOCEO (encabezado de correo). */
    public static function headerColor(): string
    {
        $cfg = self::config();

        return self::normalizeColor((string) ($cfg['header_bg'] ?? ''), self::defaultHeaderBg());
    }

    public static function logoUrl(): string
    {
        $cfg = self::config();
        $custom = trim((string) ($cfg['logo_url'] ?? ''));
        if ($custom !== '') {
            return self::absoluteAssetUrl($custom);
        }

        $fromEnv = trim((string) (Env::get('MAIL_LOGO_URL', '') ?? ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $base = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
        if ($base !== '') {
            return $base . '/assets/brand/email/logo.png';
        }

        return 'https://institutodoceo.com/img/emails/logo.png';
    }

    public static function appName(): string
    {
        $cfg = self::config();
        $custom = trim((string) ($cfg['app_name'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return (string) (Env::get('APP_NAME', 'Instituto DOCEO') ?? 'Instituto DOCEO');
    }

    /**
     * @return array{
     *   logo_url:string,
     *   app_name:string,
     *   header_bg:string,
     *   footer_bg:string,
     *   header_html:string,
     *   footer_html:string,
     *   footer_text_color:string
     * }
     */
    public static function config(): array
    {
        $defaults = self::defaults();
        $raw = Settings::get(self::SETTINGS_KEY, '');
        if ($raw === null || trim($raw) === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return [
            'logo_url' => trim((string) ($decoded['logo_url'] ?? $defaults['logo_url'])),
            'app_name' => trim((string) ($decoded['app_name'] ?? $defaults['app_name'])),
            'header_bg' => self::normalizeColor(
                (string) ($decoded['header_bg'] ?? ''),
                $defaults['header_bg']
            ),
            'footer_bg' => self::normalizeColor(
                (string) ($decoded['footer_bg'] ?? ''),
                $defaults['footer_bg']
            ),
            'header_html' => (string) ($decoded['header_html'] ?? $defaults['header_html']),
            'footer_html' => (string) ($decoded['footer_html'] ?? $defaults['footer_html']),
            'footer_text_color' => self::normalizeColor(
                (string) ($decoded['footer_text_color'] ?? ''),
                $defaults['footer_text_color']
            ),
        ];
    }

    /**
     * @return array{
     *   logo_url:string,
     *   app_name:string,
     *   header_bg:string,
     *   footer_bg:string,
     *   header_html:string,
     *   footer_html:string,
     *   footer_text_color:string
     * }
     */
    public static function defaults(): array
    {
        return [
            'logo_url' => '',
            'app_name' => '',
            'header_bg' => self::defaultHeaderBg(),
            'footer_bg' => self::defaultFooterBg(),
            'header_html' => '',
            'footer_html' => '',
            'footer_text_color' => '#ffffff',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   logo_url:string,
     *   app_name:string,
     *   header_bg:string,
     *   footer_bg:string,
     *   header_html:string,
     *   footer_html:string,
     *   footer_text_color:string
     * }
     */
    public static function save(array $input): array
    {
        $current = self::config();
        $logoUrl = trim((string) ($input['logo_url'] ?? $current['logo_url']));
        if (!empty($input['clear_logo'])) {
            $logoUrl = '';
        }
        if ($logoUrl !== '' && !self::isSafeUrl($logoUrl) && !str_starts_with($logoUrl, '/uploads/')) {
            throw new \InvalidArgumentException('La URL del logo no es válida.');
        }

        $cfg = [
            'logo_url' => $logoUrl,
            'app_name' => mb_substr(trim((string) ($input['app_name'] ?? '')), 0, 120),
            'header_bg' => self::normalizeColor(
                (string) ($input['header_bg'] ?? ''),
                self::defaultHeaderBg()
            ),
            'footer_bg' => self::normalizeColor(
                (string) ($input['footer_bg'] ?? ''),
                self::defaultFooterBg()
            ),
            'header_html' => self::sanitizeHtml((string) ($input['header_html'] ?? '')),
            'footer_html' => self::sanitizeHtml((string) ($input['footer_html'] ?? '')),
            'footer_text_color' => self::normalizeColor(
                (string) ($input['footer_text_color'] ?? ''),
                '#ffffff'
            ),
        ];

        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('No se pudo guardar la configuración de marca.');
        }
        Settings::set(self::SETTINGS_KEY, $json);

        return $cfg;
    }

    public static function reset(): void
    {
        Settings::set(self::SETTINGS_KEY, '');
    }

    /**
     * @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file
     */
    public static function storeLogoUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Selecciona una imagen de logo válida.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_readable($tmp))) {
            throw new \InvalidArgumentException('Archivo de logo inválido.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 4 * 1024 * 1024) {
            throw new \InvalidArgumentException('El logo no debe superar 4 MB.');
        }

        $original = basename((string) ($file['name'] ?? 'logo.png'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            throw new \InvalidArgumentException('Usa PNG, JPG, WEBP o GIF para el logo.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: ($file['type'] ?? ''));
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            throw new \InvalidArgumentException('Tipo de imagen no permitido para el logo.');
        }

        $relativeDir = '/uploads/mail/branding';
        $targetDir = BASE_PATH . '/public' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('No se pudo crear el directorio del logo de correo.');
        }

        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $dest = $targetDir . '/' . $filename;
        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) {
                throw new \RuntimeException('No se pudo guardar el logo de correo.');
            }
            @unlink($tmp);
        }

        return $relativeDir . '/' . $filename;
    }

    /** HTML efectivo del encabezado (logo por defecto si no hay HTML custom). */
    public static function resolvedHeaderHtml(): string
    {
        $cfg = self::config();
        $custom = trim((string) ($cfg['header_html'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $logo = htmlspecialchars(self::logoUrl(), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars(self::appName(), ENT_QUOTES, 'UTF-8');

        return '<img src="' . $logo . '" alt="' . $name . '" style="max-width:180px;height:auto;display:inline-block;">';
    }

    /** HTML efectivo del pie. */
    public static function resolvedFooterHtml(): string
    {
        $cfg = self::config();
        $custom = trim((string) ($cfg['footer_html'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $name = htmlspecialchars(self::appName(), ENT_QUOTES, 'UTF-8');

        return $name . ' · 🐝';
    }

    /** Marcador interno para no envolver dos veces el mismo HTML. */
    public const BRANDING_MARKER = '<!--doceo-mail-branding-->';

    /** ¿Ya lleva la envoltura global de encabezado/pie? */
    public static function isAlreadyBranded(string $html): bool
    {
        return str_contains($html, 'doceo-mail-branding');
    }

    /**
     * Plantilla HTML completa (p. ej. pegada desde Outlook).
     * Sigue recibiendo encabezado/pie: se extrae el cuerpo y se envuelve.
     */
    public static function isStandaloneHtml(string $html): bool
    {
        $lower = strtolower($html);
        if (str_contains($lower, '<!doctype') || str_contains($lower, '<html')) {
            return true;
        }

        // Correos armados con tablas de presentación (típico de plantillas copiadas).
        return str_contains($lower, 'role="presentation"')
            && strlen($html) > 800
            && (str_contains($lower, '<table') || str_contains($lower, 'instituto doceo'));
    }

    /** Extrae el contenido interior de un HTML completo (body o sin wrappers). */
    public static function extractInnerHtml(string $html): string
    {
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $m) === 1) {
            return trim((string) $m[1]);
        }

        $stripped = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html) ?? $html;
        $stripped = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/<\/?html\b[^>]*>/i', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * Asegura encabezado y pie globales en todos los correos.
     * Si la plantilla ya es HTML completo (Outlook, etc.), se toma el cuerpo interior
     * y se vuelve a envolver con la marca DOCEO.
     */
    public static function wrapIfNeeded(string $innerHtml): string
    {
        if (self::isAlreadyBranded($innerHtml)) {
            return $innerHtml;
        }

        if (self::isStandaloneHtml($innerHtml)) {
            $innerHtml = self::extractInnerHtml($innerHtml);
        }

        return self::wrap($innerHtml);
    }

    public static function appendBlock(string $html, string $block): string
    {
        if (preg_match('/<\/body>/i', $html) === 1) {
            return (string) preg_replace('/<\/body>/i', $block . '</body>', $html, 1);
        }

        return $html . $block;
    }

    public static function wrap(string $innerHtml): string
    {
        $cfg = self::config();
        $headerBg = htmlspecialchars(self::headerColor(), ENT_QUOTES, 'UTF-8');
        $footerBg = htmlspecialchars(self::primaryColor(), ENT_QUOTES, 'UTF-8');
        $footerColor = htmlspecialchars(
            self::normalizeColor((string) ($cfg['footer_text_color'] ?? ''), '#ffffff'),
            ENT_QUOTES,
            'UTF-8'
        );
        $headerHtml = self::resolvedHeaderHtml();
        $footerHtml = self::resolvedFooterHtml();

        // Saltos de línea: sin ellos el HTML queda en 1 sola línea >2048 chars y
        // Exim/Neubox falla con "message has lines too long for transport" (Gmail).
        return self::BRANDING_MARKER . "\n"
            . "<!DOCTYPE html>\n<html lang=\"es\"><head><meta charset=\"UTF-8\"></head>\n"
            . "<body style=\"margin:0;padding:0;background:#f4f6fa;\">\n"
            . "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f4f6fa;padding:24px 12px;\">\n"
            . "<tr><td align=\"center\">\n"
            . "<table role=\"presentation\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:600px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #C4C4C4;\">\n"
            . '<tr><td style="background:' . $headerBg . ';padding:20px;text-align:center;">' . "\n"
            . $headerHtml . "\n"
            . "</td></tr>\n"
            . "<tr><td style=\"padding:28px 32px;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#333;\">\n"
            . $innerHtml . "\n"
            . "</td></tr>\n"
            . '<tr><td style="padding:16px 32px;background:' . $footerBg . ';font-family:Arial,sans-serif;font-size:12px;color:'
            . $footerColor . ";text-align:center;\">\n"
            . $footerHtml . "\n"
            . "</td></tr>\n"
            . "</table></td></tr></table>\n</body></html>";
    }

    public static function defaultHeaderBg(): string
    {
        return '#C4C4C4';
    }

    public static function defaultFooterBg(): string
    {
        return '#315285';
    }

    public static function normalizeColor(string $raw, string $fallback): string
    {
        $c = trim($raw);
        if ($c === '') {
            return $fallback;
        }
        if ($c[0] !== '#') {
            $c = '#' . $c;
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c) !== 1) {
            return $fallback;
        }

        return strtoupper($c);
    }

    public static function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        // Admin-only: quitar scripts y handlers, permitir el resto (enlaces, imgs, redes).
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? '';
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html) ?? '';
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/javascript\s*:/i', '', $html) ?? '';

        return trim($html);
    }

    private static function absoluteAssetUrl(string $pathOrUrl): string
    {
        $v = trim($pathOrUrl);
        if ($v === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $v) === 1) {
            return $v;
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

    private static function isSafeUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }
}

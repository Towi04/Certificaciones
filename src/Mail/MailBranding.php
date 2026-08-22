<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\Env;

final class MailBranding
{
    public static function primaryColor(): string
    {
        return '#315285';
    }

    public static function logoUrl(): string
    {
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

    /** Plantilla HTML completa (p. ej. pegada desde Outlook) — no volver a envolver. */
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

    /** Envuelve solo fragmentos; respeta plantillas HTML completas. */
    public static function wrapIfNeeded(string $innerHtml): string
    {
        if (self::isStandaloneHtml($innerHtml)) {
            return $innerHtml;
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
        $logo = htmlspecialchars(self::logoUrl(), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars(Env::get('APP_NAME', 'Instituto DOCEO') ?? 'Instituto DOCEO', ENT_QUOTES, 'UTF-8');
        $blue = self::primaryColor();

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f6fa;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fa;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #C4C4C4;">'
            . '<tr><td style="background:' . $blue . ';padding:20px;text-align:center;">'
            . '<img src="' . $logo . '" alt="' . $name . '" style="max-width:180px;height:auto;">'
            . '</td></tr>'
            . '<tr><td style="padding:28px 32px;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#333;">'
            . $innerHtml
            . '</td></tr>'
            . '<tr><td style="padding:16px 32px;background:#f8f9fb;font-family:Arial,sans-serif;font-size:12px;color:#667;text-align:center;">'
            . $name . ' · 🐝'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}

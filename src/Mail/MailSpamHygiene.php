<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Higiene anti-spam alineada con recomendaciones Neubox / MailChannels:
 * - Asunto vacío o TODO EN MAYÚSCULAS
 * - Destinatarios inválidos / inexistentes (formato)
 * - Adjuntos con nombres aleatorios o sospechosos
 * - Asuntos con indicios típicos de phishing
 *
 * No garantiza entrega; evita patrones que suelen provocar nuevo bloqueo.
 */
final class MailSpamHygiene
{
    /** @var list<string> */
    private const PHISHING_HINTS = [
        'verificar cuenta',
        'verifique su cuenta',
        'confirma tu identidad',
        'confirme su identidad',
        'acta de defuncion',
        'ganaste un premio',
        'has ganado',
        'click aqui',
        'haga clic aqui',
        'password reset',
        'restablecer contraseña ahora',
        'wire transfer',
        'bitcoin',
        'crypto',
        'invoice attached',
        'payment overdue!!!',
    ];

    /**
     * Normaliza asunto para envío. Nunca deja vacío ni TODO MAYÚSCULAS.
     */
    public static function normalizeSubject(string $subject, string $fallback = 'Instituto DOCEO — notificación'): string
    {
        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);
        // Quitar controles / caracteres raros que a veces corrompen el asunto en rebotes.
        $subject = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $subject) ?? $subject;
        $subject = trim($subject);

        if ($subject === '') {
            return $fallback;
        }

        if (self::isMostlyUppercase($subject)) {
            $subject = self::softenAllCaps($subject);
        }

        // Evitar asuntos absurdamente largos (filtro spam).
        if (self::len($subject) > 180) {
            $subject = rtrim(self::slice($subject, 0, 177)) . '…';
        }

        return $subject;
    }

    /**
     * Validación estricta al guardar plantillas (falla en admin, no en silencio).
     */
    public static function assertTemplateSubject(string $subject): void
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new \InvalidArgumentException('Indica el asunto de la plantilla (Neubox/MailChannels bloquea asuntos vacíos).');
        }
        if (self::isMostlyUppercase($subject)) {
            throw new \InvalidArgumentException(
                'El asunto no puede ir todo en MAYÚSCULAS (MailChannels lo trata como spam). '
                . 'Usa mayúsculas normales, p. ej. «Solicitud de registro UKS».'
            );
        }
        $hit = self::phishingHintIn($subject);
        if ($hit !== null) {
            throw new \InvalidArgumentException(
                'El asunto parece sospechoso para filtros anti-phishing («' . $hit . '»). '
                . 'Cámbialo a un texto claro y profesional.'
            );
        }
    }

    public static function assertRecipient(string $email): void
    {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Destinatario inválido: ' . ($email !== '' ? $email : '(vacío)'));
        }
        // Dominios claramente de prueba / basura que generan rebotes y dañan reputación.
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        $blocked = ['example.com', 'example.org', 'test.com', 'invalid', 'localhost', 'mailinator.com'];
        if (in_array($domain, $blocked, true)) {
            throw new \RuntimeException(
                'Destinatario de prueba/bloqueado (' . $email . '). '
                . 'No envíes a correos inexistentes: Neubox/MailChannels puede volver a bloquear el buzón.'
            );
        }
    }

    /**
     * Nombre de adjunto legible (evita hex aleatorio tipo a1b2c3….pdf).
     *
     * @param array{path: string, name?: string, mime?: string} $att
     * @return array{path: string, name: string, mime?: string}
     */
    public static function sanitizeAttachment(array $att, int $index = 0): array
    {
        $path = (string) ($att['path'] ?? '');
        $name = trim((string) ($att['name'] ?? basename($path)));
        $ext = strtolower(pathinfo($name !== '' ? $name : $path, PATHINFO_EXTENSION));
        if ($ext === '' || !preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
            $ext = 'bin';
        }

        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = self::safeFilenameBase($base);
        if ($base === '' || self::looksRandomToken($base)) {
            $base = 'documento' . ($index > 0 ? '-' . ($index + 1) : '');
        }

        $att['name'] = $base . '.' . $ext;

        return $att;
    }

    public static function isMostlyUppercase(string $subject): bool
    {
        // Ignorar placeholders {{...}} al medir mayúsculas.
        $plain = preg_replace('/\{\{[^}]+\}\}/', ' ', $subject) ?? $subject;
        $letters = preg_replace('/[^\p{L}]/u', '', $plain) ?? '';
        if ($letters === '') {
            return false;
        }
        $upper = preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '';
        $letterLen = self::len($letters);
        $ratio = self::len($upper) / max(1, $letterLen);

        return $letterLen >= 6 && $ratio >= 0.85;
    }

    private static function softenAllCaps(string $subject): string
    {
        // Conserva {{placeholders}}; el resto a tipo oración (primera mayúscula).
        $parts = preg_split('/(\{\{[^}]+\}\})/', $subject, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$subject];
        $out = '';
        $firstTextDone = false;
        foreach ($parts as $part) {
            if (preg_match('/^\{\{[^}]+\}\}$/', $part) === 1) {
                $out .= $part;
                continue;
            }
            $lower = self::lower($part);
            if (!$firstTextDone && preg_match('/\p{L}/u', $lower) === 1) {
                $out .= self::upper(self::slice($lower, 0, 1)) . self::slice($lower, 1);
                $firstTextDone = true;
            } else {
                $out .= $lower;
            }
        }

        return trim($out) !== '' ? trim($out) : $subject;
    }

    private static function phishingHintIn(string $subject): ?string
    {
        $hay = self::lower($subject);
        foreach (self::PHISHING_HINTS as $hint) {
            if (str_contains($hay, $hint)) {
                return $hint;
            }
        }

        return null;
    }

    private static function safeFilenameBase(string $base): string
    {
        $base = trim($base);
        $base = preg_replace('/[^\p{L}\p{N}\-_ .]+/u', '-', $base) ?? $base;
        $base = preg_replace('/[ .]+/', '-', $base) ?? $base;
        $base = trim($base, '-_ ');

        return self::slice($base, 0, 80);
    }

    private static function looksRandomToken(string $base): bool
    {
        $compact = strtolower(preg_replace('/[^a-z0-9]/', '', $base) ?? '');
        if (strlen($compact) >= 24 && preg_match('/^[a-f0-9]+$/', $compact) === 1) {
            return true;
        }
        if (strlen($compact) >= 16 && preg_match('/[aeiou]/', $compact) !== 1) {
            return true;
        }

        return false;
    }

    private static function len(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    private static function upper(string $s): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    }

    private static function slice(string $s, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Enlaces temporales firmados para archivos (sin adjuntos en correo).
 */
final class SignedFileLinkService
{
    private const TTL_SECONDS = 90 * 86400;

    public function documentLink(int $documentId): string
    {
        return $this->buildUrl('doc', $documentId);
    }

    public function purchaseProofLink(int $purchaseId): string
    {
        return $this->buildUrl('proof', $purchaseId);
    }

    /**
     * @return array{type: string, id: int}
     */
    public function resolveToken(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Enlace inválido.');
        }

        $payloadJson = $this->base64UrlDecode($parts[0]);
        $data = json_decode($payloadJson, true);
        if (!is_array($data) || !isset($data['t'], $data['id'], $data['e'])) {
            throw new \InvalidArgumentException('Enlace inválido.');
        }

        $expectedSig = $this->sign($payloadJson);
        if (!hash_equals($expectedSig, $parts[1])) {
            throw new \InvalidArgumentException('Enlace inválido o alterado.');
        }

        if (time() > (int) $data['e']) {
            throw new \InvalidArgumentException('El enlace expiró. Solicita uno nuevo a DOCEO.');
        }

        $type = (string) $data['t'];
        if (!in_array($type, ['doc', 'proof'], true)) {
            throw new \InvalidArgumentException('Enlace inválido.');
        }

        return ['type' => $type, 'id' => (int) $data['id']];
    }

    private function buildUrl(string $type, int $id): string
    {
        $payloadJson = json_encode([
            't' => $type,
            'id' => $id,
            'e' => time() + self::TTL_SECONDS,
        ], JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            throw new \RuntimeException('No se pudo generar el enlace.');
        }

        $token = $this->base64UrlEncode($payloadJson) . '.' . $this->sign($payloadJson);

        return url('/archivo/' . $token);
    }

    private function sign(string $payloadJson): string
    {
        $key = (string) (Env::get('APP_KEY', '') ?? '');
        if ($key === '') {
            $key = (string) (Env::get('MAIL_LINK_SECRET', 'doceo-file-links') ?? 'doceo-file-links');
        }

        return substr(hash_hmac('sha256', $payloadJson, $key), 0, 32);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Enlace inválido.');
        }

        return $decoded;
    }
}

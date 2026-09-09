<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Entrega de resultados / cancelación de examen por grupo.
 *
 * config_json.results_delivery:
 *   mode: none | certificate_link | certificate_score | pdf
 *   cancel_template: código de plantilla de correo
 *
 * trackings.extra_json.results_delivery:
 *   score_report_url, pdf_path, pdf_name, cancelled, cancel_reason, …
 */
final class ResultsDeliveryService
{
    public const MODE_NONE = 'none';
    public const MODE_CERTIFICATE = 'certificate_link';
    public const MODE_CERTIFICATE_SCORE = 'certificate_score';
    public const MODE_PDF = 'pdf';

    public const MODES = [
        self::MODE_NONE => 'No enviar (el proveedor avisa al alumno)',
        self::MODE_CERTIFICATE => 'Enlace al certificado',
        self::MODE_CERTIFICATE_SCORE => 'Enlace al certificado + score report',
        self::MODE_PDF => 'Archivo PDF de resultados',
    ];

    /**
     * @param array<string, mixed> $config config mergeado del producto/grupo
     * @return array{mode:string,enabled:bool,cancel_template:string}
     */
    public static function fromConfig(array $config): array
    {
        $raw = is_array($config['results_delivery'] ?? null) ? $config['results_delivery'] : [];
        $mode = strtolower(trim((string) ($raw['mode'] ?? self::MODE_NONE)));
        if (!isset(self::MODES[$mode])) {
            $mode = self::MODE_NONE;
        }

        return [
            'mode' => $mode,
            'enabled' => $mode !== self::MODE_NONE,
            'cancel_template' => trim((string) ($raw['cancel_template'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $input POST del formulario de grupo
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function applyFromGroupInput(array $input, array $config): array
    {
        $mode = strtolower(trim((string) ($input['results_delivery_mode'] ?? self::MODE_NONE)));
        if (!isset(self::MODES[$mode])) {
            $mode = self::MODE_NONE;
        }
        if ($mode === self::MODE_NONE) {
            unset($config['results_delivery']);

            return $config;
        }

        $config['results_delivery'] = [
            'mode' => $mode,
            'cancel_template' => trim((string) ($input['results_cancel_template'] ?? '')),
        ];

        return $config;
    }

    /**
     * @param array<string, mixed> $tracking
     * @return array{
     *   score_report_url:string,
     *   pdf_path:string,
     *   pdf_name:string,
     *   cancelled:bool,
     *   cancel_reason:string,
     *   updated_at:?string
     * }
     */
    public static function stateFromTracking(array $tracking): array
    {
        $extra = [];
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        }
        $bag = is_array($extra['results_delivery'] ?? null) ? $extra['results_delivery'] : [];

        return [
            'score_report_url' => trim((string) ($bag['score_report_url'] ?? '')),
            'pdf_path' => trim((string) ($bag['pdf_path'] ?? '')),
            'pdf_name' => trim((string) ($bag['pdf_name'] ?? '')),
            'cancelled' => !empty($bag['cancelled']),
            'cancel_reason' => trim((string) ($bag['cancel_reason'] ?? '')),
            'updated_at' => isset($bag['updated_at']) ? (string) $bag['updated_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $tracking
     * @param array{mode:string,enabled?:bool,cancel_template?:string} $delivery
     */
    public static function isReady(array $tracking, array $delivery): bool
    {
        if (empty($delivery['enabled']) && ($delivery['mode'] ?? self::MODE_NONE) === self::MODE_NONE) {
            return false;
        }
        $state = self::stateFromTracking($tracking);
        if ($state['cancelled']) {
            return $state['cancel_reason'] !== '';
        }

        return match ((string) ($delivery['mode'] ?? self::MODE_NONE)) {
            self::MODE_CERTIFICATE => trim((string) ($tracking['results_url'] ?? '')) !== '',
            self::MODE_CERTIFICATE_SCORE => trim((string) ($tracking['results_url'] ?? '')) !== ''
                && $state['score_report_url'] !== '',
            self::MODE_PDF => $state['pdf_path'] !== '',
            default => false,
        };
    }

    /**
     * @param array{mode:string,enabled?:bool,cancel_template?:string} $delivery
     */
    public static function blockedReason(array $tracking, array $delivery): string
    {
        if (($delivery['mode'] ?? self::MODE_NONE) === self::MODE_NONE) {
            return 'Este grupo no envía resultados desde DOCEO.';
        }
        $state = self::stateFromTracking($tracking);
        if ($state['cancelled']) {
            return $state['cancel_reason'] === ''
                ? 'Indica el motivo de cancelación en el detalle del caso.'
                : '';
        }

        return match ((string) $delivery['mode']) {
            self::MODE_CERTIFICATE => 'Sube el enlace del certificado en el detalle del caso.',
            self::MODE_CERTIFICATE_SCORE => 'Sube el enlace del certificado y del score report en el detalle.',
            self::MODE_PDF => 'Sube el PDF de resultados en el detalle del caso.',
            default => 'Completa los resultados en el detalle del caso.',
        };
    }

    /**
     * Placeholders sugeridos según el modo (para UI / plantillas).
     *
     * @return list<string>
     */
    public static function suggestedPlaceholders(string $mode): array
    {
        return match ($mode) {
            self::MODE_CERTIFICATE => ['results_url', 'name', 'certificacion', 'matricula'],
            self::MODE_CERTIFICATE_SCORE => [
                'results_url', 'score_report_url', 'results_score', 'results_level',
                'name', 'certificacion', 'matricula',
            ],
            self::MODE_PDF => ['results_pdf_url', 'name', 'certificacion', 'matricula'],
            default => ['cancel_reason', 'name', 'certificacion', 'matricula'],
        };
    }
}

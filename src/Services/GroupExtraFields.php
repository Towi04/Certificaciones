<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Campos extra configurables por grupo (además de folio/clave).
 *
 * Config en product_groups.config_json.exam.extra_fields:
 *   [{ "code": "score_report", "label": "Score report" }, ...]
 *
 * Compat: exam.extra_field_label + exam.capture_zoom (un solo campo → code "extra").
 *
 * Valores por alumno en trackings.extra_json.access_fields[code].
 * El primer campo también se espeja en trackings.zoom_url (compat Ops / plantillas {{zoom}}).
 */
final class GroupExtraFields
{
    public const LEGACY_CODE = 'extra';

    /**
     * @param array<string, mixed> $examCfg
     * @return list<array{code:string,label:string}>
     */
    public static function fromExamConfig(array $examCfg): array
    {
        $raw = $examCfg['extra_fields'] ?? null;
        $out = [];
        $used = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = trim((string) ($row['label'] ?? ''));
                $code = self::normalizeCode((string) ($row['code'] ?? ''));
                if ($label === '' && $code === '') {
                    continue;
                }
                if ($label === '') {
                    $label = $code;
                }
                if ($code === '') {
                    $code = self::normalizeCode($label) ?: ('campo_' . (count($out) + 1));
                }
                $base = $code;
                $n = 2;
                while (isset($used[$code])) {
                    $code = $base . '_' . $n;
                    $n++;
                }
                $used[$code] = true;
                $out[] = [
                    'code' => $code,
                    'label' => mb_substr($label, 0, 60),
                ];
            }
        }

        if ($out === []) {
            $legacy = trim((string) ($examCfg['extra_field_label'] ?? ''));
            if ($legacy !== '' || !empty($examCfg['capture_zoom'])) {
                $out[] = [
                    'code' => self::LEGACY_CODE,
                    'label' => $legacy !== '' ? mb_substr($legacy, 0, 60) : 'Zoom',
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $groupOrProductConfig
     * @return list<array{code:string,label:string}>
     */
    public static function fromGroupConfig(?array $groupOrProductConfig): array
    {
        $exam = is_array($groupOrProductConfig['exam'] ?? null)
            ? $groupOrProductConfig['exam']
            : [];

        return self::fromExamConfig($exam);
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';

        return trim($code, '_');
    }

    /**
     * @param array<string, mixed> $tracking
     * @return array<string, string>
     */
    public static function valuesFromTracking(array $tracking): array
    {
        $extra = [];
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);
            $extra = is_array($decoded) ? $decoded : [];
        } elseif (is_array($tracking['extra_json'] ?? null)) {
            $extra = $tracking['extra_json'];
        }
        $bag = is_array($extra['access_fields'] ?? null) ? $extra['access_fields'] : [];
        $out = [];
        foreach ($bag as $code => $value) {
            if (!is_string($code) && !is_int($code)) {
                continue;
            }
            $c = self::normalizeCode((string) $code);
            if ($c === '') {
                continue;
            }
            $out[$c] = trim((string) $value);
        }
        $zoom = trim((string) ($tracking['zoom_url'] ?? ''));
        if ($zoom !== '' && !isset($out[self::LEGACY_CODE])) {
            // Compat: zoom_url alimenta el primer hueco conocido.
            if ($out === []) {
                $out[self::LEGACY_CODE] = $zoom;
            }
        }

        return $out;
    }

    /**
     * Variables de correo: cada code → {{code}}, más aliases legacy.
     *
     * @param list<array{code:string,label:string}> $fields
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public static function mailVars(array $fields, array $values): array
    {
        $vars = [];
        $firstValue = '';
        $firstLabel = '';
        foreach ($fields as $i => $field) {
            $code = (string) ($field['code'] ?? '');
            $label = (string) ($field['label'] ?? $code);
            if ($code === '') {
                continue;
            }
            $val = trim((string) ($values[$code] ?? ''));
            $vars[$code] = $val;
            $vars[$code . '_label'] = $label;
            if ($i === 0) {
                $firstValue = $val;
                $firstLabel = $label;
            }
        }
        if ($firstLabel === '' && $fields === []) {
            $firstLabel = 'Zoom';
            $firstValue = trim((string) ($values[self::LEGACY_CODE] ?? ''));
        }
        // Aliases históricos de plantillas.
        $vars['extra'] = $firstValue !== '' ? $firstValue : trim((string) ($values[self::LEGACY_CODE] ?? ''));
        $vars['zoom'] = $vars['extra'];
        $vars['zoom_url'] = $vars['extra'];
        $vars['extra_label'] = $firstLabel !== '' ? $firstLabel : 'Zoom';
        $vars['zoom_label'] = $vars['extra_label'];

        return $vars;
    }

    /**
     * @param list<array{code?:string,label?:string}|mixed> $rows
     * @return list<array{code:string,label:string}>
     */
    public static function normalizeInputRows(array $rows): array
    {
        return self::fromExamConfig(['extra_fields' => $rows]);
    }
}

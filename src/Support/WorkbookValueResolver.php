<?php

declare(strict_types=1);

namespace App\Support;

/** Resuelve el valor de una celda mapeada (campo plano o fórmula). */
final class WorkbookValueResolver
{
    /**
     * @param array{cell?:string,field?:string,formula?:string} $map
     * @param array<string, string> $fields
     */
    public static function resolve(array $map, array $fields, string $legacyNormalize = 'none'): string
    {
        $formula = trim((string) ($map['formula'] ?? ''));
        if ($formula !== '') {
            return CellFormulaEvaluator::evaluate($formula, $fields);
        }

        $field = trim((string) ($map['field'] ?? ''));
        $value = $field !== '' ? (string) ($fields[$field] ?? '') : '';
        if ($legacyNormalize === 'toefl') {
            $value = AsciiUpperNormalizer::normalize($value);
        }

        return $value;
    }
}

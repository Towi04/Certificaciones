<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Rellena celdas de una plantilla .xlsx (Office Open XML) sin dependencias externas.
 * Usa valores inlineStr para no manipular sharedStrings.
 */
final class XlsxCellFiller
{
    /**
     * @param array<string, string> $cellValues keyed by cell ref (e.g. B2 => "Juan Pérez")
     * @return string absolute path to generated temp xlsx
     */
    public function fill(string $templateAbsolutePath, array $cellValues, ?string $outputAbsolutePath = null): string
    {
        if (!is_file($templateAbsolutePath)) {
            throw new \InvalidArgumentException('Plantilla Excel no encontrada.');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive no está disponible en el servidor (necesario para Excel).');
        }
        if ($cellValues === []) {
            throw new \InvalidArgumentException('No hay celdas para rellenar en la plantilla.');
        }

        $out = $outputAbsolutePath ?? (sys_get_temp_dir() . '/doceo_xlsx_' . bin2hex(random_bytes(8)) . '.xlsx');
        if (!@copy($templateAbsolutePath, $out)) {
            throw new \RuntimeException('No se pudo copiar la plantilla Excel.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($out) !== true) {
            @unlink($out);
            throw new \RuntimeException('No se pudo abrir la plantilla Excel.');
        }

        $sheetPath = $this->resolveFirstWorksheetPath($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $zip->close();
            @unlink($out);
            throw new \RuntimeException('La plantilla Excel no tiene hoja de cálculo legible.');
        }

        $updated = $this->applyCellsToSheetXml($sheetXml, $cellValues);
        $zip->addFromString($sheetPath, $updated);
        $zip->close();

        return $out;
    }

    private function resolveFirstWorksheetPath(\ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        if (preg_match('/sheet[^>]*r:id="(rId\d+)"/i', $workbook, $m)) {
            $rid = $m[1];
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if (is_string($rels) && preg_match(
                '/Relationship[^>]*Id="' . preg_quote($rid, '/') . '"[^>]*Target="([^"]+)"/i',
                $rels,
                $tm
            )) {
                $target = ltrim(str_replace('\\', '/', $tm[1]), '/');
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . $target;
                }

                return $target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @param array<string, string> $cellValues
     */
    private function applyCellsToSheetXml(string $sheetXml, array $cellValues): string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (@$dom->loadXML($sheetXml) !== true) {
            throw new \RuntimeException('XML de la hoja Excel inválido.');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheetData = $xpath->query('/m:worksheet/m:sheetData')->item(0);
        if (!$sheetData instanceof \DOMElement) {
            throw new \RuntimeException('La hoja Excel no tiene sheetData.');
        }

        foreach ($cellValues as $ref => $value) {
            $ref = strtoupper(trim((string) $ref));
            if ($ref === '' || !preg_match('/^([A-Z]+)(\d+)$/', $ref, $parts)) {
                continue;
            }
            $col = $parts[1];
            $rowNum = (int) $parts[2];
            $text = $this->xmlSafe((string) $value);

            $row = $this->ensureRow($xpath, $dom, $sheetData, $rowNum);
            $cell = $this->ensureCell($xpath, $dom, $row, $ref, $col);

            while ($cell->firstChild) {
                $cell->removeChild($cell->firstChild);
            }
            $cell->setAttribute('t', 'inlineStr');
            $is = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is');
            $t = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't');
            $t->appendChild($dom->createTextNode($text));
            $is->appendChild($t);
            $cell->appendChild($is);
        }

        $out = $dom->saveXML();
        if ($out === false) {
            throw new \RuntimeException('No se pudo serializar la hoja Excel.');
        }

        return $out;
    }

    private function ensureRow(\DOMXPath $xpath, \DOMDocument $dom, \DOMElement $sheetData, int $rowNum): \DOMElement
    {
        $existing = $xpath->query('./m:row[@r="' . $rowNum . '"]', $sheetData)->item(0);
        if ($existing instanceof \DOMElement) {
            return $existing;
        }

        $row = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'row');
        $row->setAttribute('r', (string) $rowNum);

        $inserted = false;
        foreach (iterator_to_array($sheetData->childNodes) as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'row') {
                continue;
            }
            $r = (int) $child->getAttribute('r');
            if ($r > $rowNum) {
                $sheetData->insertBefore($row, $child);
                $inserted = true;
                break;
            }
        }
        if (!$inserted) {
            $sheetData->appendChild($row);
        }

        return $row;
    }

    private function ensureCell(
        \DOMXPath $xpath,
        \DOMDocument $dom,
        \DOMElement $row,
        string $ref,
        string $col
    ): \DOMElement {
        $existing = $xpath->query('./m:c[@r="' . $ref . '"]', $row)->item(0);
        if ($existing instanceof \DOMElement) {
            return $existing;
        }

        $cell = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c');
        $cell->setAttribute('r', $ref);

        $colIndex = $this->columnIndex($col);
        $inserted = false;
        foreach (iterator_to_array($row->childNodes) as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'c') {
                continue;
            }
            $childRef = (string) $child->getAttribute('r');
            if (preg_match('/^([A-Z]+)/', $childRef, $m)) {
                if ($this->columnIndex($m[1]) > $colIndex) {
                    $row->insertBefore($cell, $child);
                    $inserted = true;
                    break;
                }
            }
        }
        if (!$inserted) {
            $row->appendChild($cell);
        }

        return $cell;
    }

    private function columnIndex(string $col): int
    {
        $col = strtoupper($col);
        $n = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }

        return $n;
    }

    private function xmlSafe(string $value): string
    {
        return str_replace("\0", '', $value);
    }
}

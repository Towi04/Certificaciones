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
     * @param string|null $sheetSelector Nombre de hoja (p. ej. "Datos") o índice 1-based ("1", "2")
     * @return string absolute path to generated temp xlsx
     */
    public function fill(
        string $templateAbsolutePath,
        array $cellValues,
        ?string $outputAbsolutePath = null,
        ?string $sheetSelector = null
    ): string {
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

        try {
            $sheetPath = $this->resolveWorksheetPath($zip, $sheetSelector);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new \RuntimeException('La plantilla Excel no tiene hoja de cálculo legible.');
            }

            $updated = $this->applyCellsToSheetXml($sheetXml, $cellValues);
            $zip->addFromString($sheetPath, $updated);
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($out);
            throw $e;
        }

        $zip->close();

        return $out;
    }

    /**
     * @return list<array{name:string,path:string,index:int}>
     */
    public function listSheets(string $templateAbsolutePath): array
    {
        if (!is_file($templateAbsolutePath) || !class_exists(\ZipArchive::class)) {
            return [];
        }
        $zip = new \ZipArchive();
        if ($zip->open($templateAbsolutePath) !== true) {
            return [];
        }
        $sheets = $this->workbookSheets($zip);
        $zip->close();

        return $sheets;
    }

    private function resolveWorksheetPath(\ZipArchive $zip, ?string $sheetSelector): string
    {
        $sheets = $this->workbookSheets($zip);
        if ($sheets === []) {
            return 'xl/worksheets/sheet1.xml';
        }

        $selector = trim((string) $sheetSelector);
        if ($selector === '') {
            return $sheets[0]['path'];
        }

        if (ctype_digit($selector)) {
            $idx = (int) $selector;
            foreach ($sheets as $sheet) {
                if ((int) $sheet['index'] === $idx) {
                    return $sheet['path'];
                }
            }
            throw new \RuntimeException('No existe la hoja #' . $idx . ' en la plantilla Excel.');
        }

        foreach ($sheets as $sheet) {
            if (strcasecmp($sheet['name'], $selector) === 0) {
                return $sheet['path'];
            }
        }

        throw new \RuntimeException(
            'No se encontró la hoja "' . $selector . '" en la plantilla Excel. '
            . 'Hojas disponibles: ' . implode(', ', array_column($sheets, 'name'))
        );
    }

    /**
     * @return list<array{name:string,path:string,index:int}>
     */
    private function workbookSheets(\ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false) {
            return [['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml', 'index' => 1]];
        }

        $ridToTarget = [];
        if (is_string($rels) && preg_match_all(
            '/Relationship[^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"/i',
            $rels,
            $rm,
            PREG_SET_ORDER
        )) {
            foreach ($rm as $row) {
                $target = ltrim(str_replace('\\', '/', $row[2]), '/');
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . $target;
                }
                $ridToTarget[$row[1]] = $target;
            }
        }

        $sheets = [];
        if (preg_match_all(
            '/<sheet\b[^>]*>/i',
            $workbook,
            $sm,
            PREG_SET_ORDER
        )) {
            $i = 0;
            foreach ($sm as $tagMatch) {
                $tag = $tagMatch[0];
                $name = 'Hoja' . ($i + 1);
                if (preg_match('/\bname="([^"]+)"/i', $tag, $nm)) {
                    $name = html_entity_decode($nm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                $rid = '';
                if (preg_match('/\br:id="(rId\d+)"/i', $tag, $rmatch)) {
                    $rid = $rmatch[1];
                }
                $path = $rid !== '' && isset($ridToTarget[$rid])
                    ? $ridToTarget[$rid]
                    : ('xl/worksheets/sheet' . ($i + 1) . '.xml');
                $sheets[] = [
                    'name' => $name,
                    'path' => $path,
                    'index' => $i + 1,
                ];
                $i++;
            }
        }

        if ($sheets === []) {
            return [['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml', 'index' => 1]];
        }

        return $sheets;
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

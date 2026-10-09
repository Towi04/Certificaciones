<?php

declare(strict_types=1);

/**
 * Fase 8 — matriz A–D (lógica de servicios + pasos MANUAL).
 *
 * Uso: php bin/cutover-phase8-matrix.php
 * Exit 0 = lógica OK; exit 1 = fallos.
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Setup\CutoverPhase8Matrix;

$result = (new CutoverPhase8Matrix())->run();
foreach ($result['lines'] as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}
exit($result['ok'] ? 0 : 1);

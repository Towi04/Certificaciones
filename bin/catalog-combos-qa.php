<?php

declare(strict_types=1);

/**
 * Fase 4 — QA combos en catálogo (lógica + pasos MANUAL).
 *
 * Uso: php bin/catalog-combos-qa.php
 * Exit 0 = lógica OK; exit 1 = fallos.
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Setup\CatalogCombosQa;

$result = (new CatalogCombosQa())->run();
foreach ($result['lines'] as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}
exit($result['ok'] ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * Fase 4 — QA reglamento Cambridge fill_acroform (lógica + pasos MANUAL).
 *
 * Uso: php bin/cambridge-reglamento-qa.php
 * Exit 0 = lógica OK; exit 1 = fallos.
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Setup\CambridgeReglamentoQa;

$result = (new CambridgeReglamentoQa())->run();
foreach ($result['lines'] as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}
exit($result['ok'] ? 0 : 1);

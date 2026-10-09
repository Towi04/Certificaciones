<?php

declare(strict_types=1);

/**
 * Fase 8 — readiness cutover UKS/Cambridge/CENNI.
 *
 * Uso: php bin/cutover-readiness.php
 * Exit 0 = listo; exit 1 = fallos.
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Setup\CutoverReadinessChecker;

$result = (new CutoverReadinessChecker())->run();
foreach ($result['lines'] as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}
exit($result['ok'] ? 0 : 1);

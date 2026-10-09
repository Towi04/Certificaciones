<?php

declare(strict_types=1);

/**
 * Parche seguro cutover UKS/Cambridge/CENNI (no pisa agendas ni docs ya editados).
 *
 * Uso: php bin/ensure-cutover-config.php
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Setup\CutoverConfigEnsurer;

foreach ((new CutoverConfigEnsurer())->run() as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}
fwrite(STDOUT, PHP_EOL . 'Siguiente: php bin/cutover-readiness.php' . PHP_EOL);
exit(0);

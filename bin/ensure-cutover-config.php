<?php

declare(strict_types=1);

/**
 * Parche seguro cutover UKS/Cambridge/CENNI (no pisa agendas ni docs ya editados).
 *
 * Uso: php bin/ensure-cutover-config.php
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Config\Env;
use App\Setup\CutoverConfigEnsurer;

$dbName = trim((string) (Env::get('DB_NAME', '') ?? ''));
if ($dbName === '') {
    fwrite(STDOUT, 'WARN  BD no configurada (DB_NAME vacío): nada que parchear aquí.' . PHP_EOL);
    fwrite(STDOUT, '      En staging/prod ejecuta de nuevo con .env completo.' . PHP_EOL);
    fwrite(STDOUT, PHP_EOL . 'Siguiente: php bin/cutover-readiness.php' . PHP_EOL);
    fwrite(STDOUT, '          php bin/cutover-phase8-matrix.php' . PHP_EOL);
    exit(0);
}

try {
    foreach ((new CutoverConfigEnsurer())->run() as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL  ensure-cutover-config: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, PHP_EOL . 'Siguiente: php bin/provider-templates-qa.php' . PHP_EOL);
fwrite(STDOUT, '          php bin/cutover-readiness.php' . PHP_EOL);
fwrite(STDOUT, '          php bin/cutover-phase8-matrix.php' . PHP_EOL);
exit(0);

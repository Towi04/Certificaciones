<?php

declare(strict_types=1);

/**
 * Fase 4 — QA plantillas proveedor (CSV descarga + Excel correo).
 *
 * Uso: php bin/provider-templates-qa.php
 * Exit 0 = lógica OK (puede haber pasos MANUAL); exit 1 = fallos.
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Setup\ProviderTemplatesQa;

$result = (new ProviderTemplatesQa())->run();
foreach ($result['lines'] as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}
exit($result['ok'] ? 0 : 1);

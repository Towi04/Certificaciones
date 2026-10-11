<?php

declare(strict_types=1);

/**
 * Migra Excel de correo/grupos al catálogo Plantillas proveedor (file_type=xlsx).
 * No borra Settings ni archivos. Idempotente.
 *
 * Uso: php bin/migrate-provider-workbooks.php
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Config\Env;
use App\Services\ProviderWorkbookCatalogService;

$dbName = trim((string) (Env::get('DB_NAME', '') ?? ''));
if ($dbName === '') {
    fwrite(STDOUT, 'WARN  BD no configurada (DB_NAME vacío): nada que migrar aquí.' . PHP_EOL);
    exit(0);
}

try {
    foreach ((new ProviderWorkbookCatalogService())->migrateFromLegacy() as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL  migrate-provider-workbooks: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, PHP_EOL . 'Siguiente: php bin/provider-templates-qa.php' . PHP_EOL);
exit(0);

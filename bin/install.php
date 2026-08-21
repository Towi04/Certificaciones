<?php

declare(strict_types=1);

/**
 * Instala el schema y crea el usuario admin desde .env
 * Uso: php bin/install.php
 */

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;

$schema = file_get_contents($root . '/sql/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "No se pudo leer sql/schema.sql\n");
    exit(1);
}

$pdo = Connection::get();

// Ejecutar statement por statement (hosting compartido / PDO)
$parts = preg_split('/;\s*\n/', $schema) ?: [];
foreach ($parts as $sql) {
    $sql = trim($sql);
    if ($sql === '' || str_starts_with($sql, '--')) {
        continue;
    }
    // quitar líneas de comentario iniciales
    $lines = array_filter(explode("\n", $sql), static fn ($l) => !str_starts_with(trim($l), '--'));
    $sql = trim(implode("\n", $lines));
    if ($sql === '') {
        continue;
    }
    try {
        $pdo->exec($sql);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Error SQL: " . $e->getMessage() . "\nFragmento: " . substr($sql, 0, 120) . "…\n");
        exit(1);
    }
}
fwrite(STDOUT, "Schema aplicado.\n");

Auth::ensureAdminFromEnv();
fwrite(STDOUT, "Admin verificado/creado desde .env (ADMIN_EMAIL / ADMIN_PASSWORD).\n");
fwrite(STDOUT, "Siguiente: php bin/seed-catalog.php\n");
exit(0);

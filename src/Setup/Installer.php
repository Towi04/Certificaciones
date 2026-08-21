<?php

declare(strict_types=1);

namespace App\Setup;

use App\Auth\Auth;
use App\Database\Connection;

final class Installer
{
    /** @return list<string> */
    public function applySchema(): array
    {
        $log = [];
        $path = BASE_PATH . '/sql/schema.sql';
        $schema = file_get_contents($path);
        if ($schema === false) {
            throw new \RuntimeException('No se pudo leer sql/schema.sql');
        }

        $pdo = Connection::get();
        $parts = preg_split('/;\s*\n/', $schema) ?: [];
        $ok = 0;
        foreach ($parts as $sql) {
            $sql = trim($sql);
            if ($sql === '' || str_starts_with($sql, '--')) {
                continue;
            }
            $lines = array_filter(explode("\n", $sql), static fn ($l) => !str_starts_with(trim($l), '--'));
            $sql = trim(implode("\n", $lines));
            if ($sql === '') {
                continue;
            }
            $pdo->exec($sql);
            $ok++;
        }
        $log[] = "Schema aplicado ({$ok} sentencias).";

        return $log;
    }

    /** @return list<string> */
    public function ensureAdmin(): array
    {
        Auth::ensureAdminFromEnv();

        return ['Usuario admin verificado/creado desde ADMIN_EMAIL / ADMIN_PASSWORD del .env.'];
    }

    /** @return list<string> */
    public function runAll(bool $withSeed = true): array
    {
        $log = $this->applySchema();
        $log = array_merge($log, $this->ensureAdmin());
        if ($withSeed) {
            $log = array_merge($log, (new CatalogSeeder())->run());
        }

        return $log;
    }
}

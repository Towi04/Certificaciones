#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Suspende matrículas Moodle vencidas (timeend / access_ends_at).
 * Programar en cron, p.ej. diario:
 *   15 3 * * * php /path/to/bin/moodle-expire-enrolments.php
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use App\Catalog\CatalogRepository;
use App\Integrations\MoodleEnrolService;

$repo = new CatalogRepository();
$result = (new MoodleEnrolService($repo))->suspendExpiredEnrolments(500);
fwrite(STDOUT, 'suspended=' . (int) ($result['suspended'] ?? 0) . PHP_EOL);
if (!empty($result['errors'])) {
    foreach ($result['errors'] as $err) {
        fwrite(STDERR, $err . PHP_EOL);
    }
}
exit(0);

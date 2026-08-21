<?php

declare(strict_types=1);

/** CLI opcional. En Neubox usa /setup.php?key=... */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Setup\CatalogSeeder;

foreach ((new CatalogSeeder())->run() as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}
exit(0);

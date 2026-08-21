<?php

declare(strict_types=1);

/**
 * Instalador en la RAÍZ del repo (Neubox suele servir desde aquí).
 *
 * https://pdv.institutodoceo.com/setup.php?key=TU_INSTALL_KEY
 * https://pdv.institutodoceo.com/setup?key=TU_INSTALL_KEY
 */

$bootstrapCandidates = [
    __DIR__ . '/bootstrap.php',
    __DIR__ . '/public/bootstrap.php',
];
$bootstrapPath = null;
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        $bootstrapPath = $candidate;
        break;
    }
}
if ($bootstrapPath === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    die("No se encontró bootstrap.php junto a setup.php.\nRuta actual: " . __DIR__ . "\n");
}

require $bootstrapPath;

\App\Setup\WebSetup::runFromRequest();

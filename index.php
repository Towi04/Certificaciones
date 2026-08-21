<?php

declare(strict_types=1);

/**
 * Front controller para Neubox cuando el docroot es la raíz del subdominio
 * (no la carpeta public/). Reemplaza el index.php viejo de "En construcción".
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
    die("No se encontró bootstrap.php en la raíz del sitio.\n");
}

require $bootstrapPath;

/** @var \App\Http\Router $router */
$router = require BASE_PATH . '/routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');

<?php

declare(strict_types=1);

$bootstrapCandidates = [
    __DIR__ . '/bootstrap.php',
    dirname(__DIR__) . '/bootstrap.php',
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
    die('No se encontró bootstrap.php.');
}

require $bootstrapPath;

/** @var \App\Http\Router $router */
$router = require BASE_PATH . '/routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');

<?php

declare(strict_types=1);

// Soporte para dos despliegues comunes:
// 1) Docroot = /public        => bootstrap.php está 1 nivel arriba
// 2) public/ movido a la raíz => bootstrap.php está en el mismo directorio
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
    die('No se encontró bootstrap.php. Revisa rutas/document root y que bootstrap.php exista.');
}

require $bootstrapPath;

use App\Config\Env;

header('Content-Type: text/html; charset=UTF-8');

$brandPrimary = '#315285';
$brandGray = '#C4C4C4';
$brandYellow = '#F5DF25';
$appName = app_name();

// Soporte a docroot “/public” y a “public/ movido a raíz”.
// Detectamos en filesystem dónde están los assets y generamos el URL correcto.
function assets_url(string $relativeUrl, array $filesystemCandidates): string
{
    foreach ($filesystemCandidates as $fs) {
        if (is_file($fs)) {
            return $relativeUrl;
        }
    }
    // Fallback: usar la convención /assets/... basada en APP_URL
    return asset($relativeUrl);
}

$logoUrl = assets_url(
    '/assets/brand/logo.png',
    [
        __DIR__ . '/assets/brand/logo.png',
        dirname(__DIR__) . '/assets/brand/logo.png',
        __DIR__ . '/public/assets/brand/logo.png',
        dirname(__DIR__) . '/public/assets/brand/logo.png',
    ]
);

$faviconUrl = assets_url(
    '/assets/brand/favicon.ico',
    [
        __DIR__ . '/assets/brand/favicon.ico',
        dirname(__DIR__) . '/assets/brand/favicon.ico',
        __DIR__ . '/public/assets/brand/favicon.ico',
        dirname(__DIR__) . '/public/assets/brand/favicon.ico',
    ]
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?></title>
    <link rel="icon" href="<?= e($faviconUrl) ?>" type="image/x-icon">
    <style>
        :root {
            --doceo-blue: <?= e($brandPrimary) ?>;
            --doceo-gray: <?= e($brandGray) ?>;
            --doceo-yellow: <?= e($brandYellow) ?>;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: linear-gradient(160deg, #f7f9fc 0%, #eef2f8 45%, #fff 100%);
            color: #1a2a40;
        }
        .wrap {
            max-width: 720px;
            margin: 0 auto;
            padding: 3rem 1.5rem;
            text-align: center;
        }
        .logo { width: min(220px, 70vw); height: auto; margin-bottom: 1.5rem; }
        h1 { color: var(--doceo-blue); font-size: clamp(1.6rem, 4vw, 2.2rem); margin: 0 0 .75rem; }
        p { color: #445; line-height: 1.6; margin: 0 0 1rem; }
        .badge {
            display: inline-block;
            background: var(--doceo-yellow);
            color: #2a2a00;
            font-weight: 600;
            padding: .35rem .85rem;
            border-radius: 999px;
            font-size: .85rem;
            margin-bottom: 1.5rem;
        }
        .card {
            background: #fff;
            border: 1px solid var(--doceo-gray);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            text-align: left;
            margin-top: 1.5rem;
        }
        .card h2 { margin: 0 0 .5rem; font-size: 1rem; color: var(--doceo-blue); }
        .muted { color: #667; font-size: .9rem; }
        code { background: #f0f3f8; padding: .1rem .35rem; border-radius: 4px; font-size: .85em; }
    </style>
</head>
<body>
    <div class="wrap">
        <img class="logo" src="<?= e($logoUrl) ?>" alt="<?= e($appName) ?>">
        <span class="badge">🐝 En construcción</span>
        <h1><?= e($appName) ?></h1>
        <p>Base del sistema lista. Aquí irá el catálogo de productos, acceso para administradores y partners.</p>
        <div class="card">
            <h2>Entorno</h2>
            <p class="muted">
                Modo: <code><?= e(Env::get('APP_ENV', 'production') ?? 'production') ?></code><br>
                URL: <code><?= e(Env::get('APP_URL', '(sin APP_URL)') ?? '') ?></code>
            </p>
        </div>
    </div>
</body>
</html>

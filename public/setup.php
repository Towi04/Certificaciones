<?php

declare(strict_types=1);

/**
 * Instalador WEB para hosting compartido (Neubox / cPanel).
 *
 * Uso (navegador):
 *   https://pdv.institutodoceo.com/setup.php?key=TU_INSTALL_KEY
 *
 * Opciones:
 *   &seed=0     → solo schema + admin (sin productos de ejemplo)
 *   &seed=1     → schema + admin + catálogo (default)
 *
 * Requiere INSTALL_KEY en el .env del servidor.
 * Después de instalar, borra o renombra este archivo por seguridad.
 */

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
    die("No se encontró bootstrap.php.\n");
}

require $bootstrapPath;

use App\Config\Env;
use App\Setup\Installer;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$installKey = trim((string) (Env::get('INSTALL_KEY', '') ?? ''));
$provided = (string) ($_GET['key'] ?? '');

function setup_page(string $title, string $bodyHtml): void
{
    $name = htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>
      body{font-family:Segoe UI,system-ui,sans-serif;background:#f4f7fb;color:#1a2a40;margin:0;padding:2rem}
      .card{max-width:720px;margin:0 auto;background:#fff;border:1px solid #C4C4C4;border-radius:14px;padding:1.25rem 1.4rem}
      h1{color:#315285;margin:0 0 .75rem;font-size:1.35rem}
      .ok{color:#176b3a}.err{color:#8a1f1f}
      ul{line-height:1.6} code{background:#eef3f9;padding:.1rem .35rem;border-radius:4px}
      a{color:#315285}
    </style></head><body><div class="card">';
    echo '<p style="margin:0 0 1rem"><strong>' . $name . '</strong> · instalador web</p>';
    echo $bodyHtml;
    echo '</div></body></html>';
}

if ($installKey === '') {
    http_response_code(503);
    setup_page('Falta INSTALL_KEY', '<h1 class="err">Falta INSTALL_KEY</h1>
        <p>Agrega en tu <code>.env</code> del servidor una línea como:</p>
        <p><code>INSTALL_KEY=una-clave-larga-secreta</code></p>
        <p>Luego abre <code>/setup.php?key=una-clave-larga-secreta</code></p>');
    exit;
}

if ($provided === '' || !hash_equals($installKey, $provided)) {
    http_response_code(403);
    setup_page('Acceso denegado', '<h1 class="err">Acceso denegado</h1>
        <p>Debes pasar la clave correcta: <code>/setup.php?key=...</code></p>');
    exit;
}

$withSeed = !isset($_GET['seed']) || (string) $_GET['seed'] !== '0';

try {
    $log = (new Installer())->runAll($withSeed);
    $items = '';
    foreach ($log as $line) {
        $items .= '<li class="ok">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    setup_page('Instalación OK', '<h1 class="ok">Instalación completada</h1>
        <ul>' . $items . '</ul>
        <p>Siguiente:</p>
        <ol>
          <li>Entra a <a href="/login">/login</a> con <code>ADMIN_EMAIL</code> / <code>ADMIN_PASSWORD</code></li>
          <li>Revisa el catálogo en <a href="/">/</a></li>
          <li><strong>Borra o renombra <code>setup.php</code></strong> del servidor por seguridad</li>
        </ol>');
} catch (Throwable $e) {
    http_response_code(500);
    setup_page('Error', '<h1 class="err">Error al instalar</h1>
        <p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>
        <p>Revisa DB_* en <code>.env</code> y que la base exista en cPanel.</p>');
}

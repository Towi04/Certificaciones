<?php
set_time_limit(300);
ini_set('memory_limit', '512M');
ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// CONFIGURACIÓN — copia este archivo como upload_version.php en el servidor
$username   = 'Towi04';
$repo       = 'Certificaciones';
// Personal Access Token (classic) con scope "repo", o fine-grained con Contents: Read.
// Si el deploy falla con «No se pudo descargar el repositorio», renueva este token.
$token      = 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$secret_key = 'tu-clave-secreta-de-deploy';

if (!isset($_GET['key']) || $_GET['key'] !== $secret_key) {
    http_response_code(403);
    die('Acceso no autorizado.');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Preferir Bearer (más fiable que user:token en la URL).
$repo_zip = "https://github.com/{$username}/{$repo}/archive/refs/heads/main.zip";
$zip_file = 'repo.zip';

echo '<h3>Iniciando actualización desde main…</h3>';
flush();

$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: InstitutoDoceo-Deploy\r\n"
            . "Authorization: Bearer {$token}\r\n"
            . "Accept: application/vnd.github+json\r\n",
        'ignore_errors' => true,
        'timeout' => 240,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
];

$context = stream_context_create($opts);
$file_data = @file_get_contents($repo_zip, false, $context);
$statusLine = is_array($http_response_header ?? null) ? (string) ($http_response_header[0] ?? '') : '';

if ($file_data === false || $file_data === '' || !preg_match('/\s(200|302)\s/', $statusLine)) {
    $hint = 'Revisa el Personal Access Token en upload_version.php '
        . '(Contents: Read / scope repo), el usuario/repo, y que el token no esté vencido.';
    die(
        '❌ Error: No se pudo descargar el repositorio desde GitHub. '
        . $hint
        . ($statusLine !== '' ? ' HTTP: ' . htmlspecialchars($statusLine) : '')
    );
}

file_put_contents($zip_file, $file_data);
echo '• Código descargado de GitHub (' . strlen($file_data) . ' bytes).<br>';
flush();

$zip = new ZipArchive;
if ($zip->open($zip_file) !== true) {
    die('Error al descomprimir el archivo ZIP.');
}
$zip->extractTo('./extracted');
$zip->close();
echo '• Archivos descomprimidos.<br>';
flush();

function smartCopy(string $source, string $dest): void
{
    if (is_dir($source)) {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        foreach (scandir($source) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                smartCopy("$source/$file", "$dest/$file");
            }
        }
        return;
    }

    if (!is_file($source)) {
        return;
    }

    $filename = basename($dest);
    if ($filename === 'upload_version.php' || $filename === '.env') {
        return;
    }

    if (file_exists($dest)) {
        @chmod($dest, 0777);
        @unlink($dest);
    }

    if (copy($source, $dest)) {
        chmod($dest, 0644);
    }
}

$source_folder = "./extracted/{$repo}-main/";
if (!is_dir($source_folder)) {
    die('No se encontró la carpeta extraída: ' . htmlspecialchars($source_folder));
}

foreach (scandir($source_folder) ?: [] as $item) {
    if ($item !== '.' && $item !== '..') {
        smartCopy($source_folder . $item, './' . $item);
    }
}
echo '• Archivos actualizados.<br>';
flush();

// Marca de versión para verificar en /admin/salud
@file_put_contents(
    __DIR__ . '/storage/DEPLOYED_AT.txt',
    date('c') . ' main zip deployed' . PHP_EOL
);

if (file_exists($zip_file)) {
    unlink($zip_file);
}

function rmDir_rf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = "$dir/$file";
        is_dir($path) ? rmDir_rf($path) : unlink($path);
    }
    rmdir($dir);
}
rmDir_rf('./extracted');

echo '<h2>Despliegue completado</h2>';
echo '<p>Recarga el admin (Ctrl+F5). En Salud debe decir fix tabla maestra: OK.</p>';
flush();

<?php
set_time_limit(180);
ini_set('memory_limit', '256M');

// CONFIGURACIÓN — copia este archivo como upload_version.php en el servidor
$username   = 'Towi04';
$repo       = 'Certificaciones';
$token      = 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$secret_key = 'tu-clave-secreta-de-deploy';

if (!isset($_GET['key']) || $_GET['key'] !== $secret_key) {
    http_response_code(403);
    die('Acceso no autorizado.');
}

$repo_zip = "https://{$username}:{$token}@github.com/{$username}/{$repo}/archive/refs/heads/main.zip";
$zip_file = 'repo.zip';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo '<h3>Iniciando actualización...</h3>';

$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: PHP\r\n",
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
];

$context = stream_context_create($opts);
$file_data = @file_get_contents($repo_zip, false, $context);

if ($file_data === false) {
    die('Error: no se pudo descargar el repositorio desde GitHub. Revisa token, nombre del repo y permisos Contents: Read.');
}

file_put_contents($zip_file, $file_data);
echo '• Código descargado de GitHub.<br>';

$zip = new ZipArchive;
if ($zip->open($zip_file) !== true) {
    die('Error al descomprimir el archivo ZIP.');
}
$zip->extractTo('./extracted');
$zip->close();
echo '• Archivos descomprimidos.<br>';

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

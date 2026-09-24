<?php
/**
 * Copia este archivo al servidor como upload_version.php (NO subir a Git).
 *
 * IMPORTANTE:
 * - El token va en $token aquí abajo, NO en el .env de la app.
 * - Los fine-grained PAT (github_pat_…) NO funcionan embebidos en la URL
 *   (user:token@github.com/…). Hay que usar Authorization: Bearer + API zipball.
 */
set_time_limit(300);
ini_set('memory_limit', '512M');
ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// CONFIGURACIÓN
$username   = 'Towi04';
$repo       = 'Certificaciones';
// Fine-grained: github_pat_…  |  Classic: ghp_…
// Permiso requerido: Contents → Read-only (sobre este repo).
$token      = 'github_pat_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$secret_key = 'tu-clave-secreta-de-deploy';

if (!isset($_GET['key']) || $_GET['key'] !== $secret_key) {
    http_response_code(403);
    die('Acceso no autorizado.');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$zip_file = 'repo.zip';
// API zipball: funciona con classic y fine-grained PAT (github_pat_…).
$repo_zip = "https://api.github.com/repos/{$username}/{$repo}/zipball/main";

echo '<h3>Iniciando actualización desde main…</h3>';
flush();

/**
 * Descarga el ZIP de main. Preferimos cURL: sigue el 302 a codeload.github.com
 * de forma fiable (file_get_contents a veces pierde el redirect).
 *
 * @return array{0:?string,1:int,2:string} [body, httpCode, error]
 */
$downloadRepoZip = static function (string $url, string $token): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_HTTPHEADER => [
                'User-Agent: InstitutoDoceo-Deploy',
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return [null, $code > 0 ? $code : 0, $err !== '' ? $err : 'curl_exec falló'];
        }

        return [$body, $code, $err];
    }

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: InstitutoDoceo-Deploy\r\n"
                . "Authorization: Bearer {$token}\r\n"
                . "Accept: application/vnd.github+json\r\n"
                . "X-GitHub-Api-Version: 2022-11-28\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
            'timeout' => 240,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];
    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);
    $headers = is_array($http_response_header ?? null) ? $http_response_header : [];
    $statusLine = (string) ($headers[0] ?? '');
    $code = 0;
    if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
        $code = (int) $m[1];
    }
    if ($body === false) {
        return [null, $code, 'file_get_contents falló'];
    }

    return [$body, $code, ''];
};

[$file_data, $httpCode, $downloadError] = $downloadRepoZip($repo_zip, $token);
$looksLikeZip = is_string($file_data)
    && strlen($file_data) > 100
    && str_starts_with($file_data, 'PK');

if ($file_data === null || $file_data === '' || $httpCode !== 200 || !$looksLikeZip) {
    $bodyPreview = is_string($file_data) ? substr(trim(strip_tags($file_data)), 0, 280) : '';
    die(
        '❌ Error: no se pudo descargar el repositorio desde GitHub. '
        . 'Revisa que $token esté en upload_version.php (no en .env), '
        . 'que sea un PAT con Contents: Read sobre ' . htmlspecialchars("{$username}/{$repo}") . ', '
        . 'y que el script use Authorization: Bearer + API zipball '
        . '(los fine-grained github_pat_… no funcionan en user:token@github.com).'
        . ' HTTP: ' . (int) $httpCode . '.'
        . ($downloadError !== '' ? ' Detalle: ' . htmlspecialchars($downloadError) . '.' : '')
        . ($bodyPreview !== '' ? ' Respuesta: ' . htmlspecialchars($bodyPreview) : '')
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

// GitHub puede nombrar la carpeta:
//   Certificaciones-main / Certificaciones-<sha>
//   Towi04-Certificaciones-<sha>  (zipball API)
$extractedRoot = './extracted';
$candidates = [];
$fallback = null;
foreach (scandir($extractedRoot) ?: [] as $item) {
    if ($item === '.' || $item === '..') {
        continue;
    }
    $path = $extractedRoot . '/' . $item;
    if (!is_dir($path)) {
        continue;
    }
    if ($fallback === null) {
        $fallback = $path . '/';
    }
    $prefixes = [
        $repo . '-',
        $username . '-' . $repo . '-',
    ];
    foreach ($prefixes as $prefix) {
        if (str_starts_with($item, $prefix)) {
            $candidates[] = $path . '/';
            break;
        }
    }
}
$source_folder = $candidates[0] ?? $fallback;
if ($source_folder === null || !is_dir($source_folder)) {
    $found = [];
    foreach (scandir($extractedRoot) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            $found[] = $item;
        }
    }
    die(
        'No se encontró la carpeta extraída del ZIP. '
        . 'Contenido de extracted/: '
        . htmlspecialchars($found === [] ? '(vacío)' : implode(', ', $found))
    );
}
echo '• Carpeta fuente: ' . htmlspecialchars(basename(rtrim($source_folder, '/'))) . '.<br>';
flush();

foreach (scandir($source_folder) ?: [] as $item) {
    if ($item !== '.' && $item !== '..') {
        smartCopy($source_folder . $item, './' . $item);
    }
}
echo '• Archivos actualizados.<br>';
flush();

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

<?php
set_time_limit(180);
ini_set('memory_limit', '256M');

// CONFIGURACIÓN
$username   = "Towi04";          
$repo       = "certificationes";    
$token      = "token";  
$secret_key = ""; 

if (!isset($_GET['key']) || $_GET['key'] !== $secret_key) {
    http_response_code(403);
    die("❌ Acceso no autorizado.");
}

$repo_zip = "https://{$username}:{$token}@github.com/{$username}/{$repo}/archive/refs/heads/main.zip";
$zip_file = "repo.zip";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

echo "<h3>Iniciando actualización...</h3>";

// 1. Descargar el ZIP sin cURL (Evita que el servidor se congele)
$opts = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: PHP\r\n"
    ],
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
    ]
];

$context = stream_context_create($opts);
$file_data = @file_get_contents($repo_zip, false, $context);

if ($file_data === FALSE) {
    die("❌ Error: No se pudo descargar el repositorio desde GitHub. Revisa el Token o Nombre de Repo.");
}

file_put_contents($zip_file, $file_data);
echo "• Código descargado de GitHub con éxito.<br>";

// 2. Descomprimir el ZIP
$zip = new ZipArchive;
if ($zip->open($zip_file) === TRUE) {
    $zip->extractTo('./extracted');
    $zip->close();
    echo "• Archivos descomprimidos temporalmente.<br>";
} else {
    die("❌ Error al descomprimir el archivo ZIP.");
}

// 3. Función para copiar recursivamente forzando el reemplazo total
function smartCopy($source, $dest) {
    if (is_dir($source)) {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        $files = scandir($source);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                smartCopy("$source/$file", "$dest/$file");
            }
        }
    } elseif (is_file($source)) {
        $filename = basename($dest);
        // Omitir el propio script de despliegue y archivos de entorno
        if ($filename === 'upload_version.php' || $filename === '.env') {
            return;
        }

        // Si el archivo existe en el servidor, forzamos su eliminación y permisos
        if (file_exists($dest)) {
            @chmod($dest, 0777); // Otorga permisos temporales de escritura
            @unlink($dest);       // Elimina el archivo viejo
        }

        // Copia el nuevo archivo e impone permisos adecuados
        if (copy($source, $dest)) {
            chmod($dest, 0644);
        }
    }
}

// 4. Copiado de archivos al subdominio
$source_folder = "./extracted/{$repo}-main/";
if (is_dir($source_folder)) {
    $items = scandir($source_folder);
    foreach ($items as $item) {
        if ($item != '.' && $item != '..') {
            smartCopy($source_folder . $item, './' . $item);
        }
    }
    echo "• ¡Archivos y carpetas actualizados con éxito!<br>";
} else {
    die("❌ No se encontró la carpeta extraída: " . $source_folder);
}

// 5. Limpieza de archivos temporales
if (file_exists($zip_file)) unlink($zip_file);

function rmDir_rf($dir) {
    if (!is_dir($dir)) return;
    foreach(scandir($dir) as $file) {
        if ('.' === $file || '..' === $file) continue;
        if (is_dir("$dir/$file")) rmDir_rf("$dir/$file");
        else unlink("$dir/$file");
    }
    rmdir($dir);
}
rmDir_rf('./extracted');

echo "<h2>🎉 ¡Despliegue completado sin bloqueos!</h2>";
?>
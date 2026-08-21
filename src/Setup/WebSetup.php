<?php

declare(strict_types=1);

namespace App\Setup;

use App\Config\Env;

final class WebSetup
{
    public static function runFromRequest(): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');

        // Mostrar errores en el instalador aunque APP_DEBUG=false
        ini_set('display_errors', '1');
        error_reporting(E_ALL);

        $installKey = trim((string) (Env::get('INSTALL_KEY', '') ?? ''));
        $provided = trim((string) ($_GET['key'] ?? ''));
        $diag = self::diagnostics($installKey, $provided);

        if ($installKey === '') {
            http_response_code(503);
            self::page('Falta INSTALL_KEY', '<h1 class="err">Falta INSTALL_KEY en el .env</h1>
                <p>En el archivo <code>.env</code> del servidor agrega una línea <strong>aparte</strong> de APP_KEY:</p>
                <pre>INSTALL_KEY=mi-clave-de-instalacion
APP_KEY=otra-clave-distinta</pre>
                <p>Luego abre:</p>
                <pre>/setup?key=mi-clave-de-instalacion</pre>
                <p>o</p>
                <pre>/setup.php?key=mi-clave-de-instalacion</pre>
                ' . $diag);
            return;
        }

        if ($provided === '') {
            http_response_code(400);
            self::page('Falta ?key=', '<h1 class="err">Falta el parámetro key</h1>
                <p>Ejemplo:</p>
                <pre>/setup?key=TU_INSTALL_KEY</pre>
                <p><strong>Importante:</strong> usa el valor de <code>INSTALL_KEY</code>, no el de <code>APP_KEY</code>.</p>
                ' . $diag);
            return;
        }

        if (!hash_equals($installKey, $provided)) {
            http_response_code(403);
            self::page('Clave incorrecta', '<h1 class="err">La key no coincide con INSTALL_KEY</h1>
                <p>Revisa que en la URL uses exactamente el valor de <code>INSTALL_KEY</code> del .env
                (sin espacios, sin comillas). <code>APP_KEY</code> es otra variable y no sirve aquí.</p>
                ' . $diag);
            return;
        }

        $withSeed = !isset($_GET['seed']) || (string) $_GET['seed'] !== '0';

        try {
            $log = (new Installer())->runAll($withSeed);
            $items = '';
            foreach ($log as $line) {
                $items .= '<li class="ok">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            self::page('Instalación OK', '<h1 class="ok">Instalación completada</h1>
                <ul>' . $items . '</ul>
                <p>Siguiente:</p>
                <ol>
                  <li>Entra a <a href="/login">/login</a> con ADMIN_EMAIL / ADMIN_PASSWORD</li>
                  <li>Revisa el catálogo en <a href="/">/</a></li>
                </ol>
                ' . $diag);
        } catch (\Throwable $e) {
            http_response_code(500);
            self::page('Error', '<h1 class="err">Error al instalar</h1>
                <p><code>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</code></p>
                <p>Archivo: <code>' . htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</code></p>
                <p>Revisa <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> en el .env
                y que la base exista en cPanel → MySQL.</p>
                ' . $diag);
        }
    }

    private static function diagnostics(string $installKey, string $provided): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : '(no BASE_PATH)';
        $envFile = $base . '/.env';
        $schema = $base . '/sql/schema.sql';
        $rows = [
            'BASE_PATH' => $base,
            '.env legible' => is_readable($envFile) ? 'sí' : 'NO',
            'sql/schema.sql' => is_readable($schema) ? 'sí' : 'NO',
            'INSTALL_KEY definido' => $installKey !== '' ? 'sí (len=' . strlen($installKey) . ')' : 'NO',
            'key en URL' => $provided !== '' ? 'sí (len=' . strlen($provided) . ')' : 'vacío',
            'APP_KEY definido' => Env::isFilled('APP_KEY') ? 'sí (no uses este valor como key)' : 'no',
            'DB_NAME' => Env::get('DB_NAME', '(vacío)') ?? '(vacío)',
            'script' => $_SERVER['SCRIPT_NAME'] ?? '',
            'request' => $_SERVER['REQUEST_URI'] ?? '',
        ];
        $html = '<hr><h2 style="font-size:1rem;color:#315285">Diagnóstico</h2><ul>';
        foreach ($rows as $k => $v) {
            $html .= '<li><strong>' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . ':</strong> '
                . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    public static function page(string $title, string $bodyHtml): void
    {
        $name = htmlspecialchars(function_exists('app_name') ? app_name() : 'Instituto DOCEO', ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>
          body{font-family:Segoe UI,system-ui,sans-serif;background:#f4f7fb;color:#1a2a40;margin:0;padding:2rem}
          .card{max-width:820px;margin:0 auto;background:#fff;border:1px solid #C4C4C4;border-radius:14px;padding:1.25rem 1.4rem}
          h1{color:#315285;margin:0 0 .75rem;font-size:1.35rem}
          .ok{color:#176b3a}.err{color:#8a1f1f}
          ul{line-height:1.55} code,pre{background:#eef3f9;padding:.15rem .4rem;border-radius:4px}
          pre{padding:.75rem;overflow:auto} a{color:#315285} hr{border:0;border-top:1px solid #e6ebf2;margin:1.25rem 0}
        </style></head><body><div class="card">';
        echo '<p style="margin:0 0 1rem"><strong>' . $name . '</strong> · instalador web</p>';
        echo $bodyHtml;
        echo '</div></body></html>';
    }
}

<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Config\Env;

define('BASE_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = BASE_PATH . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$envPath = BASE_PATH . '/.env';
if (!is_file($envPath) && is_file(BASE_PATH . '/env.example')) {
    // permitir boot de lectura sin .env solo en CLI install
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('No se encontró .env. Copia env.example a .env.');
    }
} else {
    Env::load($envPath);
}

$timezone = Env::get('APP_TIMEZONE', 'America/Mexico_City') ?? 'America/Mexico_City';
date_default_timezone_set($timezone);

$debug = Env::getBool('APP_DEBUG', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logDir = BASE_PATH . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php-error.log');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[Doceo] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<h1>Error del servidor</h1>';
    if ($debug) {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<p>Revisa <code>storage/logs/php-error.log</code> o activa <code>APP_DEBUG=true</code> en el .env.</p>';
        echo '<p><small>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</small></p>';
    }
});

Auth::startSession();

function e(mixed $value): string
{
    if ($value === null || $value === false) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_name(): string
{
    return Env::get('APP_NAME', 'Instituto DOCEO') ?? 'Instituto DOCEO';
}

function asset(string $path): string
{
    $path = '/' . ltrim($path, '/');

    // Prefijo opcional: ASSET_BASE=/public  (Neubox con docroot en la raíz)
    $configured = rtrim((string) (\App\Config\Env::get('ASSET_BASE', '') ?? ''), '/');
    if ($configured !== '') {
        return $configured . $path;
    }

    // Auto: si el archivo solo existe bajo public/, exponerlo como /public/...
    // (docroot = raíz del subdominio). Si docroot = /public, el archivo también
    // existe como BASE_PATH/public/... pero la URL correcta sigue siendo /assets/...
    // Detectamos docroot-raíz cuando hay index.php en la raíz del repo.
    $publicFile = BASE_PATH . '/public' . $path;
    $rootFile = BASE_PATH . $path;
    $rootFrontController = is_file(BASE_PATH . '/index.php') && is_file(BASE_PATH . '/public/index.php');
    if ($rootFrontController && is_file($publicFile) && !is_file($rootFile)) {
        $url = '/public' . $path;
    } else {
        $url = $path;
    }

    // Cache-bust si el archivo existe en disco
    $disk = is_file($publicFile) ? $publicFile : (is_file($rootFile) ? $rootFile : null);
    if ($disk !== null) {
        $url .= '?v=' . filemtime($disk);
    }

    return $url;
}

function url(string $path = '/'): string
{
    $base = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
    $path = '/' . ltrim($path, '/');
    if ($path === '/') {
        return $base !== '' ? $base . '/' : '/';
    }

    return ($base !== '' ? $base : '') . $path;
}

function redirect(string $path): never
{
    if (!str_starts_with($path, 'http')) {
        $path = url($path);
    }
    header('Location: ' . $path);
    exit;
}

function flash(string $key, mixed $value = null): mixed
{
    if (func_num_args() >= 2) {
        $_SESSION['_flash'][$key] = $value;

        return null;
    }
    $msg = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);

    return $msg;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = (string) ($_POST['_csrf'] ?? '');
    if ($token === '' || empty($_SESSION['_csrf']) || !hash_equals((string) $_SESSION['_csrf'], $token)) {
        http_response_code(419);
        exit('Token CSRF inválido.');
    }
}

function money(float|int|string|null $amount): string
{
    return '$' . number_format((float) $amount, 2, '.', ',');
}

function view(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $viewFile = BASE_PATH . '/views/' . $name . '.php';
    if (!is_file($viewFile)) {
        throw new RuntimeException("Vista no encontrada: {$name}");
    }
    $layout = (string) ($layout ?? 'main');
    $contentFile = $viewFile;
    require BASE_PATH . '/views/layouts/' . $layout . '.php';
}

function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'item';
}

function category_label(string $cat): string
{
    return match ($cat) {
        'it' => 'Informática',
        'english_adult' => 'Inglés (adultos)',
        'english_kids' => 'Inglés (menores)',
        'teaching' => 'Enseñanza',
        default => 'Otros',
    };
}

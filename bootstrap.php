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

/**
 * Iconos SVG monocromáticos (usan currentColor).
 */
function icon(string $name): string
{
    $svg = match ($name) {
        'edit' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        'trash' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6v14H5V6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>',
        'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>',
        'table' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18v14H3z"/><path d="M3 10h18"/><path d="M3 15h18"/><path d="M9 5v14"/></svg>',
        'payments' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
        'products' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/></svg>',
        'filters' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 3H2l8 9.5V19l4 2v-8.5Z"/></svg>',
        'combos' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12.8 2.2 8 4.4v10.8l-8 4.4-8-4.4V6.6l8-4.4Z"/><path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/></svg>',
        'prices' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4A9 9 0 1 1 10.6 3.4"/><path d="M22 2 12 12"/><path d="M16 2h6v6"/></svg>',
        'groups' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.5L10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z"/></svg>',
        'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>',
        'promo' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'mail' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 7L2 7"/></svg>',
        'partners' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'suppliers' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h13v10H3z"/><path d="M16 10h4l1 3v4h-5"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="18.5" cy="17.5" r="1.5"/></svg>',
        'certifiers' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M8.2 13 7 22l5-3 5 3-1.2-9"/></svg>',
        'export' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
        'health' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        'catalog' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7v13a2 2 0 0 0 2 2h14"/><path d="M5 3h14a2 2 0 0 1 2 2v14"/><path d="M8 7h8"/><path d="M8 11h8"/><path d="M8 15h5"/></svg>',
        'logout' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>',
        'menu' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></svg>',
        'panel' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></svg>',
        default => '',
    };

    return $svg;
}

/**
 * Renderiza texto de producto escrito desde el admin.
 * Si trae HTML (p, strong, ul…), lo muestra formateado; si es texto plano, escapa y respeta saltos de línea.
 */
function rich_text(mixed $value): string
{
    $html = trim((string) ($value ?? ''));
    if ($html === '') {
        return '';
    }

    // Texto plano: escapar y conservar saltos de línea.
    if (!preg_match('/<[a-z][\s\S]*>/i', $html)) {
        return nl2br(e($html), false);
    }

    // HTML del admin: quitar vectores obvios, conservar formato.
    $html = preg_replace('#<(script|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|iframe|object|embed|form)\b[^>]*/?>#i', '', $html) ?? $html;
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
    $html = preg_replace('#javascript\s*:#i', '', $html) ?? $html;

    return $html;
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

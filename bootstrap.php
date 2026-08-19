<?php

declare(strict_types=1);

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

Env::load(BASE_PATH . '/.env');

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
    }
});

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
    $base = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
    $path = '/' . ltrim($path, '/');

    return ($base !== '' ? $base : '') . $path;
}

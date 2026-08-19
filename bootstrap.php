<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Config\Env;

define('BASE_PATH', dirname(__DIR__));

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
    error_log('[PDV] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

function view(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $viewFile = BASE_PATH . '/views/' . $name . '.php';
    if (!is_file($viewFile)) {
        throw new RuntimeException("Vista no encontrada: {$name}");
    }
    $layout = (string) ($layout ?? 'default');
    if ($layout === 'bare' || $layout === 'print') {
        require BASE_PATH . '/views/layout_bare.php';
        return;
    }
    require BASE_PATH . '/views/layout.php';
}

function e(mixed $value): string
{
    if ($value === null || $value === false) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Solo dígitos (para tel: y wa.me). */
function phone_digits(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

/** Enlace WhatsApp (wa.me). Prefija 52 si son 10 dígitos (MX). */
function wa_me_url(?string $whatsapp): ?string
{
    $digits = phone_digits($whatsapp);
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) === 10) {
        $digits = '52' . $digits;
    }
    return 'https://wa.me/' . $digits;
}

function tel_url(?string $phone): ?string
{
    $digits = phone_digits($phone);
    return $digits !== '' ? 'tel:+' . $digits : null;
}

function e_json(mixed $value): string
{
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($value, $flags);

    return e($json === false ? '{"error":"json_encode failed"}' : $json);
}

function app_name(): string
{
    return Env::get('APP_NAME', 'Instituto Doceo') ?? 'Instituto Doceo';
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

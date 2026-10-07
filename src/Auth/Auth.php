<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config\Env;
use App\Database\Connection;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionPath = BASE_PATH . '/storage/sessions';
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0750, true);
        }
        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            session_save_path($sessionPath);
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        session_name('doceo_sess');
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => $https,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }

    public static function attempt(string $email, string $password): bool
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = (string) $user['role'];
        $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name_p'] ?? ''));
        // Solo tras login con contraseña se exige el cambio de temporal.
        // El auto-login post-compra no debe bloquear la navegación.
        $_SESSION['force_password_change'] = !empty($user['must_change_password']);
        unset($_SESSION['must_change_password']);

        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function role(): ?string
    {
        return isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : null;
    }

    /**
     * ¿Esta sesión debe obligar el cambio de contraseña temporal?
     * Solo true tras Auth::attempt() con must_change_password en BD.
     * No se activa por auto-login de checkout (loginAs).
     */
    public static function mustChangePassword(): bool
    {
        return !empty($_SESSION['force_password_change']);
    }

    public static function clearMustChangeFlag(): void
    {
        $_SESSION['force_password_change'] = false;
        unset($_SESSION['must_change_password']);
    }

    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            flash('error', 'Inicia sesión para continuar.');
            redirect('/login');
        }
        self::enforcePasswordChange();
    }

    /** @param list<string> $roles */
    public static function requireRole(array $roles): void
    {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            http_response_code(403);
            view('errors/403', ['title' => 'Acceso denegado']);
            exit;
        }
    }

    /**
     * Obliga a cambiar la contraseña temporal tras el primer login con ella.
     * No aplica al auto-login inmediato después de crear la cuenta en checkout.
     */
    public static function enforcePasswordChange(): void
    {
        if (!self::check() || !self::mustChangePassword()) {
            return;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH) ?: '';
        $allowed = [
            '/cuenta/cambiar-contrasena',
            '/logout',
        ];
        foreach ($allowed as $prefix) {
            if ($path === $prefix || str_starts_with((string) $path, $prefix . '?')) {
                return;
            }
        }
        // Comparar también con base path si la app vive en subcarpeta.
        if (str_contains((string) $path, '/cuenta/cambiar-contrasena')
            || str_ends_with((string) $path, '/logout')) {
            return;
        }
        flash('error', 'Debes cambiar tu contraseña temporal antes de continuar.');
        redirect('/cuenta/cambiar-contrasena');
    }

    public static function ensureAdminFromEnv(): void
    {
        $email = strtolower(trim((string) (Env::get('ADMIN_EMAIL', '') ?? '')));
        $password = (string) (Env::get('ADMIN_PASSWORD', '') ?? '');
        if ($email === '' || $password === '') {
            return;
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR role = ? LIMIT 1');
        $stmt->execute([$email, 'admin']);
        $existing = $stmt->fetch();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $name = (string) (Env::get('ADMIN_USERNAME', 'Admin') ?? 'Admin');

        if ($existing) {
            if (Env::getBool('ADMIN_RESET_PASSWORD', false)) {
                $pdo->prepare('UPDATE users SET email = ?, password_hash = ?, first_name = ?, role = ? WHERE id = ?')
                    ->execute([$email, $hash, $name, 'admin', (int) $existing['id']]);
            }

            return;
        }

        $pdo->prepare(
            'INSERT INTO users (role, email, password_hash, first_name, must_change_password, is_active)
             VALUES (?, ?, ?, ?, 0, 1)'
        )->execute(['admin', $email, $hash, $name]);
    }
}

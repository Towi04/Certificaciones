<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Connection;
use App\Integrations\Mailer;
use PDO;

/**
 * Recuperación de contraseña por enlace de un solo uso (tabla password_resets).
 */
final class PasswordResetService
{
    private const TTL_SECONDS = 3600;
    private const MIN_PASSWORD_LEN = 8;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->ensureTable();
    }

    /**
     * Siempre responde igual (no revela si el correo existe).
     */
    public function requestReset(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, first_name, is_active FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !(int) ($user['is_active'] ?? 0)) {
            return;
        }

        // Invalida tokens previos del usuario.
        $this->pdo->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        )->execute([(int) $user['id']]);

        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        )->execute([(int) $user['id'], $hash, $expires]);

        $link = url('/recuperar/' . $rawToken);
        if (!preg_match('#^https?://#i', $link)) {
            $base = rtrim((string) (Env::get('APP_URL', '') ?? ''), '/');
            $link = ($base !== '' ? $base : '') . '/recuperar/' . $rawToken;
        }

        $name = trim((string) ($user['first_name'] ?? '')) ?: 'Usuario';
        $minutes = (int) (self::TTL_SECONDS / 60);
        $text = "Hola {$name},\n\n"
            . "Recibimos una solicitud para restablecer tu contraseña en " . app_name() . ".\n\n"
            . "Abre este enlace (válido {$minutes} minutos):\n{$link}\n\n"
            . "Si no fuiste tú, ignora este correo. Tu contraseña no cambiará.\n";

        $html = '<p>Hola ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Recibimos una solicitud para restablecer tu contraseña en '
            . htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Restablecer contraseña</a></p>'
            . '<p class="muted">El enlace caduca en ' . $minutes . ' minutos. '
            . 'Si no solicitaste este cambio, ignora el correo.</p>';

        try {
            (new Mailer())->send($email, 'Restablecer contraseña — ' . app_name(), $text, [
                'html' => true,
                'body_html' => $html,
            ]);
        } catch (\Throwable $e) {
            error_log('[Doceo] Password reset mail failed: ' . $e->getMessage());
            // No revelamos el fallo al usuario (mismo mensaje genérico).
        }
    }

    /** @return array{user_id:int,email:string}|null */
    public function peekValidToken(string $rawToken): ?array
    {
        $row = $this->findValidReset($rawToken);
        if ($row === null) {
            return null;
        }

        return [
            'user_id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
        ];
    }

    public function resetPassword(string $rawToken, string $password, string $passwordConfirm): void
    {
        $password = (string) $password;
        if (strlen($password) < self::MIN_PASSWORD_LEN) {
            throw new \InvalidArgumentException(
                'La contraseña debe tener al menos ' . self::MIN_PASSWORD_LEN . ' caracteres.'
            );
        }
        if ($password !== $passwordConfirm) {
            throw new \InvalidArgumentException('Las contraseñas no coinciden.');
        }
        if (preg_match('/\s/', $password)) {
            throw new \InvalidArgumentException('La contraseña no debe contener espacios.');
        }

        $row = $this->findValidReset($rawToken);
        if ($row === null) {
            throw new \InvalidArgumentException('El enlace no es válido o ya expiró. Solicita uno nuevo.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
            )->execute([$hash, (int) $row['user_id']]);
            $this->pdo->prepare(
                'UPDATE password_resets SET used_at = NOW() WHERE id = ?'
            )->execute([(int) $row['id']]);
            // Invalida otros tokens del mismo usuario.
            $this->pdo->prepare(
                'UPDATE password_resets SET used_at = NOW()
                 WHERE user_id = ? AND used_at IS NULL AND id != ?'
            )->execute([(int) $row['user_id'], (int) $row['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function changePasswordForUser(int $userId, string $current, string $new, string $confirm): void
    {
        if (strlen($new) < self::MIN_PASSWORD_LEN) {
            throw new \InvalidArgumentException(
                'La contraseña debe tener al menos ' . self::MIN_PASSWORD_LEN . ' caracteres.'
            );
        }
        if ($new !== $confirm) {
            throw new \InvalidArgumentException('Las contraseñas no coinciden.');
        }
        if (preg_match('/\s/', $new)) {
            throw new \InvalidArgumentException('La contraseña no debe contener espacios.');
        }

        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new \InvalidArgumentException('Usuario no encontrado.');
        }
        if (!password_verify($current, (string) $user['password_hash'])) {
            throw new \InvalidArgumentException('La contraseña actual no es correcta.');
        }

        $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
        )->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
    }

    /** @return array<string, mixed>|null */
    private function findValidReset(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.user_id, r.expires_at, r.used_at, u.email, u.is_active
             FROM password_resets r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row || $row['used_at'] !== null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        if (!(int) ($row['is_active'] ?? 0)) {
            return null;
        }

        return $row;
    }

    private function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS password_resets (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    token_hash VARCHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_reset_token (token_hash),
                    KEY idx_reset_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable) {
            // ignore
        }
    }
}

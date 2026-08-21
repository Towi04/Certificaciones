<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Database\Connection;
use App\Support\Settings;
use PDO;

final class StudentAccountService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /**
     * @param array{
     *   email: string,
     *   first_name: string,
     *   last_name_p: string,
     *   last_name_m?: string,
     *   phone?: string,
     *   password?: string,
     *   curp?: string,
     *   birth_date?: string,
     *   sex?: string,
     *   nationality?: string
     * } $data
     * @return array{user: array<string,mixed>, created: bool, plain_password: ?string}
     */
    public function findOrCreate(array $data): array
    {
        $email = strtolower(trim($data['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido.');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ((string) $existing['role'] !== 'student') {
                throw new \InvalidArgumentException('Ese correo ya pertenece a una cuenta que no es de alumno. Inicia sesión o usa otro correo.');
            }
            $this->ensureStudentProfile((int) $existing['id'], $data);
            // Actualiza datos de contacto si vienen vacíos
            $this->pdo->prepare(
                'UPDATE users SET first_name = ?, last_name_p = ?, last_name_m = ?, phone = ? WHERE id = ?'
            )->execute([
                $data['first_name'] !== '' ? $data['first_name'] : $existing['first_name'],
                $data['last_name_p'] !== '' ? $data['last_name_p'] : $existing['last_name_p'],
                $data['last_name_m'] ?? $existing['last_name_m'],
                $data['phone'] ?? $existing['phone'],
                (int) $existing['id'],
            ]);
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            return ['user' => $user, 'created' => false, 'plain_password' => null];
        }

        $plain = trim((string) ($data['password'] ?? ''));
        if ($plain === '') {
            $plain = Settings::defaultStudentPassword();
        }
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        $this->pdo->prepare(
            'INSERT INTO users (role, email, password_hash, first_name, last_name_p, last_name_m, phone, must_change_password, is_active)
             VALUES (?,?,?,?,?,?,?,1,1)'
        )->execute([
            'student',
            $email,
            $hash,
            trim($data['first_name']),
            trim($data['last_name_p']),
            trim((string) ($data['last_name_m'] ?? '')),
            trim((string) ($data['phone'] ?? '')) ?: null,
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->ensureStudentProfile($userId, $data);

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return ['user' => $stmt->fetch(), 'created' => true, 'plain_password' => $plain];
    }

    /** @param array<string, mixed> $data */
    private function ensureStudentProfile(int $userId, array $data): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM students WHERE user_id = ?');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            $this->pdo->prepare(
                'UPDATE students SET curp = COALESCE(?, curp), birth_date = COALESCE(?, birth_date),
                 sex = COALESCE(?, sex), nationality = COALESCE(?, nationality) WHERE user_id = ?'
            )->execute([
                ($data['curp'] ?? '') !== '' ? $data['curp'] : null,
                ($data['birth_date'] ?? '') !== '' ? $data['birth_date'] : null,
                ($data['sex'] ?? '') !== '' ? $data['sex'] : null,
                ($data['nationality'] ?? '') !== '' ? $data['nationality'] : null,
                $userId,
            ]);

            return;
        }

        $this->pdo->prepare(
            'INSERT INTO students (user_id, curp, birth_date, sex, nationality) VALUES (?,?,?,?,?)'
        )->execute([
            $userId,
            ($data['curp'] ?? '') !== '' ? $data['curp'] : null,
            ($data['birth_date'] ?? '') !== '' ? $data['birth_date'] : null,
            ($data['sex'] ?? '') !== '' ? $data['sex'] : null,
            ($data['nationality'] ?? '') !== '' ? $data['nationality'] : 'México',
        ]);
    }

    public function loginAs(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = (string) $user['role'];
        $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name_p'] ?? ''));
    }
}

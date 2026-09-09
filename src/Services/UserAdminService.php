<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Repositories\UserRepository;
use App\Support\Settings;

/** Alta y edición de usuarios admin desde el panel. */
final class UserAdminService
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    /**
     * @param array<string, mixed> $data
     * @return array{user_id:int,plain_password:string}
     */
    public function createAdmin(array $data): array
    {
        $payload = $this->validateProfile($data, null);
        $plain = $this->resolvePassword((string) ($data['password'] ?? ''), true);

        $userId = $this->users->createAdmin([
            'email' => $payload['email'],
            'password_hash' => password_hash($plain, PASSWORD_DEFAULT),
            'first_name' => $payload['first_name'],
            'last_name_p' => $payload['last_name_p'],
            'last_name_m' => $payload['last_name_m'],
            'phone' => $payload['phone'],
            'must_change_password' => $payload['must_change_password'],
            'is_active' => $payload['is_active'],
        ]);

        return [
            'user_id' => $userId,
            'plain_password' => $plain,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{plain_password:?string}
     */
    public function updateAdmin(int $userId, array $data): array
    {
        $existing = $this->users->findAdmin($userId);
        if ($existing === null) {
            throw new \InvalidArgumentException('Usuario admin no encontrado.');
        }

        $payload = $this->validateProfile($data, $userId);
        $this->assertCanChangeActive($userId, $payload['is_active'] === 1);

        $update = [
            'email' => $payload['email'],
            'first_name' => $payload['first_name'],
            'last_name_p' => $payload['last_name_p'],
            'last_name_m' => $payload['last_name_m'],
            'phone' => $payload['phone'],
            'must_change_password' => $payload['must_change_password'],
            'is_active' => $payload['is_active'],
        ];

        $plain = null;
        $passwordRaw = trim((string) ($data['password'] ?? ''));
        if ($passwordRaw !== '') {
            $plain = $this->resolvePassword($passwordRaw, false);
            $update['password_hash'] = password_hash($plain, PASSWORD_DEFAULT);
        }

        $this->users->update($userId, $update);

        return ['plain_password' => $plain];
    }

    /**
     * @return array{plain_password:string}
     */
    public function resetPassword(int $userId, ?string $password = null): array
    {
        $existing = $this->users->findAdmin($userId);
        if ($existing === null) {
            throw new \InvalidArgumentException('Usuario admin no encontrado.');
        }
        if (!(int) ($existing['is_active'] ?? 0)) {
            throw new \InvalidArgumentException('No se puede restablecer la contraseña de un usuario inactivo.');
        }

        $plain = $this->resolvePassword(trim((string) ($password ?? '')), true);
        $this->users->update($userId, [
            'password_hash' => password_hash($plain, PASSWORD_DEFAULT),
            'must_change_password' => 1,
        ]);

        return ['plain_password' => $plain];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   email:string,first_name:string,last_name_p:string,last_name_m:?string,
     *   phone:?string,must_change_password:int,is_active:int
     * }
     */
    private function validateProfile(array $data, ?int $exceptId): array
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $first = trim((string) ($data['first_name'] ?? ''));
        $lastP = trim((string) ($data['last_name_p'] ?? ''));
        $lastM = trim((string) ($data['last_name_m'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $active = !empty($data['is_active']) ? 1 : 0;
        $mustChange = !empty($data['must_change_password']) ? 1 : 0;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido.');
        }
        if ($first === '' || $lastP === '') {
            throw new \InvalidArgumentException('Nombre y apellido paterno son obligatorios.');
        }
        if ($this->users->emailExists($email, $exceptId)) {
            throw new \InvalidArgumentException('Ese correo ya está registrado.');
        }

        return [
            'email' => $email,
            'first_name' => $first,
            'last_name_p' => $lastP,
            'last_name_m' => $lastM !== '' ? $lastM : null,
            'phone' => $phone !== '' ? $phone : null,
            'must_change_password' => $mustChange,
            'is_active' => $active,
        ];
    }

    private function resolvePassword(string $plain, bool $allowDefault): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            if (!$allowDefault) {
                throw new \InvalidArgumentException('La contraseña no puede estar vacía.');
            }
            $plain = Settings::defaultStudentPassword();
        }
        if (strlen($plain) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        return $plain;
    }

    private function assertCanChangeActive(int $userId, bool $willBeActive): void
    {
        if ($willBeActive) {
            return;
        }

        $currentId = Auth::id();
        if ($currentId !== null && $currentId === $userId) {
            throw new \InvalidArgumentException('No puedes desactivar tu propia cuenta.');
        }

        if ($this->users->countActiveAdmins($userId) < 1) {
            throw new \InvalidArgumentException('Debe quedar al menos un administrador activo.');
        }
    }
}

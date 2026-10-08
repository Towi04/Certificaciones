<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\PartnerRepository;
use PDO;

/**
 * Autogestión del perfil partner (nombre comercial, contacto, código, contraseña).
 */
final class PartnerProfileService
{
    private PDO $pdo;
    private PartnerRepository $partners;
    private PartnerAdminService $admin;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->partners = new PartnerRepository();
        $this->admin = new PartnerAdminService();
    }

    /**
     * @return array<string, mixed>
     */
    public function profileForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, u.email, u.first_name, u.last_name_p, u.last_name_m, u.phone,
                    u.last_login_at
             FROM partners p
             JOIN users u ON u.id = p.user_id
             WHERE p.user_id = ? AND p.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('No hay ficha de partner activa para este usuario.');
        }

        return $row;
    }

    /**
     * @param array{
     *   display_name:string,
     *   first_name:string,
     *   phone?:string,
     *   code:string,
     *   current_password?:string,
     *   password?:string,
     *   password_confirmation?:string
     * } $data
     */
    public function update(int $userId, array $data): void
    {
        $partner = $this->profileForUser($userId);
        $partnerId = (int) $partner['id'];

        $display = trim((string) ($data['display_name'] ?? ''));
        $first = trim((string) ($data['first_name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if ($display === '') {
            throw new \InvalidArgumentException('El nombre comercial es obligatorio.');
        }
        if ($first === '') {
            throw new \InvalidArgumentException('El nombre de contacto es obligatorio.');
        }
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            throw new \InvalidArgumentException('Código inválido (2–40 caracteres: A-Z, 0-9, _ o -).');
        }
        if ($this->partners->codeExists($code, $partnerId)) {
            throw new \InvalidArgumentException('Ese código ya lo tiene otro partner.');
        }

        $newPassword = trim((string) ($data['password'] ?? ''));
        $confirm = trim((string) ($data['password_confirmation'] ?? ''));
        $current = (string) ($data['current_password'] ?? '');
        if ($newPassword !== '' || $confirm !== '') {
            if ($newPassword !== $confirm) {
                throw new \InvalidArgumentException('La confirmación de contraseña no coincide.');
            }
            (new PasswordResetService())->changePasswordForUser(
                $userId,
                $current,
                $newPassword,
                $confirm
            );
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE users SET first_name = ?, phone = ? WHERE id = ?'
            )->execute([
                $first,
                $phone !== '' ? $phone : null,
                $userId,
            ]);
            $this->pdo->prepare(
                'UPDATE partners SET display_name = ?, code = ? WHERE id = ?'
            )->execute([$display, $code, $partnerId]);
            $this->admin->syncPartnerPromoCode($partnerId, $code, true);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

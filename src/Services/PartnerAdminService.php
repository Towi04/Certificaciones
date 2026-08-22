<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\PartnerRepository;
use App\Support\Settings;
use PDO;

/**
 * Alta y edición de partners (usuario + ficha + nivel de precio).
 */
final class PartnerAdminService
{
    public const TIERS = ['cncm', 'a', 'b', 'c'];

    private PDO $pdo;
    private PartnerRepository $partners;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->partners = new PartnerRepository();
    }

    /** @return array<string, string> */
    public static function tierLabels(): array
    {
        return [
            'cncm' => 'CNCM',
            'a' => 'Nivel A',
            'b' => 'Nivel B',
            'c' => 'Nivel C',
        ];
    }

    /**
     * @param array{
     *   email:string,password?:string,first_name:string,last_name_p:string,last_name_m?:string,phone?:string,
     *   code:string,display_name:string,tier:string,notes?:string,is_active?:bool|int|string,
     *   must_change_password?:bool|int|string
     * } $data
     * @return array{partner_id:int,user_id:int,plain_password:string}
     */
    public function create(array $data): array
    {
        $email = strtolower(trim($data['email'] ?? ''));
        $first = trim($data['first_name'] ?? '');
        $lastP = trim($data['last_name_p'] ?? '');
        $lastM = trim($data['last_name_m'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $code = strtoupper(trim($data['code'] ?? ''));
        $display = trim($data['display_name'] ?? '');
        $tier = strtolower(trim($data['tier'] ?? 'c'));
        $notes = trim($data['notes'] ?? '');
        $active = !empty($data['is_active']);
        $mustChange = array_key_exists('must_change_password', $data)
            ? !empty($data['must_change_password'])
            : true;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido.');
        }
        if ($first === '' || $lastP === '') {
            throw new \InvalidArgumentException('Nombre y apellido paterno son obligatorios.');
        }
        if ($display === '') {
            throw new \InvalidArgumentException('El nombre comercial del partner es obligatorio.');
        }
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            throw new \InvalidArgumentException('Código inválido (2–40 caracteres: A-Z, 0-9, _ o -).');
        }
        if (!in_array($tier, self::TIERS, true)) {
            throw new \InvalidArgumentException('Nivel de partner no válido.');
        }
        if ($this->partners->codeExists($code)) {
            throw new \InvalidArgumentException('Ese código de partner ya existe.');
        }

        $stmt = $this->pdo->prepare('SELECT id, role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Ese correo ya está registrado. Usa otro o edita el partner existente.');
        }

        $plain = trim((string) ($data['password'] ?? ''));
        if ($plain === '') {
            $plain = Settings::defaultStudentPassword();
        }
        if (strlen($plain) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO users (role, email, password_hash, first_name, last_name_p, last_name_m, phone, must_change_password, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                'partner',
                $email,
                password_hash($plain, PASSWORD_DEFAULT),
                $first,
                $lastP,
                $lastM,
                $phone !== '' ? $phone : null,
                $mustChange ? 1 : 0,
                $active ? 1 : 0,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO partners (user_id, code, display_name, tier, notes, is_active)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $userId,
                $code,
                $display,
                $tier,
                $notes !== '' ? $notes : null,
                $active ? 1 : 0,
            ]);
            $partnerId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'partner_id' => $partnerId,
            'user_id' => $userId,
            'plain_password' => $plain,
        ];
    }

    /**
     * @param array{
     *   email:string,password?:string,first_name:string,last_name_p:string,last_name_m?:string,phone?:string,
     *   code:string,display_name:string,tier:string,notes?:string,is_active?:bool|int|string,
     *   must_change_password?:bool|int|string
     * } $data
     * @return array{plain_password:?string}
     */
    public function update(int $partnerId, array $data): array
    {
        $partner = $this->partners->find($partnerId);
        if ($partner === null) {
            throw new \InvalidArgumentException('Partner no encontrado.');
        }

        $email = strtolower(trim($data['email'] ?? ''));
        $first = trim($data['first_name'] ?? '');
        $lastP = trim($data['last_name_p'] ?? '');
        $lastM = trim($data['last_name_m'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $code = strtoupper(trim($data['code'] ?? ''));
        $display = trim($data['display_name'] ?? '');
        $tier = strtolower(trim($data['tier'] ?? 'c'));
        $notes = trim($data['notes'] ?? '');
        $active = !empty($data['is_active']);
        $mustChange = !empty($data['must_change_password']);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido.');
        }
        if ($first === '' || $lastP === '') {
            throw new \InvalidArgumentException('Nombre y apellido paterno son obligatorios.');
        }
        if ($display === '') {
            throw new \InvalidArgumentException('El nombre comercial del partner es obligatorio.');
        }
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            throw new \InvalidArgumentException('Código inválido (2–40 caracteres: A-Z, 0-9, _ o -).');
        }
        if (!in_array($tier, self::TIERS, true)) {
            throw new \InvalidArgumentException('Nivel de partner no válido.');
        }
        if ($this->partners->codeExists($code, $partnerId)) {
            throw new \InvalidArgumentException('Ese código de partner ya existe.');
        }

        $stmt = $this->pdo->prepare('SELECT id, role FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $stmt->execute([$email, (int) $partner['user_id']]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Ese correo ya pertenece a otra cuenta.');
        }

        $plain = trim((string) ($data['password'] ?? ''));
        if ($plain !== '' && strlen($plain) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($plain !== '') {
                $this->pdo->prepare(
                    'UPDATE users
                     SET email = ?, password_hash = ?, first_name = ?, last_name_p = ?, last_name_m = ?,
                         phone = ?, must_change_password = ?, is_active = ?
                     WHERE id = ?'
                )->execute([
                    $email,
                    password_hash($plain, PASSWORD_DEFAULT),
                    $first,
                    $lastP,
                    $lastM,
                    $phone !== '' ? $phone : null,
                    $mustChange ? 1 : 0,
                    $active ? 1 : 0,
                    (int) $partner['user_id'],
                ]);
            } else {
                $this->pdo->prepare(
                    'UPDATE users
                     SET email = ?, first_name = ?, last_name_p = ?, last_name_m = ?,
                         phone = ?, must_change_password = ?, is_active = ?
                     WHERE id = ?'
                )->execute([
                    $email,
                    $first,
                    $lastP,
                    $lastM,
                    $phone !== '' ? $phone : null,
                    $mustChange ? 1 : 0,
                    $active ? 1 : 0,
                    (int) $partner['user_id'],
                ]);
            }

            $this->pdo->prepare(
                'UPDATE partners
                 SET code = ?, display_name = ?, tier = ?, notes = ?, is_active = ?
                 WHERE id = ?'
            )->execute([
                $code,
                $display,
                $tier,
                $notes !== '' ? $notes : null,
                $active ? 1 : 0,
                $partnerId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['plain_password' => $plain !== '' ? $plain : null];
    }
}

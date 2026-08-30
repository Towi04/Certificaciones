<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SupplierRepository;
use App\Support\Crypto;

/** Alta/edición de proveedores, contactos y accesos a plataformas. */
final class SupplierAdminService
{
    public const CONTACT_ROLES = [
        'General',
        'Ventas',
        'Soporte',
        'Facturación',
        'Técnico / plataformas',
        'Otro',
    ];

    private SupplierRepository $suppliers;
    private BrandAssetService $assets;

    public function __construct()
    {
        $this->suppliers = new SupplierRepository();
        $this->assets = new BrandAssetService();
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = str_replace('_', '-', $code);

        return preg_replace('/[^a-z0-9-]/', '', $code) ?? '';
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): int
    {
        $data = $this->buildPayload($input, true);
        if ($this->suppliers->findByCode($data['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe un proveedor con el código ' . $data['code']);
        }

        return $this->suppliers->create($data);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): void
    {
        if ($this->suppliers->find($id) === null) {
            throw new \InvalidArgumentException('Proveedor no encontrado.');
        }
        $data = $this->buildPayload($input, false);
        unset($data['code']);
        $this->suppliers->update($id, $data);
    }

    public function delete(int $id): void
    {
        $supplier = $this->suppliers->find($id);
        if ($supplier === null) {
            throw new \InvalidArgumentException('Proveedor no encontrado.');
        }
        $productCount = $this->suppliers->countProducts($id);
        $groupCount = $this->suppliers->countGroups($id);
        if ($productCount > 0 || $groupCount > 0) {
            throw new \InvalidArgumentException(
                'No se puede eliminar: tiene ' . $productCount . ' producto(s) y '
                . $groupCount . ' grupo(s). Desasigna primero o marca el proveedor como inactivo.'
            );
        }
        $this->assets->deletePublicFile($supplier['logo_path'] ?? null);
        $this->suppliers->delete($id);
    }

    /** @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file */
    public function uploadLogo(int $id, array $file): string
    {
        $supplier = $this->suppliers->find($id);
        if ($supplier === null) {
            throw new \InvalidArgumentException('Proveedor no encontrado.');
        }
        $path = $this->assets->storeLogo('suppliers', $id, $file);
        $old = $supplier['logo_path'] ?? null;
        $this->suppliers->update($id, ['logo_path' => $path]);
        if (is_string($old) && $old !== $path) {
            $this->assets->deletePublicFile($old);
        }

        return $path;
    }

    public function clearLogo(int $id): void
    {
        $supplier = $this->suppliers->find($id);
        if ($supplier === null) {
            throw new \InvalidArgumentException('Proveedor no encontrado.');
        }
        $old = $supplier['logo_path'] ?? null;
        $this->suppliers->update($id, ['logo_path' => null]);
        $this->assets->deletePublicFile(is_string($old) ? $old : null);
    }

    /** @param array<string, mixed> $input */
    public function addContact(int $supplierId, array $input): int
    {
        $this->assertSupplier($supplierId);

        return $this->suppliers->createContact($this->contactPayload($supplierId, $input));
    }

    /** @param array<string, mixed> $input */
    public function updateContact(int $supplierId, int $contactId, array $input): void
    {
        $contact = $this->suppliers->findContact($contactId);
        if ($contact === null || (int) $contact['supplier_id'] !== $supplierId) {
            throw new \InvalidArgumentException('Contacto no encontrado.');
        }
        $payload = $this->contactPayload($supplierId, $input);
        unset($payload['supplier_id']);
        $this->suppliers->updateContact($contactId, $payload);
    }

    public function deleteContact(int $supplierId, int $contactId): void
    {
        $contact = $this->suppliers->findContact($contactId);
        if ($contact === null || (int) $contact['supplier_id'] !== $supplierId) {
            throw new \InvalidArgumentException('Contacto no encontrado.');
        }
        $this->suppliers->deleteContact($contactId);
    }

    /** @param array<string, mixed> $input */
    public function addAccount(int $supplierId, array $input): int
    {
        $this->assertSupplier($supplierId);
        $payload = $this->accountPayload($supplierId, $input, true);

        return $this->suppliers->createAccount($payload);
    }

    /** @param array<string, mixed> $input */
    public function updateAccount(int $supplierId, int $accountId, array $input): void
    {
        $account = $this->suppliers->findAccount($accountId);
        if ($account === null || (int) $account['supplier_id'] !== $supplierId) {
            throw new \InvalidArgumentException('Acceso no encontrado.');
        }
        $payload = $this->accountPayload($supplierId, $input, false);
        unset($payload['supplier_id']);
        if (!array_key_exists('password_enc', $payload)) {
            // conservar la actual
        }
        $this->suppliers->updateAccount($accountId, $payload);
    }

    public function deleteAccount(int $supplierId, int $accountId): void
    {
        $account = $this->suppliers->findAccount($accountId);
        if ($account === null || (int) $account['supplier_id'] !== $supplierId) {
            throw new \InvalidArgumentException('Acceso no encontrado.');
        }
        $this->suppliers->deleteAccount($accountId);
    }

    public function revealAccountPassword(int $supplierId, int $accountId): string
    {
        $account = $this->suppliers->findAccount($accountId);
        if ($account === null || (int) $account['supplier_id'] !== $supplierId) {
            throw new \InvalidArgumentException('Acceso no encontrado.');
        }
        $enc = trim((string) ($account['password_enc'] ?? ''));
        if ($enc === '') {
            return '';
        }

        return Crypto::decrypt($enc);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name:string,code:string,website:?string,platform_url:?string,notes:?string,is_active:int}
     */
    private function buildPayload(array $input, bool $requireCode): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del proveedor es obligatorio.');
        }

        $code = self::normalizeCode((string) ($input['code'] ?? ''));
        if ($requireCode && strlen($code) < 2) {
            throw new \InvalidArgumentException('El código del proveedor es obligatorio (ej. itep).');
        }

        $website = trim((string) ($input['website'] ?? ''));
        $platform = trim((string) ($input['platform_url'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));

        return [
            'name' => $name,
            'code' => $code,
            'website' => $website !== '' ? $website : null,
            'platform_url' => $platform !== '' ? $platform : null,
            'notes' => $notes !== '' ? $notes : null,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{supplier_id:int,role_label:string,name:string,email:?string,phone:?string,notes:?string}
     */
    private function contactPayload(int $supplierId, array $input): array
    {
        $role = trim((string) ($input['role_label'] ?? ''));
        if ($role === '') {
            throw new \InvalidArgumentException('Indica el área o rol del contacto (General, Ventas, Soporte…).');
        }
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        if ($email === '' && $phone === '') {
            throw new \InvalidArgumentException('Agrega al menos un teléfono o un correo para el contacto.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo del contacto no es válido.');
        }
        $notes = trim((string) ($input['notes'] ?? ''));

        return [
            'supplier_id' => $supplierId,
            'role_label' => mb_substr($role, 0, 80),
            'name' => mb_substr($name, 0, 190),
            'email' => $email !== '' ? mb_substr($email, 0, 190) : null,
            'phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null,
            'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function accountPayload(int $supplierId, array $input, bool $requirePassword): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') {
            throw new \InvalidArgumentException('Indica un nombre para el acceso (ej. Portal admin iTEP).');
        }
        $loginUrl = trim((string) ($input['login_url'] ?? ''));
        if ($loginUrl !== '' && !filter_var($loginUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('El link de la plataforma no es una URL válida.');
        }
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $notes = trim((string) ($input['notes'] ?? ''));

        $out = [
            'supplier_id' => $supplierId,
            'label' => mb_substr($label, 0, 120),
            'login_url' => $loginUrl !== '' ? mb_substr($loginUrl, 0, 255) : null,
            'username' => $username !== '' ? mb_substr($username, 0, 190) : null,
            'notes' => $notes !== '' ? $notes : null,
        ];

        if ($password !== '') {
            $out['password_enc'] = Crypto::encrypt($password);
        } elseif ($requirePassword) {
            throw new \InvalidArgumentException('La contraseña del acceso es obligatoria al crearlo.');
        }

        return $out;
    }

    private function assertSupplier(int $id): void
    {
        if ($this->suppliers->find($id) === null) {
            throw new \InvalidArgumentException('Proveedor no encontrado.');
        }
    }
}

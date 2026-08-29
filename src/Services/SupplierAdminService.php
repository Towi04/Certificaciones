<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SupplierRepository;

/** Alta/edición de proveedores desde el panel admin. */
final class SupplierAdminService
{
    private SupplierRepository $suppliers;

    public function __construct()
    {
        $this->suppliers = new SupplierRepository();
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = str_replace('_', '-', $code);

        return preg_replace('/[^a-z0-9-]/', '', $code) ?? '';
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input): int
    {
        $data = $this->buildPayload($input, true);
        if ($this->suppliers->findByCode($data['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe un proveedor con el código ' . $data['code']);
        }

        return $this->suppliers->create($data);
    }

    /**
     * @param array<string, mixed> $input
     */
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
        $this->suppliers->delete($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name:string,code:string,website:?string,notes:?string,is_active:int}
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
        $notes = trim((string) ($input['notes'] ?? ''));

        return [
            'name' => $name,
            'code' => $code,
            'website' => $website !== '' ? $website : null,
            'notes' => $notes !== '' ? $notes : null,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        ];
    }
}

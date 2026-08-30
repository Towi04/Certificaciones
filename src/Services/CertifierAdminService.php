<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CertifierRepository;

/** CRUD de casas certificadoras (logo, web y plataforma). */
final class CertifierAdminService
{
    private CertifierRepository $certifiers;
    private BrandAssetService $assets;

    public function __construct()
    {
        $this->certifiers = new CertifierRepository();
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
        if ($this->certifiers->findByCode($data['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe una certificadora con el código ' . $data['code']);
        }

        return $this->certifiers->create($data);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): void
    {
        if ($this->certifiers->find($id) === null) {
            throw new \InvalidArgumentException('Certificadora no encontrada.');
        }
        $data = $this->buildPayload($input, false);
        unset($data['code']);
        $this->certifiers->update($id, $data);
    }

    public function delete(int $id): void
    {
        $certifier = $this->certifiers->find($id);
        if ($certifier === null) {
            throw new \InvalidArgumentException('Certificadora no encontrada.');
        }
        if ($this->certifiers->countProducts($id) > 0) {
            throw new \InvalidArgumentException(
                'No se puede eliminar: hay productos vinculados. Desasigna la certificadora o márcala inactiva.'
            );
        }
        $this->assets->deletePublicFile($certifier['logo_path'] ?? null);
        $this->certifiers->delete($id);
    }

    /** @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file */
    public function uploadLogo(int $id, array $file): string
    {
        $certifier = $this->certifiers->find($id);
        if ($certifier === null) {
            throw new \InvalidArgumentException('Certificadora no encontrada.');
        }
        $path = $this->assets->storeLogo('certifiers', $id, $file);
        $old = $certifier['logo_path'] ?? null;
        $this->certifiers->update($id, ['logo_path' => $path]);
        if (is_string($old) && $old !== $path) {
            $this->assets->deletePublicFile($old);
        }

        return $path;
    }

    public function clearLogo(int $id): void
    {
        $certifier = $this->certifiers->find($id);
        if ($certifier === null) {
            throw new \InvalidArgumentException('Certificadora no encontrada.');
        }
        $old = $certifier['logo_path'] ?? null;
        $this->certifiers->update($id, ['logo_path' => null]);
        $this->assets->deletePublicFile(is_string($old) ? $old : null);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name:string,code:string,website:?string,platform_url:?string,notes:?string,is_active:int}
     */
    private function buildPayload(array $input, bool $requireCode): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre de la certificadora es obligatorio.');
        }
        $code = self::normalizeCode((string) ($input['code'] ?? ''));
        if ($requireCode && strlen($code) < 2) {
            throw new \InvalidArgumentException('El código es obligatorio (ej. cambridge).');
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
}

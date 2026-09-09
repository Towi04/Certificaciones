<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CatalogFilterRepository;
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

    public function allocateUniqueCode(string $name): string
    {
        $base = self::normalizeCode(ProductAdminService::slugify($name));
        if (strlen($base) < 2) {
            $base = 'certificadora';
        }
        $candidate = $base;
        $n = 2;
        while ($this->certifiers->findByCode($candidate) !== null) {
            $candidate = $base . '-' . $n;
            $n++;
            if ($n > 500) {
                throw new \RuntimeException('No se pudo generar un código único para la certificadora.');
            }
        }

        return $candidate;
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): int
    {
        $data = $this->buildPayload($input, true);
        if ($this->certifiers->findByCode($data['code']) !== null) {
            throw new \InvalidArgumentException('Ya existe una certificadora con el código ' . $data['code']);
        }

        $id = $this->certifiers->create($data);
        $this->syncCatalogFilterFor($id);

        return $id;
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
        $this->syncCatalogFilterFor($id);
    }

    /** Refleja la certificadora como filtro del catálogo (grupo Certificadora). */
    private function syncCatalogFilterFor(int $certifierId): void
    {
        $cert = $this->certifiers->find($certifierId);
        if ($cert === null) {
            return;
        }
        $code = trim((string) ($cert['code'] ?? ''));
        $name = trim((string) ($cert['name'] ?? ''));
        if ($code === '' || $name === '') {
            return;
        }
        $active = !empty($cert['is_active']);
        try {
            (new CatalogFilterRepository())->ensureFilter(
                'cert-' . $code,
                $name,
                CatalogFilterRepository::CERTIFIER_GROUP,
                50 + $certifierId,
                ['is_active' => $active, 'show_in_catalog' => $active]
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] syncCatalogFilterFor certifier: ' . $e->getMessage());
        }
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
        if ($requireCode) {
            if (strlen($code) < 2) {
                $code = $this->allocateUniqueCode($name);
            } elseif ($this->certifiers->findByCode($code) !== null) {
                $code = $this->allocateUniqueCode($name);
            }
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

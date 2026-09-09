<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CertifierRepository;
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

    /** Logo sin denominación (solo símbolo). */
    public const LOGO_MARK = 'mark';
    /** Logo con denominación (símbolo + nombre). */
    public const LOGO_WORDMARK = 'wordmark';

    private SupplierRepository $suppliers;
    private CertifierRepository $certifiers;
    private BrandAssetService $assets;

    public function __construct()
    {
        $this->suppliers = new SupplierRepository();
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
            $base = 'proveedor';
        }
        $candidate = $base;
        $n = 2;
        while ($this->suppliers->findByCode($candidate) !== null) {
            $candidate = $base . '-' . $n;
            $n++;
            if ($n > 500) {
                throw new \RuntimeException('No se pudo generar un código único para el proveedor.');
            }
        }

        return $candidate;
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

    /**
     * Guarda datos + logos (+ contacto/acceso nuevos opcionales) en una sola acción.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @return list<string> mensajes de lo aplicado
     */
    public function saveAll(int $id, array $input, array $files = []): array
    {
        $this->update($id, $input);
        $applied = ['Datos del proveedor'];

        $logoJobs = [
            self::LOGO_MARK => [
                'file' => 'logo_mark',
                'remove' => 'remove_logo_mark',
                'label' => 'Logo sin denominación',
            ],
            self::LOGO_WORDMARK => [
                'file' => 'logo_wordmark',
                'remove' => 'remove_logo_wordmark',
                'label' => 'Logo con denominación',
            ],
        ];
        foreach ($logoJobs as $variant => $job) {
            if (!empty($input[$job['remove']])) {
                $this->clearLogo($id, $variant);
                $applied[] = $job['label'] . ' eliminado';
                continue;
            }
            $file = $files[$job['file']] ?? null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $this->uploadLogo($id, $file, $variant);
                $applied[] = $job['label'] . ' actualizado';
            }
        }

        $contactSync = $this->syncContactsFromInput($id, $input);
        if ($contactSync['created'] > 0 || $contactSync['updated'] > 0 || $contactSync['deleted'] > 0) {
            $parts = [];
            if ($contactSync['created'] > 0) {
                $parts[] = $contactSync['created'] . ' contacto(s) nuevo(s)';
            }
            if ($contactSync['updated'] > 0) {
                $parts[] = $contactSync['updated'] . ' contacto(s) actualizado(s)';
            }
            if ($contactSync['deleted'] > 0) {
                $parts[] = $contactSync['deleted'] . ' contacto(s) eliminado(s)';
            }
            $applied[] = 'Contactos: ' . implode(', ', $parts);
        }

        if (array_key_exists('certifier_ids', $input) || array_key_exists('certifier_ids_present', $input)) {
            $raw = $input['certifier_ids'] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $ids = [];
            foreach ($raw as $cid) {
                $cid = (int) $cid;
                if ($cid > 0) {
                    if ($this->certifiers->find($cid) === null) {
                        throw new \InvalidArgumentException('Certificadora no válida: #' . $cid);
                    }
                    $ids[] = $cid;
                }
            }
            $before = $this->suppliers->certifierIds($id);
            sort($before);
            $after = $ids;
            sort($after);
            $this->suppliers->syncCertifiers($id, $ids);
            if ($before !== $after) {
                $applied[] = 'Certificadoras vinculadas: ' . count($ids);
            }
        }

        $accountLabel = trim((string) ($input['account_label'] ?? ''));
        if ($accountLabel !== '') {
            $this->addAccount($id, [
                'label' => $accountLabel,
                'login_url' => (string) ($input['account_login_url'] ?? ''),
                'username' => (string) ($input['account_username'] ?? ''),
                'password' => (string) ($input['account_password'] ?? ''),
                'notes' => (string) ($input['account_notes'] ?? ''),
            ]);
            $applied[] = 'Acceso agregado';
        }

        return $applied;
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
        $this->assets->deletePublicFile($supplier['logo_wordmark_path'] ?? null);
        $this->suppliers->delete($id);
    }

    /**
     * Ruta pública del logo según variante.
     * mark = sin denominación; wordmark = con denominación.
     * Si falta la pedida, hace fallback a la otra.
     *
     * @param array<string, mixed>|null $supplier
     */
    public static function logoPath(?array $supplier, string $variant = self::LOGO_MARK): ?string
    {
        if ($supplier === null) {
            return null;
        }
        $mark = trim((string) ($supplier['logo_path'] ?? ''));
        $word = trim((string) ($supplier['logo_wordmark_path'] ?? ''));
        if ($variant === self::LOGO_WORDMARK) {
            if ($word !== '') {
                return $word;
            }

            return $mark !== '' ? $mark : null;
        }
        if ($mark !== '') {
            return $mark;
        }

        return $word !== '' ? $word : null;
    }

    /**
     * @param array{tmp_name?:string,name?:string,error?:int,size?:int,type?:string} $file
     */
    public function uploadLogo(int $id, array $file, string $variant = self::LOGO_MARK): string
    {
        $supplier = $this->suppliers->find($id);
        if ($supplier === null) {
            throw new \InvalidArgumentException('Proveedor no encontrado.');
        }
        $column = $variant === self::LOGO_WORDMARK ? 'logo_wordmark_path' : 'logo_path';
        $path = $this->assets->storeLogo('suppliers', $id, $file);
        $old = $supplier[$column] ?? null;
        $this->suppliers->update($id, [$column => $path]);
        if (is_string($old) && $old !== $path) {
            $this->assets->deletePublicFile($old);
        }

        return $path;
    }

    public function clearLogo(int $id, string $variant = self::LOGO_MARK): void
    {
        $supplier = $this->suppliers->find($id);
        if ($supplier === null) {
            throw new \InvalidArgumentException('Proveedor no encontrado.');
        }
        $column = $variant === self::LOGO_WORDMARK ? 'logo_wordmark_path' : 'logo_path';
        $old = $supplier[$column] ?? null;
        $this->suppliers->update($id, [$column => null]);
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

    /**
     * Sincroniza la lista de contactos desde el formulario (crear / actualizar / eliminar).
     *
     * @param array<string, mixed> $input
     * @return array{created:int,updated:int,deleted:int}
     */
    public function syncContactsFromInput(int $supplierId, array $input): array
    {
        $this->assertSupplier($supplierId);

        $deleted = 0;
        $deleteIds = is_array($input['contact_delete_ids'] ?? null) ? $input['contact_delete_ids'] : [];
        foreach ($deleteIds as $rawId) {
            $cid = (int) $rawId;
            if ($cid < 1) {
                continue;
            }
            $existing = $this->suppliers->findContact($cid);
            if ($existing === null || (int) $existing['supplier_id'] !== $supplierId) {
                continue;
            }
            $this->suppliers->deleteContact($cid);
            $deleted++;
        }

        $ids = is_array($input['contact_id'] ?? null) ? $input['contact_id'] : [];
        $roles = is_array($input['contact_role_label'] ?? null) ? $input['contact_role_label'] : [];
        $names = is_array($input['contact_name'] ?? null) ? $input['contact_name'] : [];
        $phones = is_array($input['contact_phone'] ?? null) ? $input['contact_phone'] : [];
        $emails = is_array($input['contact_email'] ?? null) ? $input['contact_email'] : [];
        $notes = is_array($input['contact_notes'] ?? null) ? $input['contact_notes'] : [];

        // Compat: un solo contacto “nuevo” con nombres planos (legado).
        if ($roles === [] && trim((string) ($input['contact_role_label'] ?? '')) !== '') {
            $ids = [''];
            $roles = [(string) $input['contact_role_label']];
            $names = [(string) ($input['contact_name'] ?? '')];
            $phones = [(string) ($input['contact_phone'] ?? '')];
            $emails = [(string) ($input['contact_email'] ?? '')];
            $notes = [(string) ($input['contact_notes'] ?? '')];
        }

        $created = 0;
        $updated = 0;
        $n = max(count($ids), count($roles), count($names), count($phones), count($emails), count($notes));
        $deletedSet = [];
        foreach ($deleteIds as $rawId) {
            $deletedSet[(int) $rawId] = true;
        }

        for ($i = 0; $i < $n; $i++) {
            $cid = (int) ($ids[$i] ?? 0);
            if ($cid > 0 && isset($deletedSet[$cid])) {
                continue;
            }
            $row = [
                'role_label' => (string) ($roles[$i] ?? ''),
                'name' => (string) ($names[$i] ?? ''),
                'phone' => (string) ($phones[$i] ?? ''),
                'email' => (string) ($emails[$i] ?? ''),
                'notes' => (string) ($notes[$i] ?? ''),
            ];
            $role = trim($row['role_label']);
            $name = trim($row['name']);
            $phone = trim($row['phone']);
            $email = trim($row['email']);
            $note = trim($row['notes']);

            // Fila vacía (nuevo sin datos): ignorar.
            if ($cid < 1 && $role === '' && $name === '' && $phone === '' && $email === '' && $note === '') {
                continue;
            }

            if ($cid > 0) {
                $existing = $this->suppliers->findContact($cid);
                if ($existing === null || (int) $existing['supplier_id'] !== $supplierId) {
                    throw new \InvalidArgumentException('Contacto no encontrado (#' . $cid . ').');
                }
                $payload = $this->contactPayload($supplierId, $row);
                unset($payload['supplier_id']);
                $this->suppliers->updateContact($cid, $payload);
                $updated++;
            } else {
                $this->suppliers->createContact($this->contactPayload($supplierId, $row));
                $created++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }

    /** @param array<string, mixed> $input */
    public function addAccount(int $supplierId, array $input): int
    {
        $this->assertSupplier($supplierId);
        $payload = $this->accountPayload($supplierId, $input, false);

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
     * @return array{name:string,code:string,website:?string,is_active:int}
     */
    private function buildPayload(array $input, bool $requireCode): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del proveedor es obligatorio.');
        }

        $code = self::normalizeCode((string) ($input['code'] ?? ''));
        if ($requireCode) {
            if (strlen($code) < 2) {
                $code = $this->allocateUniqueCode($name);
            } elseif ($this->suppliers->findByCode($code) !== null) {
                $code = $this->allocateUniqueCode($name);
            }
        }

        $website = trim((string) ($input['website'] ?? ''));

        // Portal y notas viven en Accesos / plataformas (supplier_accounts), no aquí.
        return [
            'name' => $name,
            'code' => $code,
            'website' => $website !== '' ? $website : null,
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
            // Conservado por compatibilidad; la creación ya no exige contraseña.
            throw new \InvalidArgumentException('La contraseña del acceso es obligatoria al crearlo.');
        }
        // Si la contraseña viene vacía en edición, no tocar password_enc.

        return $out;
    }

    private function assertSupplier(int $id): void
    {
        if ($this->suppliers->find($id) === null) {
            throw new \InvalidArgumentException('Proveedor no encontrado.');
        }
    }
}

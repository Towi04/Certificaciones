<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\Settings;
use PDO;

/**
 * Perfil público de escuela partner + aprobación admin + switch global del catálogo.
 */
final class PartnerDirectoryService
{
    public const SETTING_PUBLIC = 'partner_directory_public_enabled';

    private PDO $pdo;
    private DocumentService $documents;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->documents = new DocumentService();
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM partners LIKE 'publish_status'");
            if ($stmt && $stmt->fetch()) {
                $done = true;

                return;
            }
            $alters = [
                'directory_logo_path VARCHAR(255) NULL',
                'directory_phone VARCHAR(40) NULL',
                'directory_address VARCHAR(255) NULL',
                'directory_maps_url VARCHAR(512) NULL',
                'directory_description VARCHAR(500) NULL',
                "publish_status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft'",
                'publish_requested_at DATETIME NULL',
                'publish_reviewed_at DATETIME NULL',
                'publish_reviewed_by BIGINT UNSIGNED NULL',
                'publish_note VARCHAR(500) NULL',
                'published_json JSON NULL',
                'pending_json JSON NULL',
            ];
            foreach ($alters as $def) {
                $col = explode(' ', $def, 2)[0];
                $check = $this->pdo->query('SHOW COLUMNS FROM partners LIKE ' . $this->pdo->quote($col));
                if ($check && $check->fetch()) {
                    continue;
                }
                $this->pdo->exec('ALTER TABLE partners ADD COLUMN ' . $def);
            }
        } catch (\Throwable $e) {
            error_log('[Doceo] PartnerDirectoryService::ensureSchema: ' . $e->getMessage());
        }
        $done = true;
    }

    public function isPublicEnabled(): bool
    {
        return Settings::get(self::SETTING_PUBLIC, '0') === '1';
    }

    public function setPublicEnabled(bool $enabled): void
    {
        Settings::set(self::SETTING_PUBLIC, $enabled ? '1' : '0');
    }

    /**
     * @return array<string, mixed>
     */
    public function profileForPartner(int $partnerId): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.code, p.display_name, p.directory_logo_path, p.directory_phone,
                    p.directory_address, p.directory_maps_url, p.directory_description,
                    p.publish_status, p.publish_requested_at, p.publish_reviewed_at,
                    p.publish_note, p.published_json, p.pending_json, u.phone AS user_phone
             FROM partners p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('Partner no encontrado.');
        }
        $pending = $this->decodeJson($row['pending_json'] ?? null);
        $published = $this->decodeJson($row['published_json'] ?? null);
        $draft = $pending !== [] ? $pending : [
            'logo_path' => (string) ($row['directory_logo_path'] ?? ''),
            'phone' => (string) ($row['directory_phone'] ?? ($row['user_phone'] ?? '')),
            'address' => (string) ($row['directory_address'] ?? ''),
            'maps_url' => (string) ($row['directory_maps_url'] ?? ''),
            'description' => (string) ($row['directory_description'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
        ];
        if (($draft['display_name'] ?? '') === '') {
            $draft['display_name'] = (string) ($row['display_name'] ?? '');
        }

        return [
            'partner' => $row,
            'draft' => $draft,
            'published' => $published,
            'status' => (string) ($row['publish_status'] ?? 'draft'),
        ];
    }

    /**
     * @param array{
     *   display_name?:string,phone?:string,address?:string,maps_url?:string,description?:string
     * } $data
     * @param array<string,mixed>|null $logoFile
     */
    public function saveDraft(int $partnerId, array $data, ?array $logoFile = null, bool $submit = false): void
    {
        $this->ensureSchema();
        $profile = $this->profileForPartner($partnerId);
        $draft = $profile['draft'];

        $display = trim((string) ($data['display_name'] ?? $draft['display_name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        $maps = trim((string) ($data['maps_url'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if ($display === '') {
            throw new \InvalidArgumentException('El nombre de la escuela es obligatorio.');
        }
        if (mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('La descripción no puede superar 500 caracteres.');
        }
        if ($maps !== '' && !preg_match('#^https?://#i', $maps)) {
            throw new \InvalidArgumentException('El link de Maps debe empezar con https://');
        }

        $logoPath = (string) ($draft['logo_path'] ?? '');
        if ($logoFile !== null && ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->documents->storeUploaded(
                $logoFile,
                'partners/directory/' . $partnerId,
                '.jpg,.jpeg,.png,.webp'
            );
            $logoPath = $stored['path'];
        }

        $pending = [
            'display_name' => $display,
            'phone' => $phone,
            'address' => $address,
            'maps_url' => $maps,
            'description' => $description,
            'logo_path' => $logoPath,
        ];

        if ($submit) {
            if ($phone === '' || $address === '' || $description === '') {
                throw new \InvalidArgumentException(
                    'Para enviar a revisión indica teléfono público, dirección y descripción.'
                );
            }
            $this->pdo->prepare(
                'UPDATE partners
                 SET pending_json = ?, directory_logo_path = ?, directory_phone = ?,
                     directory_address = ?, directory_maps_url = ?, directory_description = ?,
                     publish_status = ?, publish_requested_at = NOW(), publish_note = NULL
                 WHERE id = ?'
            )->execute([
                json_encode($pending, JSON_UNESCAPED_UNICODE),
                $logoPath !== '' ? $logoPath : null,
                $phone !== '' ? $phone : null,
                $address !== '' ? $address : null,
                $maps !== '' ? $maps : null,
                $description !== '' ? $description : null,
                'pending',
                $partnerId,
            ]);

            return;
        }

        $status = (string) ($profile['status'] ?? 'draft');
        // Re-editar un aprobado vuelve a pending solo al enviar; guardar borrador mantiene draft/rejected
        // o deja pending si ya estaba en cola.
        $newStatus = $status === 'pending' ? 'pending' : ($status === 'approved' ? 'approved' : $status);
        if ($status === 'rejected') {
            $newStatus = 'draft';
        }

        $this->pdo->prepare(
            'UPDATE partners
             SET pending_json = ?, directory_logo_path = ?, directory_phone = ?,
                 directory_address = ?, directory_maps_url = ?, directory_description = ?,
                 publish_status = ?
             WHERE id = ?'
        )->execute([
            json_encode($pending, JSON_UNESCAPED_UNICODE),
            $logoPath !== '' ? $logoPath : null,
            $phone !== '' ? $phone : null,
            $address !== '' ? $address : null,
            $maps !== '' ? $maps : null,
            $description !== '' ? $description : null,
            $newStatus === 'approved' ? 'approved' : $newStatus,
            $partnerId,
        ]);
    }

    public function approve(int $partnerId, int $adminUserId, ?string $note = null): void
    {
        $this->ensureSchema();
        $profile = $this->profileForPartner($partnerId);
        $pending = $profile['draft'];
        if (($pending['phone'] ?? '') === '' || ($pending['description'] ?? '') === '') {
            throw new \InvalidArgumentException('El borrador está incompleto.');
        }
        $published = $pending;
        $this->pdo->prepare(
            'UPDATE partners
             SET published_json = ?, pending_json = NULL,
                 directory_logo_path = ?, directory_phone = ?, directory_address = ?,
                 directory_maps_url = ?, directory_description = ?,
                 publish_status = ?, publish_reviewed_at = NOW(), publish_reviewed_by = ?,
                 publish_note = ?, display_name = COALESCE(NULLIF(?, \'\'), display_name)
             WHERE id = ?'
        )->execute([
            json_encode($published, JSON_UNESCAPED_UNICODE),
            ($published['logo_path'] ?? '') !== '' ? $published['logo_path'] : null,
            ($published['phone'] ?? '') !== '' ? $published['phone'] : null,
            ($published['address'] ?? '') !== '' ? $published['address'] : null,
            ($published['maps_url'] ?? '') !== '' ? $published['maps_url'] : null,
            ($published['description'] ?? '') !== '' ? $published['description'] : null,
            'approved',
            $adminUserId,
            $note !== null && trim($note) !== '' ? trim($note) : null,
            (string) ($published['display_name'] ?? ''),
            $partnerId,
        ]);
    }

    public function reject(int $partnerId, int $adminUserId, string $note): void
    {
        $this->ensureSchema();
        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException('Indica una nota al rechazar.');
        }
        $this->pdo->prepare(
            'UPDATE partners
             SET publish_status = ?, publish_reviewed_at = NOW(), publish_reviewed_by = ?, publish_note = ?
             WHERE id = ?'
        )->execute(['rejected', $adminUserId, mb_substr($note, 0, 500), $partnerId]);
    }

    /** @return list<array<string, mixed>> */
    public function pendingQueue(): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->query(
            "SELECT p.id, p.code, p.display_name, p.publish_requested_at, p.pending_json,
                    p.directory_phone, p.directory_description, u.email
             FROM partners p
             JOIN users u ON u.id = p.user_id
             WHERE p.publish_status = 'pending'
             ORDER BY p.publish_requested_at ASC, p.id ASC"
        );

        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    /**
     * Cards públicas: solo si el switch global está on y hay al menos un approved.
     *
     * @return list<array<string, mixed>>
     */
    public function publicCards(): array
    {
        if (!$this->isPublicEnabled()) {
            return [];
        }
        $this->ensureSchema();
        $stmt = $this->pdo->query(
            "SELECT p.id, p.code, p.display_name, p.published_json, p.directory_logo_path,
                    p.directory_phone, p.directory_address, p.directory_maps_url, p.directory_description
             FROM partners p
             WHERE p.is_active = 1 AND p.publish_status = 'approved'
               AND p.published_json IS NOT NULL
             ORDER BY p.display_name ASC, p.id ASC"
        );
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $out = [];
        foreach ($rows as $row) {
            $pub = $this->decodeJson($row['published_json'] ?? null);
            if ($pub === []) {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'display_name' => (string) ($pub['display_name'] ?? $row['display_name'] ?? ''),
                'phone' => (string) ($pub['phone'] ?? ''),
                'address' => (string) ($pub['address'] ?? ''),
                'maps_url' => (string) ($pub['maps_url'] ?? ''),
                'description' => (string) ($pub['description'] ?? ''),
                'logo_path' => (string) ($pub['logo_path'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

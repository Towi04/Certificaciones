<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class SupplierRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->ensureWordmarkColumn();
        $this->ensureNotesColumn();
        $this->ensureSupplierCertifiersTable();
    }

    /** Columna de logo con denominación (instalaciones ya existentes). */
    private function ensureWordmarkColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM suppliers LIKE 'logo_wordmark_path'");
            if ($stmt && $stmt->fetch()) {
                return;
            }
            $this->pdo->exec(
                'ALTER TABLE suppliers ADD COLUMN logo_wordmark_path VARCHAR(255) NULL AFTER logo_path'
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] ensureWordmarkColumn: ' . $e->getMessage());
        }
    }

    /** Notas internas del proveedor (instalaciones ya existentes). */
    private function ensureNotesColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM suppliers LIKE 'notes'");
            if ($stmt && $stmt->fetch()) {
                return;
            }
            $this->pdo->exec(
                'ALTER TABLE suppliers ADD COLUMN notes TEXT NULL AFTER platform_url'
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] ensureNotesColumn: ' . $e->getMessage());
        }
    }

    /** Pivot proveedor ↔ certificadoras (instalaciones ya existentes). */
    private function ensureSupplierCertifiersTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS supplier_certifiers (
                  supplier_id BIGINT UNSIGNED NOT NULL,
                  certifier_id BIGINT UNSIGNED NOT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (supplier_id, certifier_id),
                  KEY idx_supplier_certifiers_certifier (certifier_id),
                  CONSTRAINT fk_sc_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
                  CONSTRAINT fk_sc_certifier FOREIGN KEY (certifier_id) REFERENCES certifiers(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] ensureSupplierCertifiersTable: ' . $e->getMessage());
        }
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function all(?int $limit = null, ?int $offset = null): array
    {
        $sql = 'SELECT * FROM suppliers ORDER BY name';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) ($offset ?? 0));
        }

        return $this->pdo->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppliers (name, code, website, logo_path, logo_wordmark_path, platform_url, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['code'],
            $data['website'] ?? null,
            $data['logo_path'] ?? null,
            $data['logo_wordmark_path'] ?? null,
            $data['platform_url'] ?? null,
            $data['notes'] ?? null,
            $data['is_active'] ?? 1,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = [];
        foreach ($data as $k => $_) {
            $sets[] = "{$k} = :{$k}";
        }
        $sql = 'UPDATE suppliers SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countProducts(int $supplierId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    public function countGroups(int $supplierId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_groups WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function contacts(int $supplierId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_contacts WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll();
    }

    public function findContact(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_contacts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function createContact(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO supplier_contacts (supplier_id, role_label, name, email, phone, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['supplier_id'],
            $data['role_label'],
            $data['name'] ?? '',
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['notes'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function updateContact(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = [];
        foreach ($data as $k => $_) {
            $sets[] = "{$k} = :{$k}";
        }
        $stmt = $this->pdo->prepare('UPDATE supplier_contacts SET ' . implode(', ', $sets) . ' WHERE id = :id');
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteContact(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM supplier_contacts WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public function accounts(int $supplierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, supplier_id, label, login_url, username, notes FROM supplier_accounts WHERE supplier_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll();
    }

    public function findAccount(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function createAccount(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO supplier_accounts (supplier_id, label, login_url, username, password_enc, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['supplier_id'],
            $data['label'],
            $data['login_url'] ?? null,
            $data['username'] ?? null,
            $data['password_enc'] ?? null,
            $data['notes'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function updateAccount(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = [];
        foreach ($data as $k => $_) {
            $sets[] = "{$k} = :{$k}";
        }
        $stmt = $this->pdo->prepare('UPDATE supplier_accounts SET ' . implode(', ', $sets) . ' WHERE id = :id');
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteAccount(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM supplier_accounts WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return list<int> */
    public function certifierIds(int $supplierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT certifier_id FROM supplier_certifiers WHERE supplier_id = ? ORDER BY certifier_id'
        );
        $stmt->execute([$supplierId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> */
    public function certifiers(int $supplierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*
             FROM supplier_certifiers sc
             INNER JOIN certifiers c ON c.id = sc.certifier_id
             WHERE sc.supplier_id = ?
             ORDER BY c.name'
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll();
    }

    /**
     * Mapa supplier_id => list of {id, name, code} for product form filtering.
     *
     * @return array<int, list<array{id:int,name:string,code:string}>>
     */
    public function certifiersGroupedBySupplier(): array
    {
        $sql = 'SELECT sc.supplier_id, c.id, c.name, c.code
                FROM supplier_certifiers sc
                INNER JOIN certifiers c ON c.id = sc.certifier_id
                ORDER BY sc.supplier_id, c.name';
        $rows = $this->pdo->query($sql)->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $sid = (int) $row['supplier_id'];
            $out[$sid][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'code' => (string) $row['code'],
            ];
        }

        return $out;
    }

    /**
     * Reemplaza las certificadoras vinculadas al proveedor.
     *
     * @param list<int> $certifierIds
     */
    public function syncCertifiers(int $supplierId, array $certifierIds): void
    {
        $ids = [];
        foreach ($certifierIds as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) {
                $ids[$cid] = $cid;
            }
        }
        $ids = array_values($ids);

        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM supplier_certifiers WHERE supplier_id = ?');
            $del->execute([$supplierId]);
            if ($ids !== []) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO supplier_certifiers (supplier_id, certifier_id) VALUES (?, ?)'
                );
                foreach ($ids as $cid) {
                    $ins->execute([$supplierId, $cid]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function linkCertifier(int $supplierId, int $certifierId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO supplier_certifiers (supplier_id, certifier_id) VALUES (?, ?)'
        );
        $stmt->execute([$supplierId, $certifierId]);
    }
}

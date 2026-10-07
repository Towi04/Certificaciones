<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Contactos de publicidad (p. ej. ventas anteriores importadas por CSV).
 * No crean cuenta de usuario; solo sirven para audiencias de campañas.
 */
final class MarketingContactService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
        $this->ensureTables();
    }

    public function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS marketing_contacts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                first_name VARCHAR(120) NOT NULL DEFAULT '',
                last_name_p VARCHAR(120) NOT NULL DEFAULT '',
                last_name_m VARCHAR(120) NOT NULL DEFAULT '',
                full_name VARCHAR(255) NOT NULL DEFAULT '',
                phone VARCHAR(40) NULL,
                product_name VARCHAR(190) NULL,
                product_code VARCHAR(80) NULL,
                product_id BIGINT UNSIGNED NULL,
                supplier_id BIGINT UNSIGNED NULL,
                purchased_at DATE NULL,
                notes VARCHAR(500) NULL,
                source VARCHAR(40) NOT NULL DEFAULT 'legacy_import',
                opted_out_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_marketing_contacts_email (email),
                KEY idx_mkt_contacts_product (product_id),
                KEY idx_mkt_contacts_supplier (supplier_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function count(?string $q = null): int
    {
        [$where, $params] = $this->searchWhere($q, '');
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM marketing_contacts' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $q = null, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->searchWhere($q, 'mc.');
        $sql = 'SELECT mc.*, p.name AS matched_product_name, s.name AS supplier_name
                FROM marketing_contacts mc
                LEFT JOIN products p ON p.id = mc.product_id
                LEFT JOIN suppliers s ON s.id = mc.supplier_id'
            . $where
            . ' ORDER BY mc.id DESC LIMIT ' . max(1, min(500, $limit))
            . ' OFFSET ' . max(0, $offset);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array{imported:int,updated:int,skipped:int,errors:list<string>}
     */
    public function importCsv(string $path): array
    {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException('No se pudo leer el archivo CSV.');
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \InvalidArgumentException('No se pudo abrir el CSV.');
        }

        $header = null;
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $lineNo = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $lineNo++;
            if ($row === [null] || $row === false) {
                continue;
            }
            // BOM UTF-8
            if ($lineNo === 1 && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) ?? (string) $row[0];
            }
            if ($header === null) {
                $header = array_map(static fn ($h) => self::normalizeHeader((string) $h), $row);
                continue;
            }
            if (count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $data = [];
            foreach ($header as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $data[$key] = trim((string) ($row[$i] ?? ''));
            }
            try {
                $result = $this->upsertFromRow($data);
                if ($result === 'inserted') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Línea ' . $lineNo . ': ' . $e->getMessage();
                if (count($errors) >= 40) {
                    $errors[] = '… demasiados errores; se detuvo el detalle.';
                    break;
                }
            }
        }
        fclose($fh);

        return compact('imported', 'updated', 'skipped', 'errors');
    }

    /** Plantilla CSV sugerida (UTF-8 con BOM para Excel). */
    public function csvTemplate(): string
    {
        $headers = [
            'email',
            'first_name',
            'last_name_p',
            'last_name_m',
            'full_name',
            'phone',
            'product_name',
            'product_code',
            'purchased_at',
            'notes',
        ];
        $sample = [
            'ana.ejemplo@correo.com',
            'Ana',
            'García',
            'López',
            'Ana García López',
            '5512345678',
            'TOEFL ITP',
            'TOEFL-ITP',
            '2024-11-15',
            'Cliente anterior',
        ];
        $out = "\xEF\xBB\xBF" . implode(',', $headers) . "\n";
        $out .= implode(',', array_map(static function (string $v): string {
            return '"' . str_replace('"', '""', $v) . '"';
        }, $sample)) . "\n";

        return $out;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM marketing_contacts WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, string> $data
     * @return 'inserted'|'updated'|'skipped'
     */
    private function upsertFromRow(array $data): string
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo inválido o vacío.');
        }

        $first = trim((string) ($data['first_name'] ?? $data['nombre'] ?? ''));
        $lp = trim((string) ($data['last_name_p'] ?? $data['apellido_paterno'] ?? ''));
        $lm = trim((string) ($data['last_name_m'] ?? $data['apellido_materno'] ?? ''));
        $full = trim((string) ($data['full_name'] ?? $data['nombre_completo'] ?? ''));
        if ($full === '') {
            $full = trim(implode(' ', array_filter([$first, $lp, $lm])));
        }
        if ($first === '' && $full !== '') {
            $parts = preg_split('/\s+/', $full) ?: [];
            $first = (string) ($parts[0] ?? '');
        }
        $phone = trim((string) ($data['phone'] ?? $data['telefono'] ?? '')) ?: null;
        $productName = trim((string) ($data['product_name'] ?? $data['certificacion'] ?? $data['producto'] ?? '')) ?: null;
        $productCode = trim((string) ($data['product_code'] ?? $data['codigo_producto'] ?? '')) ?: null;
        $purchasedAt = $this->parseDate((string) ($data['purchased_at'] ?? $data['fecha_compra'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? $data['notas'] ?? '')) ?: null;

        [$productId, $supplierId] = $this->matchProduct($productCode, $productName);

        $existing = $this->pdo->prepare('SELECT id FROM marketing_contacts WHERE email = ? LIMIT 1');
        $existing->execute([$email]);
        $id = (int) ($existing->fetchColumn() ?: 0);

        if ($id > 0) {
            $this->pdo->prepare(
                'UPDATE marketing_contacts SET
                    first_name = ?, last_name_p = ?, last_name_m = ?, full_name = ?,
                    phone = COALESCE(?, phone),
                    product_name = COALESCE(?, product_name),
                    product_code = COALESCE(?, product_code),
                    product_id = COALESCE(?, product_id),
                    supplier_id = COALESCE(?, supplier_id),
                    purchased_at = COALESCE(?, purchased_at),
                    notes = COALESCE(?, notes)
                 WHERE id = ?'
            )->execute([
                $first, $lp, $lm, $full,
                $phone, $productName, $productCode, $productId, $supplierId,
                $purchasedAt, $notes, $id,
            ]);

            return 'updated';
        }

        $this->pdo->prepare(
            'INSERT INTO marketing_contacts
                (email, first_name, last_name_p, last_name_m, full_name, phone,
                 product_name, product_code, product_id, supplier_id, purchased_at, notes, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'legacy_import\')'
        )->execute([
            $email, $first, $lp, $lm, $full, $phone,
            $productName, $productCode, $productId, $supplierId, $purchasedAt, $notes,
        ]);

        return 'inserted';
    }

    /** @return array{0:?int,1:?int} product_id, supplier_id */
    private function matchProduct(?string $code, ?string $name): array
    {
        if ($code !== null && $code !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT id, supplier_id FROM products WHERE UPPER(code) = UPPER(?) LIMIT 1'
            );
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                return [(int) $row['id'], $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null];
            }
        }
        if ($name !== null && $name !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT id, supplier_id FROM products
                 WHERE name = ? OR name LIKE ?
                 ORDER BY (name = ?) DESC, id ASC
                 LIMIT 1'
            );
            $stmt->execute([$name, '%' . $name . '%', $name]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                return [(int) $row['id'], $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null];
            }
        }

        return [null, null];
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        $ts = strtotime($raw);

        return $ts ? date('Y-m-d', $ts) : null;
    }

    private static function normalizeHeader(string $h): string
    {
        $h = strtolower(trim($h));
        $h = strtr($h, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;
        $h = trim($h, '_');
        $map = [
            'correo' => 'email',
            'e_mail' => 'email',
            'mail' => 'email',
            'nombre' => 'first_name',
            'nombres' => 'first_name',
            'apellido_paterno' => 'last_name_p',
            'apellido_materno' => 'last_name_m',
            'nombre_completo' => 'full_name',
            'telefono' => 'phone',
            'celular' => 'phone',
            'certificacion' => 'product_name',
            'producto' => 'product_name',
            'examen' => 'product_name',
            'codigo' => 'product_code',
            'codigo_producto' => 'product_code',
            'fecha' => 'purchased_at',
            'fecha_compra' => 'purchased_at',
            'notas' => 'notes',
            'comentario' => 'notes',
        ];

        return $map[$h] ?? $h;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function searchWhere(?string $q, string $alias = ''): array
    {
        $q = trim((string) $q);
        if ($q === '') {
            return ['', []];
        }
        $like = '%' . $q . '%';
        $a = $alias;

        return [
            " WHERE ({$a}email LIKE ? OR {$a}full_name LIKE ? OR {$a}product_name LIKE ? OR {$a}phone LIKE ?)",
            [$like, $like, $like, $like],
        ];
    }
}

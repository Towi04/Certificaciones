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

    /**
     * Guía de nombres históricos → qué escribir en la columna producto.
     * maps_to = código de catálogo actual si existe; null = solo texto libre.
     *
     * @return list<array{label:string,write_as:string,maps_to:?string,note:string}>
     */
    public static function historicalProductHints(): array
    {
        return [
            [
                'label' => 'ELET + CENNI',
                'write_as' => 'ELET + CENNI',
                'maps_to' => 'ELET-UKS',
                'note' => 'Se enlaza al examen ELET actual (el CENNI iba empaquetado).',
            ],
            [
                'label' => 'CENNI',
                'write_as' => 'CENNI',
                'maps_to' => 'CENNI-TRAMITE',
                'note' => 'Trámite CENNI ante SEP.',
            ],
            [
                'label' => 'Elet Plus',
                'write_as' => 'Elet Plus',
                'maps_to' => null,
                'note' => 'Ya no está en catálogo; se guarda el texto tal cual.',
            ],
            [
                'label' => 'Elet Reading',
                'write_as' => 'Elet Reading',
                'maps_to' => null,
                'note' => 'Ya no está en catálogo; se guarda el texto tal cual.',
            ],
            [
                'label' => 'English Course',
                'write_as' => 'English Course',
                'maps_to' => null,
                'note' => 'Curso antiguo; se guarda el texto (sin enlace a producto actual).',
            ],
            [
                'label' => 'Excel',
                'write_as' => 'Excel',
                'maps_to' => 'MOS-EXCEL-2016',
                'note' => 'Se enlaza a Microsoft Office Specialist Excel 2016.',
            ],
            [
                'label' => 'ITEP + CENNI',
                'write_as' => 'ITEP + CENNI',
                'maps_to' => 'ITEP-CENNI',
                'note' => 'Coincide con el producto actual iTEP + CENNI.',
            ],
            [
                'label' => 'Linguaskill 4 bundle + CENNI',
                'write_as' => 'Linguaskill 4 bundle + CENNI',
                'maps_to' => null,
                'note' => 'Paquete antiguo; se guarda el texto tal cual.',
            ],
            [
                'label' => 'OOPT',
                'write_as' => 'OOPT',
                'maps_to' => 'OOPT',
                'note' => 'Coincide con Oxford Online Placement Test.',
            ],
            [
                'label' => 'TOEFL ITP',
                'write_as' => 'TOEFL ITP',
                'maps_to' => 'TOEFL-ITP',
                'note' => 'Coincide con el producto actual.',
            ],
        ];
    }

    /** Plantilla CSV sencilla (UTF-8 con BOM para Excel). */
    public function csvTemplate(): string
    {
        // Formato corto: no hace falta product_code ni apellidos separados.
        $headers = ['email', 'full_name', 'phone', 'producto', 'purchased_at'];
        $samples = [
            ['ana.ejemplo@correo.com', 'Ana García López', '5512345678', 'TOEFL ITP', '2024-11-15'],
            ['juan@correo.com', 'Juan Pérez', '5587654321', 'ELET + CENNI', '2023-05-10'],
            ['maria@correo.com', 'María Ruiz', '', 'Excel', ''],
            ['luis@correo.com', 'Luis Gómez', '5511223344', 'Elet Plus', '2022-08-01'],
        ];
        $out = "\xEF\xBB\xBF" . implode(',', $headers) . "\n";
        foreach ($samples as $sample) {
            $out .= implode(',', array_map(static function (string $v): string {
                return '"' . str_replace('"', '""', $v) . '"';
            }, $sample)) . "\n";
        }

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
        // Si solo pusieron el nombre histórico (ej. "Excel", "ELET + CENNI"),
        // resolver al código de catálogo cuando exista; si no, dejar texto libre.
        if (($productCode === null || $productCode === '') && $productName !== null) {
            $aliasCode = self::resolveHistoricalCode(null, $productName);
            if ($aliasCode !== null) {
                $productCode = $aliasCode;
            }
        } elseif ($productCode !== null && $productCode !== '') {
            $aliasCode = self::resolveHistoricalCode($productCode, null);
            if ($aliasCode !== null) {
                $productCode = $aliasCode;
            }
        }
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

    /**
     * Resuelve alias histórico → código de catálogo (o null si solo es texto libre).
     */
    public static function resolveHistoricalCode(?string $code, ?string $name): ?string
    {
        $aliases = self::historicalAliasMap();
        if ($code !== null && $code !== '') {
            $key = self::normalizeProductKey($code);
            if (isset($aliases[$key])) {
                return $aliases[$key];
            }
            // Ya es un código tipo TOEFL-ITP / ELET-UKS
            if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,59}$/i', $code)) {
                return strtoupper($code);
            }
        }
        if ($name !== null && $name !== '') {
            $key = self::normalizeProductKey($name);
            if (isset($aliases[$key])) {
                return $aliases[$key];
            }
        }

        return null;
    }

    /**
     * Clave normalizada → código catálogo (null = no mapear / producto discontinuado).
     * Valores null en el mapa se omiten: el texto se guarda sin product_id.
     *
     * @return array<string, string>
     */
    public static function historicalAliasMap(): array
    {
        return [
            'elet' => 'ELET-UKS',
            'elet_uks' => 'ELET-UKS',
            'elet_cenni' => 'ELET-UKS',
            'elet_+_cenni' => 'ELET-UKS',
            'elet_plus_cenni' => 'ELET-UKS',
            'cenni' => 'CENNI-TRAMITE',
            'tramite_cenni' => 'CENNI-TRAMITE',
            'cenni_tramite' => 'CENNI-TRAMITE',
            'excel' => 'MOS-EXCEL-2016',
            'mos_excel' => 'MOS-EXCEL-2016',
            'mos_excel_2016' => 'MOS-EXCEL-2016',
            'microsoft_excel' => 'MOS-EXCEL-2016',
            'microsoft_office_specialist_excel_2016' => 'MOS-EXCEL-2016',
            'itep' => 'ITEP-CENNI',
            'itep_cenni' => 'ITEP-CENNI',
            'itep_+_cenni' => 'ITEP-CENNI',
            'oopt' => 'OOPT',
            'oxford' => 'OOPT',
            'oxford_online_placement_test' => 'OOPT',
            'oxford_online_placement_test_oopt' => 'OOPT',
            'toefl' => 'TOEFL-ITP',
            'toefl_itp' => 'TOEFL-ITP',
        ];
    }

    private static function normalizeProductKey(string $raw): string
    {
        $h = mb_strtolower(trim($raw));
        $h = strtr($h, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            '+' => ' + ',
        ]);
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;

        return trim($h, '_');
    }

    /** @return array{0:?int,1:?int} product_id, supplier_id */
    private function matchProduct(?string $code, ?string $name): array
    {
        $resolved = self::resolveHistoricalCode($code, $name);
        if ($resolved !== null) {
            $code = $resolved;
        }
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

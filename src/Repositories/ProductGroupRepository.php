<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class ProductGroupRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT pg.*, s.name AS supplier_name, s.code AS supplier_code
             FROM product_groups pg
             LEFT JOIN suppliers s ON s.id = pg.supplier_id
             ORDER BY pg.name ASC'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pg.*, s.name AS supplier_name, s.code AS supplier_code
             FROM product_groups pg
             LEFT JOIN suppliers s ON s.id = pg.supplier_id
             WHERE pg.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_groups WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array{name:string,code:string,supplier_id?:?int,config_json?:?string} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_groups (supplier_id, name, code, config_json) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['supplier_id'] ?? null,
            $data['name'],
            $data['code'],
            $data['config_json'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{name?:string,supplier_id?:?int,config_json?:?string} $data
     */
    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = [];
        foreach ($data as $k => $_) {
            $sets[] = "{$k} = :{$k}";
        }
        $sql = 'UPDATE product_groups SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Crea o actualiza un grupo por código.
     *
     * @param array{name:string,code:string,supplier_id?:?int,config_json?:?string} $data
     */
    public function upsertByCode(array $data): int
    {
        $existing = $this->findByCode($data['code']);
        if ($existing) {
            $this->update((int) $existing['id'], [
                'name' => $data['name'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'config_json' => $data['config_json'] ?? null,
            ]);

            return (int) $existing['id'];
        }

        return $this->create($data);
    }

    public function countProducts(int $groupId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE product_group_id = ?');
        $stmt->execute([$groupId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Config base de cobro (misma oferta que ELeT) para grupos nuevos.
     *
     * @return array<string, mixed>
     */
    public static function defaultCheckoutConfig(bool $enableMsi = true): array
    {
        $fields = ['email', 'first_name', 'last_name_p', 'last_name_m', 'phone'];
        $payments = [
            'default_method' => 'transfer_proof',
            'order' => ['transfer_proof', 'openpay_store', 'openpay_card'],
            'price_includes_fee' => false,
        ];
        $msi = $enableMsi
            ? ['enabled' => true, 'months' => [1, 3, 6, 9, 12], 'min_amount' => 0]
            : ['enabled' => false, 'months' => [1], 'min_amount' => 0];

        return [
            'checkout_fields' => $fields,
            'required_docs' => [],
            'registration_docs' => $enableMsi ? [
                [
                    'code' => 'reglamento',
                    'label' => 'Reglamento firmado (PDF)',
                    'required' => true,
                    'accept' => '.pdf',
                ],
                [
                    'code' => 'signature',
                    'label' => 'Firma (imagen)',
                    'required' => true,
                    'accept' => '.jpg,.jpeg,.png',
                ],
            ] : [],
            'payments' => $payments,
            'card_msi' => $msi,
        ];
    }
}

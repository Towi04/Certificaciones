<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class MailTemplateRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function all(?int $limit = null, ?int $offset = null): array
    {
        $sql = 'SELECT * FROM mail_templates ORDER BY name ASC, code ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) ($offset ?? 0));
        }
        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM mail_templates')->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mail_templates WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsert(
        string $code,
        string $name,
        string $subject,
        string $bodyHtml,
        string $triggerMode = 'automatic',
        bool $isActive = true
    ): void {
        $stmt = $this->pdo->prepare('SELECT id FROM mail_templates WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $this->pdo->prepare(
                'UPDATE mail_templates SET name = ?, subject = ?, body_html = ?, trigger_mode = ?, is_active = ? WHERE id = ?'
            )->execute([$name, $subject, $bodyHtml, $triggerMode, $isActive ? 1 : 0, (int) $id]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO mail_templates (code, name, subject, body_html, trigger_mode, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$code, $name, $subject, $bodyHtml, $triggerMode, $isActive ? 1 : 0]);
        }
    }

    /**
     * @param list<string> $requiredFields
     */
    public function create(
        string $code,
        string $name,
        string $subject,
        string $bodyHtml,
        string $triggerMode,
        bool $isActive,
        array $requiredFields
    ): void {
        $fieldsJson = $requiredFields !== [] ? json_encode(array_values($requiredFields), JSON_UNESCAPED_UNICODE) : null;
        $this->pdo->prepare(
            'INSERT INTO mail_templates (code, name, subject, body_html, trigger_mode, is_active, required_fields_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$code, $name, $subject, $bodyHtml, $triggerMode, $isActive ? 1 : 0, $fieldsJson]);
    }

    /**
     * @param list<string>|null $requiredFields
     */
    public function update(
        string $code,
        string $subject,
        string $bodyHtml,
        bool $isActive,
        ?array $requiredFields = null
    ): void {
        if ($requiredFields === null) {
            $this->pdo->prepare(
                'UPDATE mail_templates SET subject = ?, body_html = ?, is_active = ? WHERE code = ?'
            )->execute([$subject, $bodyHtml, $isActive ? 1 : 0, $code]);

            return;
        }

        $fieldsJson = $requiredFields !== [] ? json_encode(array_values($requiredFields), JSON_UNESCAPED_UNICODE) : null;
        $this->pdo->prepare(
            'UPDATE mail_templates SET subject = ?, body_html = ?, is_active = ?, required_fields_json = ? WHERE code = ?'
        )->execute([$subject, $bodyHtml, $isActive ? 1 : 0, $fieldsJson, $code]);
    }

    public function renameCode(string $fromCode, string $toCode, string $newName): void
    {
        $this->pdo->prepare(
            'UPDATE mail_templates SET code = ?, name = ? WHERE code = ?'
        )->execute([$toCode, $newName, $fromCode]);
    }
}

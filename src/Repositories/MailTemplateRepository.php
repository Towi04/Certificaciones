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
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM mail_templates ORDER BY name ASC, code ASC'
        );

        return $stmt->fetchAll();
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

    public function update(
        string $code,
        string $subject,
        string $bodyHtml,
        bool $isActive
    ): void {
        $this->pdo->prepare(
            'UPDATE mail_templates SET subject = ?, body_html = ?, is_active = ? WHERE code = ?'
        )->execute([$subject, $bodyHtml, $isActive ? 1 : 0, $code]);
    }
}

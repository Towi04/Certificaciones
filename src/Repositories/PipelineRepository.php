<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;

final class PipelineRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function allTemplates(): array
    {
        return $this->pdo->query(
            'SELECT id, code, name, product_type
             FROM pipeline_templates
             ORDER BY product_type ASC, name ASC'
        )->fetchAll() ?: [];
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, product_type FROM pipeline_templates WHERE code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, product_type FROM pipeline_templates WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function stepsForTemplate(int $templateId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pipeline_template_id, code, label, actor, sort_order, is_terminal
             FROM pipeline_steps
             WHERE pipeline_template_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$templateId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, list<array<string, mixed>>> keyed by template code
     */
    public function stepsByTemplateCode(): array
    {
        $out = [];
        foreach ($this->allTemplates() as $tpl) {
            $code = (string) $tpl['code'];
            $out[$code] = $this->stepsForTemplate((int) $tpl['id']);
        }

        return $out;
    }

    /**
     * Reemplaza los pasos de una plantilla.
     *
     * @param list<array{code:string,label:string,actor?:string,is_terminal?:bool|int}> $steps
     */
    public function replaceSteps(int $templateId, array $steps): void
    {
        if ($this->find($templateId) === null) {
            throw new \InvalidArgumentException('Plantilla de progreso no encontrada.');
        }
        if ($steps === []) {
            throw new \InvalidArgumentException('El progreso debe tener al menos un paso.');
        }

        $normalized = [];
        $seen = [];
        foreach ($steps as $i => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $code = strtolower(trim((string) ($raw['code'] ?? '')));
            $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
            $code = trim($code, '_');
            $label = trim((string) ($raw['label'] ?? ''));
            if ($code === '' || $label === '') {
                continue;
            }
            if (isset($seen[$code])) {
                throw new \InvalidArgumentException('Código de paso duplicado: ' . $code);
            }
            $seen[$code] = true;
            $actor = (string) ($raw['actor'] ?? 'admin');
            if (!in_array($actor, ['system', 'admin', 'student', 'partner', 'provider'], true)) {
                $actor = 'admin';
            }
            $normalized[] = [
                'code' => $code,
                'label' => $label,
                'actor' => $actor,
                'sort_order' => $i,
                'is_terminal' => !empty($raw['is_terminal']) ? 1 : 0,
            ];
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('Indica código y etiqueta en cada paso del progreso.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM pipeline_steps WHERE pipeline_template_id = ?')
                ->execute([$templateId]);
            $ins = $this->pdo->prepare(
                'INSERT INTO pipeline_steps
                    (pipeline_template_id, code, label, actor, sort_order, is_terminal)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($normalized as $step) {
                $ins->execute([
                    $templateId,
                    $step['code'],
                    $step['label'],
                    $step['actor'],
                    $step['sort_order'],
                    $step['is_terminal'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

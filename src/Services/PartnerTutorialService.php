<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Tutorial por vista del portal partner (persistido en partners.tutorial_json).
 */
final class PartnerTutorialService
{
    public const VIEWS = [
        'dashboard',
        'alumnos',
        'caso',
        'registrar',
        'registrar-grupo',
        'avance',
        'perfil',
        'mi-escuela',
    ];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::get();
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM partners LIKE 'tutorial_json'");
            if ($stmt && $stmt->fetch()) {
                $done = true;

                return;
            }
            $this->pdo->exec('ALTER TABLE partners ADD COLUMN tutorial_json JSON NULL');
        } catch (\Throwable $e) {
            error_log('[Doceo] PartnerTutorialService::ensureSchema: ' . $e->getMessage());
        }
        $done = true;
    }

    /**
     * @return array{views:array<string,bool>,completed_all:bool}
     */
    public function stateForPartner(int $partnerId): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare('SELECT tutorial_json FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$partnerId]);
        $raw = $stmt->fetchColumn();
        $decoded = [];
        if (is_string($raw) && $raw !== '') {
            $tmp = json_decode($raw, true);
            $decoded = is_array($tmp) ? $tmp : [];
        }
        $views = [];
        foreach (self::VIEWS as $view) {
            $views[$view] = !empty($decoded[$view]) || !empty($decoded['views'][$view]);
        }
        $completedAll = !empty($decoded['completed_all']);
        if (!$completedAll) {
            $completedAll = !in_array(false, $views, true);
        }

        return ['views' => $views, 'completed_all' => $completedAll];
    }

    public function markViewComplete(int $partnerId, string $view): array
    {
        $view = $this->normalizeView($view);
        $state = $this->stateForPartner($partnerId);
        $state['views'][$view] = true;
        $state['completed_all'] = !in_array(false, $state['views'], true);
        $this->save($partnerId, $state);

        return $state;
    }

    public function resetAll(int $partnerId): array
    {
        $state = ['views' => [], 'completed_all' => false];
        foreach (self::VIEWS as $view) {
            $state['views'][$view] = false;
        }
        $this->save($partnerId, $state);

        return $state;
    }

    public function resetView(int $partnerId, string $view): array
    {
        $view = $this->normalizeView($view);
        $state = $this->stateForPartner($partnerId);
        $state['views'][$view] = false;
        $state['completed_all'] = false;
        $this->save($partnerId, $state);

        return $state;
    }

    public static function viewFromPath(string $path): ?string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        return match (true) {
            $path === '/partner' || $path === '/partner/' => 'dashboard',
            str_starts_with($path, '/partner/alumnos') => 'alumnos',
            str_starts_with($path, '/partner/caso/') => 'caso',
            str_starts_with($path, '/partner/registrar-grupo') => 'registrar-grupo',
            str_starts_with($path, '/partner/registrar') || str_starts_with($path, '/adquirir/') => 'registrar',
            str_starts_with($path, '/partner/avance') => 'avance',
            str_starts_with($path, '/partner/perfil') => 'perfil',
            str_starts_with($path, '/partner/mi-escuela') => 'mi-escuela',
            default => null,
        };
    }

    private function normalizeView(string $view): string
    {
        $view = trim($view);
        if (!in_array($view, self::VIEWS, true)) {
            throw new \InvalidArgumentException('Vista de tutorial no válida.');
        }

        return $view;
    }

    /** @param array{views:array<string,bool>,completed_all:bool} $state */
    private function save(int $partnerId, array $state): void
    {
        $this->ensureSchema();
        $payload = [
            'completed_all' => !empty($state['completed_all']),
        ];
        foreach (self::VIEWS as $view) {
            $payload[$view] = !empty($state['views'][$view]);
        }
        $this->pdo->prepare('UPDATE partners SET tutorial_json = ? WHERE id = ?')
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $partnerId]);
    }
}

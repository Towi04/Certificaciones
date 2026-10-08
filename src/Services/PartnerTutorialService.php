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
        'calendario',
        'notificaciones',
        'credito',
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
            str_starts_with($path, '/partner/calendario') => 'calendario',
            str_starts_with($path, '/partner/notificaciones') => 'notificaciones',
            str_starts_with($path, '/partner/credito') => 'credito',
            str_starts_with($path, '/partner/avance') => 'avance',
            str_starts_with($path, '/partner/perfil') => 'perfil',
            str_starts_with($path, '/partner/mi-escuela') => 'mi-escuela',
            default => null,
        };
    }

    /**
     * Textos del tutorial (fuente de verdad en PHP para no depender solo del JS cacheado).
     *
     * @return array<string, list<array{sel:string,title:string,body:string}>>
     */
    public static function tourSteps(): array
    {
        return [
            'dashboard' => [
                ['sel' => '[data-tour="partner-nav"]', 'title' => 'Menú partner', 'body' => 'Desde aquí navegas alumnos, registro, calendario, notificaciones, crédito, avance, perfil y tu escuela.'],
                ['sel' => '[data-tour="students-header"]', 'title' => 'Tu tablero', 'body' => 'Aquí ves el resumen de tu código, crédito y accesos rápidos.'],
                ['sel' => '[data-tour="students-payments"]', 'title' => 'Resumen', 'body' => 'Tu crédito, casos en revisión y compras pagadas. Haz clic en revisión o pagados para filtrar.'],
                ['sel' => '[data-tour="students-table"]', 'title' => 'Cartera de alumnos', 'body' => 'Abre cada caso para ver datos, códigos, correos y resultados.'],
            ],
            'alumnos' => [
                ['sel' => '[data-tour="students-header"]', 'title' => 'Alumnos', 'body' => 'Tu cartera: matrícula, examen, folio/clave y últimos correos de accesos.'],
                ['sel' => '[data-tour="students-payments"]', 'title' => 'Crédito y pagos', 'body' => 'Saldo a favor, cuántos están en revisión y cuántos ya están pagados. Sin montos totales de compra.'],
                ['sel' => '[data-tour="register-cta"]', 'title' => 'Registrar', 'body' => 'Usa este botón para inscribir un alumno con el flujo completo.'],
                ['sel' => '[data-tour="students-export"]', 'title' => 'Exportar CSV', 'body' => 'Exporta tu cartera filtrada a CSV para control escolar.'],
                ['sel' => '[data-tour="students-table"]', 'title' => 'Abrir ficha', 'body' => 'Haz clic en «Abrir» para ver el detalle del caso.'],
            ],
            'caso' => [
                ['sel' => '[data-tour="case-registration"]', 'title' => 'Datos del alumno', 'body' => 'Siempre visibles. Solo se editan antes de asignar folio y clave.'],
                ['sel' => '[data-tour="case-codes"]', 'title' => 'Códigos de acceso', 'body' => 'Cuando DOCEO asigne folio/clave (y extra), aparecerán aquí.'],
                ['sel' => '[data-tour="case-mails"]', 'title' => 'Correos al alumno', 'body' => 'Historial de correos del caso.'],
                ['sel' => '[data-tour="case-results"]', 'title' => 'Resultados', 'body' => 'Nivel, puntaje, certificado y PDF cuando existan.'],
            ],
            'registrar' => [
                ['sel' => '[data-tour="register-header"]', 'title' => 'Registrar alumno', 'body' => 'Elige un producto del catálogo partner y completa el mismo flujo de compra.'],
                ['sel' => '[data-tour="partner-nav"]', 'title' => 'Menú', 'body' => 'También puedes volver a alumnos, calendario, crédito o registro de grupo desde el menú lateral.'],
            ],
            'registrar-grupo' => [
                ['sel' => '[data-tour="bulk-header"]', 'title' => 'Registro de grupo', 'body' => 'Un producto, una fecha, CSV de alumnos y un solo comprobante.'],
                ['sel' => '[data-tour="bulk-form"], [data-tour="bulk-proof"]', 'title' => 'Pasos del lote', 'body' => 'Primero revisas el CSV; luego subes el comprobante por el monto partner × N.'],
            ],
            'calendario' => [
                ['sel' => '[data-tour="calendar-header"]', 'title' => 'Calendario', 'body' => 'Aquí ves los exámenes próximos de tus alumnos.'],
                ['sel' => '[data-tour="calendar-range"]', 'title' => 'Rango', 'body' => 'Cambia entre los próximos 30 o 60 días.'],
                ['sel' => '[data-tour="calendar-list"]', 'title' => 'Lista por fecha', 'body' => 'Agrupados por día. Abre el caso para ver detalle o códigos.'],
            ],
            'notificaciones' => [
                ['sel' => '[data-tour="notifications-header"]', 'title' => 'Centro de notificaciones', 'body' => 'Aquí ves cuando se enviaron accesos a tus alumnos y cuando hay resultados listos.'],
                ['sel' => '[data-tour="notifications-filters"]', 'title' => 'Filtros', 'body' => 'Filtra por accesos o por resultados.'],
                ['sel' => '[data-tour="notifications-list"]', 'title' => 'Lista', 'body' => 'Las nuevas se resaltan. Abre el caso para ver códigos o resultados. El aviso del menú se limpia al entrar.'],
            ],
            'credito' => [
                ['sel' => '[data-tour="credit-header"]', 'title' => 'Tu crédito', 'body' => 'Consulta el saldo a favor generado por ventas con tu código.'],
                ['sel' => '[data-tour="credit-balance"]', 'title' => 'Saldo y totales', 'body' => 'Saldo actual, abonos y usos del mes, e histórico.'],
                ['sel' => '[data-tour="credit-movements"]', 'title' => 'Movimientos', 'body' => 'Cada abono (+) o uso (−) ligado a una compra/matrícula.'],
            ],
            'avance' => [
                ['sel' => '[data-tour="progress-stats"]', 'title' => 'Tu avance', 'body' => 'Ventas del mes/convenio y crédito.'],
                ['sel' => '[data-tour="tier-progress"]', 'title' => 'Niveles', 'body' => 'Barra y metas Bronze / Silver / Gold de tu convenio.'],
            ],
            'perfil' => [
                ['sel' => '[data-tour="profile-readonly"]', 'title' => 'Nivel y crédito', 'body' => 'Estos datos son de solo lectura.'],
                ['sel' => '[data-tour="profile-share-link"]', 'title' => 'Link de captación', 'body' => 'Comparte este enlace para que tus alumnos compren con tu código.'],
                ['sel' => '[data-tour="profile-form"]', 'title' => 'Editar perfil', 'body' => 'Actualiza nombre comercial, contacto, código de descuento y contraseña.'],
                ['sel' => '[data-tour="tutorial-reset"]', 'title' => 'Reiniciar tutorial', 'body' => 'Desde aquí puedes volver a ver las burbujas de ayuda.'],
            ],
            'mi-escuela' => [
                ['sel' => '[data-tour="school-header"]', 'title' => 'Directorio', 'body' => 'Publica tu escuela como distribuidor autorizado (con aprobación de DOCEO).'],
                ['sel' => '[data-tour="school-form"]', 'title' => 'Borrador', 'body' => 'Guarda y envía a revisión. Los cambios nuevos no se publican hasta aprobarse.'],
            ],
        ];
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

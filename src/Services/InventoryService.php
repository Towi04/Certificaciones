<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Integrations\Mailer;
use App\Repositories\InventoryRepository;
use App\Repositories\ProductRepository;
use App\Support\Settings;

/**
 * Inventario de folios/claves (p. ej. iTEP): compra por lotes, asignación automática,
 * reasignación urgente y alerta de stock bajo.
 */
final class InventoryService
{
    private InventoryRepository $repo;
    private TrackingService $tracking;
    private ProductRepository $products;

    public function __construct()
    {
        $this->repo = new InventoryRepository();
        $this->tracking = new TrackingService();
        $this->products = new ProductRepository();
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function configForProduct(array $product): array
    {
        $cfg = CheckoutRequirements::config($product);
        $inv = is_array($cfg['inventory'] ?? null) ? $cfg['inventory'] : [];

        return [
            'enabled' => !empty($inv['enabled']),
            'assign_within_days' => max(0, (int) ($inv['assign_within_days'] ?? 3)),
            'send_access_days_before' => max(0, (int) ($inv['send_access_days_before'] ?? 3)),
            'low_stock_threshold' => max(0, (int) ($inv['low_stock_threshold'] ?? 5)),
            'student_validity_months' => max(1, (int) ($inv['student_validity_months'] ?? 6)),
            'provider_validity_months' => max(1, (int) ($inv['provider_validity_months'] ?? 12)),
            'reallocate_enabled' => array_key_exists('reallocate_enabled', $inv)
                ? !empty($inv['reallocate_enabled'])
                : true,
            'reallocate_min_future_days' => max(1, (int) ($inv['reallocate_min_future_days'] ?? 14)),
            'access_mail_template' => trim((string) ($inv['access_mail_template'] ?? 'student_inventory_exam_access'))
                ?: 'student_inventory_exam_access',
            'results_mail_template' => trim((string) ($inv['results_mail_template'] ?? 'student_results_cenni'))
                ?: 'student_results_cenni',
            'low_stock_notify_email' => trim((string) ($inv['low_stock_notify_email'] ?? '')),
        ];
    }

    public static function isEnabledForProduct(array $product): bool
    {
        return !empty(self::configForProduct($product)['enabled']);
    }

    /**
     * @param list<array{folio:string,clave?:string,extra?:string}> $rows
     * @return array{lot_id:int,imported:int,skipped:int,errors:list<string>}
     */
    public function importLot(
        int $productId,
        array $rows,
        string $label,
        ?string $purchasedAt,
        ?float $costTotal,
        int $lowStockThreshold,
        ?int $actorUserId = null
    ): array {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }
        $cfg = self::configForProduct($product);
        $providerMonths = (int) $cfg['provider_validity_months'];
        $expiresAt = null;
        if ($purchasedAt) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $purchasedAt);
            if ($dt) {
                $expiresAt = $dt->modify('+' . $providerMonths . ' months')->format('Y-m-d');
            }
        }

        $lotId = $this->repo->createLot([
            'product_id' => $productId,
            'label' => $label !== '' ? $label : ('Lote ' . date('Y-m-d H:i')),
            'purchased_at' => $purchasedAt,
            'cost_total' => $costTotal,
            'low_stock_threshold' => $lowStockThreshold > 0 ? $lowStockThreshold : (int) $cfg['low_stock_threshold'],
        ]);

        $imported = 0;
        $skipped = 0;
        $errors = [];
        foreach ($rows as $i => $row) {
            $folio = trim((string) ($row['folio'] ?? $row['code_primary'] ?? ''));
            $clave = trim((string) ($row['clave'] ?? $row['code_secondary'] ?? ''));
            $extra = trim((string) ($row['extra'] ?? $row['code_extra'] ?? ''));
            if ($folio === '') {
                $skipped++;
                continue;
            }
            try {
                $this->repo->insertCode([
                    'lot_id' => $lotId,
                    'product_id' => $productId,
                    'code_primary' => $folio,
                    'code_secondary' => $clave !== '' ? $clave : null,
                    'code_extra' => $extra !== '' ? $extra : null,
                    'status' => 'available',
                    'expires_at' => $expiresAt,
                    'meta_json' => ['imported_by' => $actorUserId, 'line' => $i + 1],
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = 'Línea ' . ($i + 1) . ' (' . $folio . '): ' . $e->getMessage();
            }
        }

        // Reasignar a alumnos que esperaban reposición.
        $this->restockPending($productId, $actorUserId);
        $this->maybeAlertLowStock($productId);

        return [
            'lot_id' => $lotId,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Tras pago / cambio de fecha: asigna ya o programa envío N días antes.
     *
     * @return array{action:string,detail?:string}
     */
    public function processTracking(int $trackingId, ?int $actorUserId = null): array
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return ['action' => 'skip', 'detail' => 'tracking_missing'];
        }
        $product = [
            'id' => $tracking['product_id'] ?? 0,
            'config_json' => $tracking['config_json'] ?? null,
            'group_config_json' => $tracking['group_config_json'] ?? null,
            'type' => $tracking['product_type'] ?? ($tracking['type'] ?? ''),
            'code' => $tracking['product_code'] ?? '',
            'name' => $tracking['product_name'] ?? '',
        ];
        // Recargar producto completo si hace falta.
        $full = $this->products->find((int) ($tracking['product_id'] ?? 0));
        if ($full !== null) {
            $product = $full + [
                'group_config_json' => $tracking['group_config_json'] ?? ($full['group_config_json'] ?? null),
            ];
        }
        $cfg = self::configForProduct($product);
        if (!$cfg['enabled']) {
            return ['action' => 'skip', 'detail' => 'inventory_disabled'];
        }

        $purchaseStatus = (string) ($tracking['purchase_status'] ?? '');
        if ($purchaseStatus !== '' && $purchaseStatus !== 'paid') {
            return ['action' => 'skip', 'detail' => 'not_paid'];
        }

        $examDate = trim((string) ($tracking['exam_date'] ?? ''));
        if ($examDate === '') {
            return ['action' => 'skip', 'detail' => 'no_exam_date'];
        }

        $daysBefore = (int) $cfg['send_access_days_before'];
        $urgentWithin = (int) $cfg['assign_within_days'];
        $today = new \DateTimeImmutable('today');
        $exam = \DateTimeImmutable::createFromFormat('Y-m-d', $examDate);
        if (!$exam) {
            return ['action' => 'skip', 'detail' => 'bad_exam_date'];
        }
        $daysUntil = (int) $today->diff($exam)->format('%r%a');
        // Reasignar stock de fechas lejanas solo si ESTE examen es urgente.
        $allowSteal = $daysUntil <= $urgentWithin;

        $hasAccess = trim((string) ($tracking['folio'] ?? '')) !== ''
            && trim((string) ($tracking['access_key'] ?? '')) !== '';

        // Reservar folio/clave desde el pago (aunque el examen sea en meses).
        // Así hay stock “asignado” que se puede reasignar si llega una urgencia.
        if (!$hasAccess) {
            $assigned = $this->assignToTracking($trackingId, $actorUserId, $allowSteal);
            if (!$assigned['ok']) {
                // Sin stock: reintentar N días antes (por si llega un lote).
                ScheduledMailService::schedule(
                    $trackingId,
                    'inventory_assign_and_mail',
                    $exam->modify('-' . $daysBefore . ' days')->format('Y-m-d') . ' 09:00:00',
                    ['template' => $cfg['access_mail_template']]
                );

                return [
                    'action' => 'assign_failed_scheduled_retry',
                    'detail' => $assigned['error'] ?? 'unknown',
                ];
            }
            $hasAccess = true;
            $tracking = $this->tracking->find($trackingId) ?? $tracking;
            $reallocated = !empty($assigned['reallocated']);
        } else {
            $reallocated = false;
        }

        if ($daysUntil <= $daysBefore) {
            ScheduledMailService::cancelPending($trackingId, 'inventory_assign_and_mail');
            ScheduledMailService::cancelPending($trackingId, $cfg['access_mail_template']);
            $this->sendAccessMail($trackingId, $actorUserId, $cfg['access_mail_template']);

            return [
                'action' => 'assigned_and_mailed',
                'detail' => $reallocated ? 'reallocated' : 'from_stock',
            ];
        }

        // Ya reservado: solo programar envío N días antes (reusa processTracking).
        ScheduledMailService::schedule(
            $trackingId,
            'inventory_assign_and_mail',
            $exam->modify('-' . $daysBefore . ' days')->format('Y-m-d') . ' 09:00:00',
            ['template' => $cfg['access_mail_template']]
        );

        return [
            'action' => 'assigned_mail_scheduled',
            'detail' => $reallocated ? 'reallocated' : 'from_stock',
        ];
    }

    /**
     * @return array{ok:bool,error?:string,code_id?:int,reallocated?:bool,stolen_from?:?int}
     */
    public function assignToTracking(int $trackingId, ?int $actorUserId = null, bool $allowReallocate = true): array
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return ['ok' => false, 'error' => 'Seguimiento no encontrado.'];
        }
        $productId = (int) ($tracking['product_id'] ?? 0);
        $product = $this->products->find($productId);
        if ($product === null) {
            return ['ok' => false, 'error' => 'Producto no encontrado.'];
        }
        $cfg = self::configForProduct($product + [
            'group_config_json' => $tracking['group_config_json'] ?? null,
        ]);

        if (trim((string) ($tracking['folio'] ?? '')) !== '' && trim((string) ($tracking['access_key'] ?? '')) !== '') {
            return ['ok' => true, 'reallocated' => false];
        }

        $this->repo->beginTransaction();
        try {
            $code = $this->repo->findAvailableCode($productId);
            $reallocated = false;
            $stolenFrom = null;

            if ($code === null && $allowReallocate && !empty($cfg['reallocate_enabled'])) {
                $candidates = $this->repo->stealCandidates(
                    $productId,
                    (int) $cfg['reallocate_min_future_days'],
                    5
                );
                if ($candidates !== []) {
                    $victim = $candidates[0];
                    $stolenFrom = (int) ($victim['tracking_id'] ?? 0);
                    $this->releaseFromTracking(
                        $stolenFrom,
                        (int) $victim['id'],
                        'Código reasignado a un examen más urgente (stock bajo)',
                        $actorUserId,
                        true
                    );
                    $code = $this->repo->findAvailableCode($productId);
                    $reallocated = $code !== null;
                }
            }

            if ($code === null) {
                $this->repo->rollBack();
                $this->maybeAlertLowStock($productId);

                return ['ok' => false, 'error' => 'No hay códigos disponibles en inventario.'];
            }

            $studentMonths = (int) $cfg['student_validity_months'];
            $expiresAt = (new \DateTimeImmutable('today'))
                ->modify('+' . $studentMonths . ' months')
                ->format('Y-m-d');

            $this->repo->markAssigned((int) $code['id'], $trackingId, $expiresAt);
            $this->repo->pdo()->prepare(
                'UPDATE trackings SET folio = ?, access_key = ? WHERE id = ?'
            )->execute([
                (string) $code['code_primary'],
                (string) ($code['code_secondary'] ?? ''),
                $trackingId,
            ]);

            $this->mergeInventoryMeta($trackingId, [
                'code_id' => (int) $code['id'],
                'lot_id' => (int) ($code['lot_id'] ?? 0),
                'assigned_at' => date('c'),
                'expires_at' => $expiresAt,
                'needs_restock' => false,
                'reallocated_from_tracking_id' => $stolenFrom,
            ]);

            $this->tracking->addLog(
                $trackingId,
                'codigos',
                'Inventario: folio/clave asignados'
                    . ($reallocated ? ' (reasignado desde caso #' . $stolenFrom . ')' : ''),
                $actorUserId
            );

            $this->repo->commit();
            $this->maybeAlertLowStock($productId);

            return [
                'ok' => true,
                'code_id' => (int) $code['id'],
                'reallocated' => $reallocated,
                'stolen_from' => $stolenFrom,
            ];
        } catch (\Throwable $e) {
            $this->repo->rollBack();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function releaseFromTracking(
        int $trackingId,
        int $codeId,
        string $reason,
        ?int $actorUserId = null,
        bool $needsRestock = true
    ): void {
        $tracking = $this->tracking->find($trackingId);
        $productId = (int) ($tracking['product_id'] ?? 0);
        $accessTpl = 'student_inventory_exam_access';
        if ($productId > 0) {
            $product = $this->products->find($productId);
            if ($product !== null) {
                $accessTpl = (string) self::configForProduct($product + [
                    'group_config_json' => $tracking['group_config_json'] ?? null,
                ])['access_mail_template'];
            }
        }

        $this->repo->markAvailable($codeId, [
            'last_released_from' => $trackingId,
            'last_released_at' => date('c'),
            'last_release_reason' => $reason,
        ]);
        $this->repo->pdo()->prepare(
            'UPDATE trackings SET folio = NULL, access_key = NULL WHERE id = ?'
        )->execute([$trackingId]);
        $this->mergeInventoryMeta($trackingId, [
            'needs_restock' => $needsRestock,
            'released_at' => date('c'),
            'release_reason' => $reason,
            'code_id' => null,
            'access_mail_sent_at' => null,
        ]);
        ScheduledMailService::cancelPending($trackingId, 'inventory_assign_and_mail');
        ScheduledMailService::cancelPending($trackingId, $accessTpl);
        $this->tracking->addLog($trackingId, 'codigos', 'Inventario: ' . $reason, $actorUserId);
    }

    public function restockPending(int $productId, ?int $actorUserId = null): int
    {
        $pending = $this->repo->pendingRestockTrackings($productId);
        $n = 0;
        foreach ($pending as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            $result = $this->assignToTracking($tid, $actorUserId, false);
            if (!empty($result['ok'])) {
                $n++;
                // Reprogramar / enviar según fecha.
                $this->processTracking($tid, $actorUserId);
            }
        }

        return $n;
    }

    public function maybeAlertLowStock(int $productId): void
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return;
        }
        $cfg = self::configForProduct($product);
        $counts = $this->repo->stockCounts($productId);
        $threshold = (int) $cfg['low_stock_threshold'];
        // Prefer lot threshold if any lot is tighter — use config default for now.
        if ($counts['available'] > $threshold) {
            return;
        }

        $to = $cfg['low_stock_notify_email'];
        if ($to === '') {
            $to = trim((string) (Env::get('SMTP_FROM', '') ?? ''));
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $key = 'inventory_low_stock_alert_' . $productId;
        $last = Settings::get($key, '');
        if ($last !== null && $last !== '' && (time() - (int) $last) < 86400) {
            return; // 1 alerta / día
        }

        $body = '<p>Stock bajo de códigos de inventario.</p>'
            . '<p><strong>Producto:</strong> ' . htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES, 'UTF-8')
            . ' (' . htmlspecialchars((string) ($product['code'] ?? ''), ENT_QUOTES, 'UTF-8') . ')</p>'
            . '<p><strong>Disponibles:</strong> ' . (int) $counts['available']
            . ' · umbral: ' . $threshold . '</p>'
            . '<p>Sube un nuevo lote en Admin → Inventario.</p>';

        try {
            (new Mailer())->send(
                $to,
                'Stock bajo · ' . (string) ($product['code'] ?? 'inventario'),
                strip_tags($body),
                ['html' => true, 'body_html' => \App\Mail\MailBranding::wrap($body)]
            );
            Settings::set($key, (string) time());
        } catch (\Throwable $e) {
            error_log('[Doceo] low stock mail: ' . $e->getMessage());
        }
    }

    private function sendAccessMail(int $trackingId, ?int $actorUserId, string $templateCode): void
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return;
        }
        $extra = $this->decodeExtra($tracking);
        $inv = is_array($extra['inventory'] ?? null) ? $extra['inventory'] : [];
        if (!empty($inv['access_mail_sent_at'])) {
            return;
        }

        try {
            $mail = new MailTemplateService();
            $vars = (new StepMailService())->buildVars($tracking);
            $to = trim((string) ($tracking['student_email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Sin correo de alumno.');
            }
            if ($mail->render($templateCode, $vars) === null) {
                // Fallback a plantilla ELeT de accesos si existe.
                $templateCode = 'student_elet_exam_access';
                if ($mail->render($templateCode, $vars) === null) {
                    throw new \RuntimeException('Plantilla de accesos no encontrada.');
                }
            }
            $mail->send($templateCode, $to, $vars);
            $this->mergeInventoryMeta($trackingId, [
                'access_mail_sent_at' => date('c'),
                'access_mail_template' => $templateCode,
            ]);
            $this->tracking->addLog(
                $trackingId,
                'codigos',
                'Correo de acceso inventario enviado («' . $templateCode . '» → ' . $to . ')',
                $actorUserId
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] inventory access mail: ' . $e->getMessage());
            $this->tracking->addLog(
                $trackingId,
                'codigos',
                'No se pudo enviar correo de acceso: ' . $e->getMessage(),
                $actorUserId
            );
        }
    }

    /** Correo de resultados + info CENNI tras cargar resultados el admin. */
    public function sendResultsMail(int $trackingId, ?int $actorUserId = null): void
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return;
        }
        $product = $this->products->find((int) ($tracking['product_id'] ?? 0));
        if ($product === null) {
            return;
        }
        $cfg = self::configForProduct($product + [
            'group_config_json' => $tracking['group_config_json'] ?? null,
        ]);
        $templateCode = (string) $cfg['results_mail_template'];
        $extra = $this->decodeExtra($tracking);
        $inv = is_array($extra['inventory'] ?? null) ? $extra['inventory'] : [];
        $fingerprint = md5(implode('|', [
            (string) ($tracking['results_level'] ?? ''),
            (string) ($tracking['results_score'] ?? ''),
            (string) ($tracking['results_url'] ?? ''),
            (string) ($tracking['cenni_folio'] ?? ''),
        ]));
        if (($inv['results_mail_fingerprint'] ?? '') === $fingerprint) {
            return;
        }

        try {
            $mail = new MailTemplateService();
            $vars = (new StepMailService())->buildVars($tracking);
            $to = trim((string) ($tracking['student_email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Sin correo de alumno.');
            }
            if ($mail->render($templateCode, $vars) === null) {
                throw new \RuntimeException('Plantilla no encontrada: ' . $templateCode);
            }
            $mail->send($templateCode, $to, $vars);
            $this->mergeInventoryMeta($trackingId, [
                'results_mail_sent_at' => date('c'),
                'results_mail_template' => $templateCode,
                'results_mail_fingerprint' => $fingerprint,
            ]);
            $this->tracking->addLog(
                $trackingId,
                'resultados',
                'Correo resultados/CENNI enviado («' . $templateCode . '» → ' . $to . ')',
                $actorUserId
            );
        } catch (\Throwable $e) {
            error_log('[Doceo] inventory results mail: ' . $e->getMessage());
            $this->tracking->addLog(
                $trackingId,
                'resultados',
                'No se pudo enviar correo de resultados: ' . $e->getMessage(),
                $actorUserId
            );
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function mergeInventoryMeta(int $trackingId, array $meta): void
    {
        $tracking = $this->tracking->find($trackingId);
        if ($tracking === null) {
            return;
        }
        $extra = $this->decodeExtra($tracking);
        $current = is_array($extra['inventory'] ?? null) ? $extra['inventory'] : [];
        $extra['inventory'] = array_merge($current, $meta);
        $this->repo->pdo()->prepare('UPDATE trackings SET extra_json = ? WHERE id = ?')
            ->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), $trackingId]);
    }

    /** @param array<string, mixed> $tracking */
    private function decodeExtra(array $tracking): array
    {
        if (!empty($tracking['extra_json']) && is_string($tracking['extra_json'])) {
            $decoded = json_decode($tracking['extra_json'], true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($tracking['extra_json'] ?? null)) {
            return $tracking['extra_json'];
        }

        return [];
    }

    /**
     * Parsea CSV/texto: folio,clave[,extra]
     *
     * @return list<array{folio:string,clave:string,extra:string}>
     */
    public static function parseCodesText(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(folio|code_primary)\b/i', $line)) {
                continue; // header
            }
            $parts = str_getcsv($line);
            if (count($parts) < 2) {
                $parts = preg_split('/[\t|;]+/', $line) ?: [];
            }
            $folio = trim((string) ($parts[0] ?? ''));
            $clave = trim((string) ($parts[1] ?? ''));
            $extra = trim((string) ($parts[2] ?? ''));
            if ($folio === '') {
                continue;
            }
            $out[] = ['folio' => $folio, 'clave' => $clave, 'extra' => $extra];
        }

        return $out;
    }
}

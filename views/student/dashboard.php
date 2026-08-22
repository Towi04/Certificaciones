<?php
/** @var list<array<string,mixed>> $trackings */
$uksElet = new \App\Services\UksEletService();
$stepLabels = [
    'registro' => 'Registro',
    'confirm_pago' => 'Confirmación de pago',
    'solicitud_uks' => 'Solicitud a UKS',
    'codigos' => 'Accesos al examen',
    'resultados' => 'Resultados',
    'fin' => 'Completado',
];
$statusLabels = [
    'open' => 'En proceso',
    'waiting_admin' => 'En revisión DOCEO',
    'waiting_student' => 'Acción pendiente tuya',
    'waiting_partner' => 'Espera partner',
    'waiting_provider' => 'En proceso con UKS',
    'completed' => 'Completado',
    'cancelled' => 'Cancelado',
];
$payLabels = [
    'awaiting_payment' => 'Esperando pago',
    'payment_review' => 'Comprobante en revisión',
    'paid' => 'Pagado',
];
?>
<h1 style="margin-top:0;color:var(--doceo-blue)">Hola, <?= e(\App\Auth\Auth::user()['first_name'] ?? 'alumno') ?></h1>
<p class="muted">Aquí verás el estatus de cada compra y las acciones pendientes (docs, pago, resultados, CENNI…).</p>
<div class="panel">
    <h2>Mis seguimientos</h2>
    <?php if ($trackings === []): ?>
        <div class="empty">Aún no tienes compras. <a href="<?= e(url('/catalogo')) ?>">Explora el catálogo</a>.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Matrícula</th><th>Producto</th><th>Pago</th><th>Seguimiento</th><th>Paso</th><th>Examen</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($trackings as $t): ?>
                    <?php
                    $portalLabels = $uksElet->studentPortalLabels($t, $stepLabels, $statusLabels);
                    $stepLabel = $portalLabels['step'];
                    $statusLabel = $portalLabels['status'];
                    $payKey = (string) ($t['purchase_status'] ?? '');
                    ?>
                    <tr>
                        <td><?= e($t['matricula']) ?></td>
                        <td><?= e($t['product_name']) ?></td>
                        <td><span class="pill"><?= e($payLabels[$payKey] ?? $payKey) ?></span></td>
                        <td><span class="pill"><?= e($statusLabel) ?></span></td>
                        <td><?= e($stepLabel) ?></td>
                        <td><?= e($t['exam_date'] ?? '—') ?></td>
                        <td><a href="<?= e(url('/alumno/caso/' . $t['id'])) ?>">Abrir caso</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

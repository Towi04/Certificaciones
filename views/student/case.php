<?php
/** @var array<string,mixed> $tracking */
/** @var list<array<string,mixed>> $steps */
/** @var list<array<string,mixed>> $documents */
/** @var list<array<string,mixed>> $logs */
$current = (string) ($tracking['current_step_code'] ?? '');
$statusLabels = [
    'open' => 'En proceso',
    'waiting_admin' => 'En revisión DOCEO',
    'waiting_student' => 'Acción pendiente tuya',
    'waiting_partner' => 'Espera partner',
    'waiting_provider' => 'En proceso con proveedor',
    'completed' => 'Completado',
    'cancelled' => 'Cancelado',
];
$payLabels = [
    'awaiting_payment' => 'Esperando pago',
    'payment_review' => 'Comprobante en revisión',
    'paid' => 'Pagado',
];
?>
<p class="meta"><a href="<?= e(url('/alumno')) ?>">← Mi panel</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($tracking['product_name']) ?></h1>
<p>
    Matrícula <strong><?= e($tracking['matricula']) ?></strong>
    · <span class="pill"><?= e($statusLabels[$tracking['status']] ?? $tracking['status']) ?></span>
</p>
<p class="muted">
    Pago: <?= e($payLabels[$tracking['purchase_status']] ?? $tracking['purchase_status']) ?>
    · <?= money($tracking['charged_amount']) ?>
    · <a href="<?= e(url('/compra/' . $tracking['matricula'])) ?>">Ver ficha de pago</a>
</p>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Progreso</h2>
    <?php if ($steps === []): ?>
        <p class="muted">Paso actual: <?= e($current !== '' ? $current : '—') ?></p>
    <?php else: ?>
        <ol style="margin:0;padding-left:1.2rem">
            <?php foreach ($steps as $s): ?>
                <?php
                $codes = array_column($steps, 'code');
                $curIdx = array_search($current, $codes, true);
                $thisIdx = array_search((string) $s['code'], $codes, true);
                $done = is_int($curIdx) && is_int($thisIdx) && $thisIdx < $curIdx;
                $active = (string) $s['code'] === $current;
                ?>
                <li style="<?= $active ? 'font-weight:700;color:var(--doceo-blue)' : ($done ? 'opacity:.65' : '') ?>">
                    <?= e($s['label']) ?><?php if ($active): ?> ← aquí vas<?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>

<?php if (!empty($tracking['exam_date'])): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Fecha de examen</h2>
    <p style="margin-top:0">
        <strong><?= e((string) $tracking['exam_date']) ?></strong>
        <?php if (!empty($tracking['exam_time'])): ?>
            a las <?= e(substr((string) $tracking['exam_time'], 0, 5)) ?>
        <?php endif; ?>
    </p>
    <?php if (!empty($tracking['exam_date_2'])): ?>
        <p class="muted">
            Alternativa:
            <?= e((string) $tracking['exam_date_2']) ?>
            <?php if (!empty($tracking['exam_time_2'])): ?>
                <?= e(substr((string) $tracking['exam_time_2'], 0, 5)) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if (!empty($tracking['zoom_url'])): ?>
        <p><a class="btn btn-accent" href="<?= e((string) $tracking['zoom_url']) ?>" target="_blank" rel="noopener">Abrir enlace de reunión</a></p>
    <?php else: ?>
        <p class="muted">El enlace de Zoom se publicará aquí cuando esté listo.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($tracking['moodle_username'])): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Acceso Campus DOCEO</h2>
    <p>
        Entra a <a href="https://campus.institutodoceo.com" target="_blank" rel="noopener">campus.institutodoceo.com</a>
    </p>
    <p>
        Usuario: <strong><?= e($tracking['moodle_username']) ?></strong>
        <?php if (!empty($tracking['moodle_password'])): ?>
            <br>Contraseña temporal: <strong><?= e($tracking['moodle_password']) ?></strong>
        <?php endif; ?>
    </p>
    <?php if (!empty($tracking['moodle_access_ends_at'])): ?>
        <p class="muted" style="font-size:.85rem">Vigencia hasta <?= e($tracking['moodle_access_ends_at']) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Documentos</h2>
    <?php if ($documents === []): ?>
        <p class="muted">Este producto no requiere documentos por ahora. Si más adelante hace falta reglamento o firma, te lo pediremos aquí.</p>
    <?php else: ?>
        <?php foreach ($documents as $d): ?>
            <div style="padding:.75rem 0;border-bottom:1px solid #e6ebf2">
                <strong><?= e($d['doc_type']) ?></strong>
                · <span class="pill"><?= e($d['status']) ?></span>
                · <a href="<?= e(url('/alumno/documentos/' . $d['id'] . '/ver')) ?>" target="_blank" rel="noopener"><?= e($d['original_name']) ?></a>
                <?php if ($d['status'] === 'rejected'): ?>
                    <p class="muted" style="margin:.4rem 0">Motivo: <?= e($d['rejection_reason'] ?? '') ?></p>
                    <form method="post" action="<?= e(url('/alumno/documentos/' . $d['id'] . '/reenviar')) ?>" enctype="multipart/form-data" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                        <?= csrf_field() ?>
                        <input type="file" name="document" required accept="<?= e($d['doc_type'] === 'ine' ? '.pdf' : '.pdf,.jpg,.jpeg,.png') ?>">
                        <button class="btn btn-accent btn-sm" type="submit">Subir de nuevo</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($logs !== []): ?>
    <div class="panel" style="margin-top:1rem">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Actividad reciente</h2>
        <ul style="margin:0;padding-left:1.1rem">
            <?php foreach (array_slice($logs, 0, 8) as $l): ?>
                <li>
                    <?= e($l['note'] ?: $l['step_code']) ?>
                    <span class="muted" style="font-size:.82rem">· <?= e($l['created_at']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

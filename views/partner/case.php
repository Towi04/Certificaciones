<?php
/** @var array<string,mixed> $tracking */
/** @var list<array<string,mixed>> $steps */
$current = (string) ($tracking['current_step_code'] ?? '');
?>
<p class="meta"><a href="<?= e(url('/partner')) ?>">← Mis alumnos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($tracking['product_name']) ?></h1>
<p>
    Matrícula <strong><?= e($tracking['matricula']) ?></strong>
    · <span class="pill"><?= e($tracking['status']) ?></span>
    · pago <?= e($tracking['purchase_status']) ?> · <?= money($tracking['charged_amount']) ?>
</p>
<?php if (in_array($tracking['purchase_status'] ?? '', ['awaiting_payment', 'payment_review'], true)): ?>
    <p class="flash flash-info" style="margin-top:.75rem">
        El comprobante está en revisión. El caso avanzará cuando administración confirme el pago.
    </p>
<?php endif; ?>
<p class="muted">
    <?= e(trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''))) ?>
    · <?= e($tracking['student_email']) ?>
    <?php if (!empty($tracking['student_phone'])): ?> · <?= e($tracking['student_phone']) ?><?php endif; ?>
</p>

<?php if ($steps !== []): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Progreso</h2>
    <?php
    $codes = array_column($steps, 'code');
    $curIdx = array_search($current, $codes, true);
    $currentIsHidden = $current !== '' && $curIdx === false;
    ?>
    <ol style="margin:0;padding-left:1.2rem">
        <?php foreach ($steps as $i => $s): ?>
            <?php $active = is_int($curIdx) && $i === $curIdx; ?>
            <li style="<?= $active ? 'font-weight:700;color:var(--doceo-blue)' : '' ?>">
                <?= e($s['label']) ?><?= $active ? ' ← actual' : '' ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if ($currentIsHidden): ?>
        <p class="muted" style="margin:.5rem 0 0;font-size:.85rem">El caso está en un paso interno de DOCEO.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Fecha de examen</h2>
    <?php if (in_array((string) ($tracking['product_type'] ?? ''), ['certification', 'procedure'], true) || !empty($tracking['exam_date'])): ?>
        <?php if (!empty($tracking['exam_date'])): ?>
            <p style="margin-top:0">
                <strong><?= e((string) $tracking['exam_date']) ?></strong>
                <?php if (!empty($tracking['exam_time'])): ?>
                    a las <?= e(substr((string) $tracking['exam_time'], 0, 5)) ?>
                <?php endif; ?>
            </p>
            <?php if (!empty($tracking['exam_date_2']) || !empty($tracking['zoom_url'])): ?>
                <p class="muted" style="font-size:.85rem;margin-top:0">
                    <?php if (!empty($tracking['exam_date_2'])): ?>
                        Reagenda (admin): <?= e((string) $tracking['exam_date_2']) ?>
                        <?php if (!empty($tracking['exam_time_2'])): ?>
                            <?= e(substr((string) $tracking['exam_time_2'], 0, 5)) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($tracking['zoom_url'])): ?>
                        <?php if (!empty($tracking['exam_date_2'])): ?> · <?php endif; ?>
                        Dato extra asignado por admin
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted" style="margin-top:0">Aún no hay fecha asignada.</p>
        <?php endif; ?>
        <p class="muted" style="font-size:.85rem;margin:.75rem 0 0">
            Para asignar o reagendar la fecha, contacta a DOCEO. Solo administración puede cambiarla.
        </p>
    <?php else: ?>
        <p class="muted" style="margin:0">Este producto no requiere fecha de examen.</p>
    <?php endif; ?>
</div>

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
<p class="muted">
    <?= e(trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''))) ?>
    · <?= e($tracking['student_email']) ?>
    <?php if (!empty($tracking['student_phone'])): ?> · <?= e($tracking['student_phone']) ?><?php endif; ?>
</p>

<?php if ($steps !== []): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Progreso</h2>
    <ol style="margin:0;padding-left:1.2rem">
        <?php foreach ($steps as $s): ?>
            <li style="<?= (string) $s['code'] === $current ? 'font-weight:700;color:var(--doceo-blue)' : '' ?>">
                <?= e($s['label']) ?><?= (string) $s['code'] === $current ? ' ← actual' : '' ?>
            </li>
        <?php endforeach; ?>
    </ol>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Fecha de examen</h2>
    <?php if (in_array((string) ($tracking['product_type'] ?? ''), ['certification', 'procedure'], true) || !empty($tracking['exam_date'])): ?>
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
                Zoom asignado por admin
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/partner/caso/' . $tracking['id'] . '/examen')) ?>">
        <?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Fecha *
                <input type="date" name="exam_date" required value="<?= e($tracking['exam_date'] ?? '') ?>" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Hora *
                <input type="time" name="exam_time" required value="<?= e(isset($tracking['exam_time']) ? substr((string) $tracking['exam_time'], 0, 5) : '') ?>" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
        </div>
        <p class="muted" style="font-size:.82rem;margin:.75rem 0 0">
            La 2ª fecha (reagenda) y el enlace Zoom los gestiona administración.
        </p>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;margin:.85rem 0;font-size:.88rem">
            <input type="checkbox" name="notify" value="1" checked> Avisar al alumno por correo
        </label>
        <button class="btn btn-accent" type="submit">Guardar fecha</button>
    </form>
    <?php else: ?>
        <p class="muted" style="margin:0">Este producto no requiere fecha de examen.</p>
    <?php endif; ?>
</div>

<?php
/** @var array<string,mixed> $config */
/** @var list<array{code:string,label:string,min_sales:int,max_sales:?int,range_label:string}> $tiers */
/** @var array<string,mixed>|null $lastEval */
$config = $config ?? [];
$tiers = $tiers ?? [];
$warn = is_array($config['warning'] ?? null) ? $config['warning'] : [];
$minByCode = [];
foreach ($config['tiers'] ?? [] as $t) {
    if (is_array($t)) {
        $minByCode[(string) ($t['code'] ?? '')] = (int) ($t['min_sales'] ?? 0);
    }
}
?>
<p class="meta"><a href="<?= e(url('/admin/partners')) ?>">← Partners</a></p>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Niveles partner</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Define cuántas certificaciones al año se necesitan para Bronze, Silver y Gold.
            Al cerrar el convenio se evalúa si el partner sube, se mantiene o baja de nivel.
            Los convenios especiales (CNCM u otros fuera del programa) no aparecen en esta escala.
        </p>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/partners/niveles')) ?>" class="panel" style="margin-top:1rem;max-width:760px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <label class="muted" style="display:flex;gap:.45rem;align-items:center;font-size:.9rem;font-weight:600;margin-bottom:1rem">
        <input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>>
        Programa de niveles activo
    </label>

    <h2 style="margin:0 0 .65rem;font-size:1.05rem;color:var(--doceo-blue)">Periodo de convenio (evaluación)</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Inicio
            <input type="date" name="period_start" required
                   value="<?= e((string) ($config['period_start'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Término
            <input type="date" name="period_end" required
                   value="<?= e((string) ($config['period_end'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>
    <p class="muted" style="font-size:.78rem;margin:.45rem 0 1.1rem">
        Al llegar a la fecha de término (o después), el cron / evaluación manual ajusta el nivel
        según las ventas del periodo. Cada partner puede tener fechas propias en su ficha.
    </p>

    <h2 style="margin:0 0 .65rem;font-size:1.05rem;color:var(--doceo-blue)">Umbrales de ventas (certificaciones pagadas / año)</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Bronze · mínimo
            <input type="number" min="0" name="min_a" required
                   value="<?= (int) ($minByCode['a'] ?? 1) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Silver · mínimo
            <input type="number" min="0" name="min_b" required
                   value="<?= (int) ($minByCode['b'] ?? 50) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Gold · mínimo
            <input type="number" min="0" name="min_c" required
                   value="<?= (int) ($minByCode['c'] ?? 120) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>
    <?php if ($tiers !== []): ?>
        <ul class="muted" style="font-size:.82rem;margin:.65rem 0 1.1rem;padding-left:1.1rem">
            <?php foreach ($tiers as $t): ?>
                <li><strong><?= e($t['label']) ?></strong>: <?= e($t['range_label']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2 style="margin:0 0 .65rem;font-size:1.05rem;color:var(--doceo-blue)">Aviso por bajas ventas</h2>
    <p class="muted" style="font-size:.82rem;margin:0 0 .65rem;max-width:40rem">
        Se envía (una vez por periodo) a partners del programa con pocas ventas y varios meses
        sin actividad. Usa una plantilla de correo (audiencia Partner).
    </p>
    <label class="muted" style="display:flex;gap:.45rem;align-items:center;font-size:.9rem;font-weight:600;margin-bottom:.75rem">
        <input type="checkbox" name="warning_enabled" value="1" <?= !empty($warn['enabled']) ? 'checked' : '' ?>>
        Enviar avisos automáticos
    </label>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Plantilla
            <input type="text" name="warning_template"
                   value="<?= e((string) ($warn['template_code'] ?? 'partner_low_sales_warning')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Avisar si ventas del año ≤
            <input type="number" min="0" name="warning_max_sales"
                   value="<?= (int) ($warn['max_sales_year'] ?? 1) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Tras N meses del convenio
            <input type="number" min="1" name="warning_months"
                   value="<?= (int) ($warn['months_without_sale'] ?? 8) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>
    <p class="muted" style="font-size:.78rem;margin:.55rem 0 1.1rem">
        Edita el texto en
        <a href="<?= e(url('/admin/correos/partner_low_sales_warning')) ?>">Correos → partner_low_sales_warning</a>.
    </p>

    <button class="btn btn-accent" type="submit">Guardar configuración</button>
</form>

<div class="panel" style="margin-top:1rem;max-width:760px">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Evaluación manual</h2>
    <p class="muted" style="font-size:.85rem">
        Recalcula niveles de partners del programa cuyo convenio ya terminó.
        También puedes dejar que el cron (<code>bin/process-scheduled-mails.php</code>) lo haga solo.
    </p>
    <?php if (is_array($lastEval) && $lastEval !== []): ?>
        <p class="muted" style="font-size:.8rem">
            Última evaluación:
            <?= e((string) ($lastEval['at'] ?? '—')) ?>
            · actualizados: <?= (int) ($lastEval['updated'] ?? 0) ?>
        </p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/partners/niveles')) ?>" style="display:flex;gap:.55rem;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="evaluate">
        <button class="btn btn-primary" type="submit">Evaluar niveles ahora</button>
    </form>
    <form method="post" action="<?= e(url('/admin/partners/niveles')) ?>" style="margin-top:.55rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="warn">
        <button class="btn btn-ghost" type="submit">Enviar avisos de bajas ventas ahora</button>
    </form>
</div>

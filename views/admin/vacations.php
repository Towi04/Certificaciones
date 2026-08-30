<?php
/** @var list<string> $dates */
/** @var string $raw */
$dates = $dates ?? [];
$raw = $raw ?? implode("\n", $dates);
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Vacaciones globales DOCEO</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Publica aquí las fechas en que DOCEO no aplica exámenes (vacaciones o cierres).
            Aplican a <strong>todos</strong> los grupos, excepto los marcados como
            <em>Disponible los 365 días del año</em> en Fechas y horarios.
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/grupos')) ?>">Ver grupos</a>
</div>

<form method="post" action="<?= e(url('/admin/vacaciones')) ?>" class="panel" style="margin-top:1rem;max-width:720px">
    <?= csrf_field() ?>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
        Fechas bloqueadas (una por línea, YYYY-MM-DD)
        <textarea name="vacation_dates" rows="12" placeholder="2026-12-24&#10;2026-12-25&#10;2027-01-01"
                  style="padding:.65rem .75rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.9rem"><?= e($raw) ?></textarea>
    </label>
    <p class="muted" style="font-size:.78rem;margin:.55rem 0 1rem">
        Ejemplo: <code>2026-12-24</code>, <code>2026-12-25</code>, <code>2027-01-01</code>.
        Hoy hay <strong><?= count($dates) ?></strong> fecha(s) publicadas.
    </p>
    <button class="btn btn-accent" type="submit">Guardar vacaciones</button>
</form>

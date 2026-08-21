<h1 style="margin-top:0;color:var(--doceo-blue)">Salud del sistema</h1>
<div class="panel" style="margin-bottom:1rem">
    <p style="margin:0">
        <strong>Código en este servidor:</strong>
        fix tabla maestra (display_name):
        <span class="pill"><?= !empty($maestraFix) ? 'OK' : 'FALTA — vuelve a desplegar' ?></span>
    </p>
    <?php if (!empty($deployedAt)): ?>
        <p class="muted" style="margin:.5rem 0 0">Último deploy marcado: <?= e($deployedAt) ?></p>
    <?php else: ?>
        <p class="muted" style="margin:.5rem 0 0">Sin marca de deploy. Abre <code>upload_version.php?key=…</code> y espera «Despliegue completado».</p>
    <?php endif; ?>
</div>
<div class="stats">
<?php foreach ($results as $r): ?>
    <div class="stat">
        <div class="label"><?= e($r['name']) ?></div>
        <div class="value" style="font-size:1.1rem;color:<?= !empty($r['ok']) ? '#176b3a' : '#8a1f1f' ?>">
            <?= !empty($r['ok']) ? 'OK' : 'FALLA' ?>
        </div>
        <div class="muted" style="margin-top:.4rem;font-size:.85rem"><?= e($r['message']) ?></div>
    </div>
<?php endforeach; ?>
</div>

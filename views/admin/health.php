<h1 style="margin-top:0;color:var(--doceo-blue)">Salud del sistema</h1>
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

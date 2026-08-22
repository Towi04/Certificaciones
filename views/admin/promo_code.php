<?php
/** @var string $currentCode */
/** @var ?array<string,mixed> $active */
?>
<h1 style="margin-top:0;color:var(--doceo-blue)">Código promocional DOCEO</h1>
<p class="muted">
    Un solo código activo para todo el catálogo. Al usarlo, el alumno paga el precio público DOCEO
    en lugar del precio de lista. Puedes cambiarlo cada semana o mes.
</p>

<div class="panel" style="margin-top:1rem;max-width:480px">
    <p style="margin-top:0"><strong>Código vigente:</strong>
        <code style="font-size:1.1rem"><?= e($currentCode) ?></code>
    </p>
    <?php if ($active): ?>
        <p class="muted" style="font-size:.85rem;margin-bottom:1rem">
            Activo desde <?= e($active['created_at'] ?? '—') ?> · modo: precio público DOCEO
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/promo')) ?>">
        <?= csrf_field() ?>
        <label>Nuevo código
            <input type="text" name="code" value="<?= e($currentCode) ?>" required
                pattern="[A-Za-z0-9_-]{3,40}" maxlength="40"
                style="text-transform:uppercase;font:inherit;padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%">
        </label>
        <p class="muted" style="font-size:.82rem;margin:.35rem 0 1rem">
            El código anterior dejará de funcionar al guardar uno nuevo.
        </p>
        <button class="btn btn-accent" type="submit">Guardar código</button>
    </form>
</div>

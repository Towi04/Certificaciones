<?php
/** @var array<string,mixed>|null $partner */
/** @var array<string,string> $tierLabels */
$isEdit = $partner !== null;
$action = $isEdit
    ? url('/admin/partners/' . $partner['id'])
    : url('/admin/partners/nuevo');
?>
<p class="meta"><a href="<?= e(url('/admin/partners')) ?>">← Partners</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar partner' : 'Nuevo partner' ?>
</h1>
<p class="muted">
    <?= $isEdit
        ? 'Puedes cambiar el nivel de precio, datos de acceso y activar/desactivar la cuenta.'
        : 'Se crea la cuenta de acceso (rol partner) y la ficha con nivel de precio.' ?>
</p>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:640px">
    <?= csrf_field() ?>

    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Ficha comercial</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nombre comercial *
            <input type="text" name="display_name" required
                   value="<?= e((string) ($partner['display_name'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Código *
            <input type="text" name="code" required maxlength="40" pattern="[A-Za-z0-9_-]{2,40}"
                   value="<?= e((string) ($partner['code'] ?? '')) ?>"
                   placeholder="Ej. ESCUELA01"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;text-transform:uppercase">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nivel de precio *
            <select name="tier" required style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <?php foreach ($tierLabels as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= (($partner['tier'] ?? 'c') === $val) ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-top:.75rem">
        Notas internas
        <textarea name="notes" rows="2" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px"><?= e((string) ($partner['notes'] ?? '')) ?></textarea>
    </label>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin-top:1.25rem">Acceso</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Correo *
            <input type="email" name="email" required
                   value="<?= e((string) ($partner['email'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Contraseña<?= $isEdit ? '' : ' (opcional)' ?>
            <input type="text" name="password" minlength="8" autocomplete="new-password"
                   placeholder="<?= $isEdit ? 'Dejar vacío para no cambiar' : 'Vacío = contraseña por defecto' ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Teléfono
            <input type="tel" name="phone"
                   value="<?= e((string) ($partner['phone'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nombre(s) *
            <input type="text" name="first_name" required
                   value="<?= e((string) ($partner['first_name'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Apellido paterno *
            <input type="text" name="last_name_p" required
                   value="<?= e((string) ($partner['last_name_p'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Apellido materno
            <input type="text" name="last_name_m"
                   value="<?= e((string) ($partner['last_name_m'] ?? '')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>

    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:1rem">
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
            <input type="checkbox" name="is_active" value="1"
                <?= (!$isEdit || (int) ($partner['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
            Partner activo
        </label>
        <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
            <input type="checkbox" name="must_change_password" value="1"
                <?= (!$isEdit || (int) ($partner['must_change_password'] ?? 0) === 1) ? 'checked' : '' ?>>
            Debe cambiar contraseña al entrar
        </label>
    </div>

    <?php if ($isEdit): ?>
        <p class="muted" style="font-size:.85rem;margin-top:.75rem">
            Crédito a favor: <strong><?= money($partner['credit_balance'] ?? 0) ?></strong>
            (no se edita aquí)
        </p>
    <?php else: ?>
        <p class="muted" style="font-size:.85rem;margin-top:.75rem">
            Si dejas la contraseña vacía se usa la por defecto del sistema.
            Tras crear verás la contraseña una vez en el mensaje de éxito.
        </p>
    <?php endif; ?>

    <div style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Crear partner' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/partners')) ?>">Cancelar</a>
    </div>
</form>

<?php
/** @var array<string,mixed>|null $group */
/** @var list<array<string,mixed>> $suppliers */
/** @var string $defaultConfig */
$isEdit = $group !== null;
$action = $isEdit ? url('/admin/grupos/' . $group['id']) : url('/admin/grupos/nuevo');
?>
<p class="meta"><a href="<?= e(url('/admin/grupos')) ?>">← Grupos de proceso</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar grupo' : 'Nuevo grupo de proceso' ?>
</h1>
<p class="muted">
    El código identifica el grupo. La configuración JSON define pagos/MSI y el resto del proceso
    que heredan todos los productos asignados.
</p>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:760px">
    <?= csrf_field() ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Nombre *
            <input type="text" name="name" required
                   value="<?= e((string) ($group['name'] ?? '')) ?>"
                   placeholder="Ej. iTEP / Oxford · Exámenes"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Código *
            <input type="text" name="code" required maxlength="40"
                   <?= $isEdit ? 'readonly' : '' ?>
                   value="<?= e((string) ($group['code'] ?? '')) ?>"
                   placeholder="Ej. itep-exams"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px<?= $isEdit ? ';background:#f4f7fb' : '' ?>">
            <?php if ($isEdit): ?>
                <span style="font-weight:500;font-size:.78rem">El código no se cambia después de crear el grupo.</span>
            <?php endif; ?>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Proveedor
            <select name="supplier_id" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                <option value="">— Ninguno —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) ($group['supplier_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?> (<?= e($s['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (!$isEdit): ?>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                Plantilla inicial
                <select name="template" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    <option value="cert">Certificación / trámite (MSI 1–12)</option>
                    <option value="course">Curso Moodle (sin MSI)</option>
                </select>
            </label>
        <?php endif; ?>
    </div>

    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-top:1rem">
        Configuración JSON del proceso
        <textarea name="config_json" rows="16"
                  style="padding:.65rem .75rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem"><?= e($defaultConfig) ?></textarea>
        <span style="font-weight:500;font-size:.78rem">
            Vacío al crear = se usa la plantilla. Incluye <code>payments</code> y <code>card_msi</code> para cobros tipo ELeT.
        </span>
    </label>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar grupo' : 'Crear grupo' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos')) ?>">Cancelar</a>
    </div>
</form>

<?php
/** @var array<string,mixed> $template */
/** @var list<array<string,mixed>> $cellMap */
/** @var list<array{value:string,label:string}> $fieldOptions */
/** @var list<array<string,mixed>> $suppliers */
/** @var bool $isNew */
$isNew = $isNew ?? empty($template['code']);
$code = (string) ($template['code'] ?? '');
$name = (string) ($template['name'] ?? '');
$active = (int) ($template['is_active'] ?? 1) === 1;
$supplierId = (int) ($template['supplier_id'] ?? 0);
$suppliers = is_array($suppliers ?? null) ? $suppliers : [];
$path = (string) ($template['storage_path'] ?? '');
$map = [];
if (!empty($template['mapping_json'])) {
    $decoded = is_string($template['mapping_json'])
        ? json_decode((string) $template['mapping_json'], true)
        : $template['mapping_json'];
    if (is_array($decoded)) {
        $map = $decoded;
    }
}
$sheet = (string) ($map['sheet'] ?? '');
$normalize = (string) ($map['normalize'] ?? 'none');
$cellMap = is_array($cellMap ?? null) && $cellMap !== [] ? $cellMap : [['cell' => '', 'field' => '', 'formula' => '']];
$fieldOptions = is_array($fieldOptions ?? null) ? $fieldOptions : \App\Services\ProviderRequestService::FIELD_OPTIONS;
$formAction = $isNew ? url('/admin/plantillas-csv/nueva') : url('/admin/plantillas-csv/' . $code);
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;font:inherit;width:100%';
?>
<p class="meta"><a href="<?= e(url('/admin/plantillas-csv?tipo=xlsx')) ?>">← Plantillas proveedor · Excel</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= $isNew ? 'Nueva plantilla Excel' : e($name !== '' ? $name : $code) ?></h1>
<p class="muted">Código: <code><?= e($code !== '' ? $code : 'por definir') ?></code>
    · Tipo <strong>xlsx</strong> (correo <code>{{workbook_url}}</code>)
</p>

<div class="panel" style="margin-top:1rem;max-width:860px">
    <form method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data" id="xlsx-template-form">
        <?= csrf_field() ?>
        <input type="hidden" name="file_type" value="xlsx">

        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <h2 style="margin:0 0 .75rem;font-size:1rem;color:var(--doceo-blue)">Identificación</h2>
            <div style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Nombre *
                    <input type="text" name="name" required maxlength="160" value="<?= e($name) ?>"
                           placeholder="TOEFL · Excel inscripción" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Código *
                    <input type="text" name="code" required pattern="[a-z0-9_\-]{2,64}"
                           value="<?= e($code) ?>" <?= $isNew ? '' : 'readonly' ?>
                           placeholder="xlsx_mail_toefl_solicitud" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Proveedor
                    <select name="supplier_id" style="<?= e($inputStyle) ?>">
                        <option value="">— Sin proveedor —</option>
                        <?php foreach ($suppliers as $s): ?>
                            <?php $sid = (int) ($s['id'] ?? 0); if ($sid < 1) continue; ?>
                            <option value="<?= $sid ?>" <?= $supplierId === $sid ? 'selected' : '' ?>>
                                <?= e((string) ($s['name'] ?? $s['code'] ?? ('#' . $sid))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label style="display:flex;align-items:center;gap:.45rem;margin-top:.9rem;font-size:.9rem">
                <input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
                Plantilla activa
            </label>
        </div>

        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <h2 style="margin:0 0 .75rem;font-size:1rem;color:var(--doceo-blue)">Archivo .xlsx</h2>
            <div style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Archivo actual
                    <input type="text" name="template_path" value="<?= e($path) ?>" readonly
                           style="<?= e($inputStyle) ?>;background:#fff">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Subir / reemplazar
                    <input type="file" name="workbook_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Hoja (opcional)
                    <input type="text" name="sheet" value="<?= e($sheet) ?>" placeholder="Sheet1 o 1" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Normalización
                    <select name="normalize" style="<?= e($inputStyle) ?>">
                        <option value="none" <?= $normalize === 'none' ? 'selected' : '' ?>>Ninguna</option>
                        <option value="toefl" <?= $normalize === 'toefl' ? 'selected' : '' ?>>TOEFL (MAYÚSCULAS sin acentos/Ñ)</option>
                    </select>
                </label>
            </div>
            <?php if (!$isNew && $path !== ''): ?>
                <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.82rem;margin-top:.65rem">
                    <input type="checkbox" name="clear_file" value="1"> Quitar ruta de archivo (requiere subir otra al guardar)
                </label>
            <?php endif; ?>
        </div>

        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
                <h2 style="margin:0;font-size:1rem;color:var(--doceo-blue)">Celdas → campo / fórmula</h2>
                <button type="button" class="btn btn-ghost btn-sm" id="xlsx-cell-add">+ Celda</button>
            </div>
            <div id="xlsx-cells">
                <?php foreach ($cellMap as $cell): ?>
                    <div style="display:grid;grid-template-columns:6rem minmax(9rem,1fr) minmax(14rem,1.4fr) auto;gap:.4rem;margin-bottom:.35rem" class="xlsx-cell-row">
                        <input type="text" name="workbook_cells[]" value="<?= e((string) ($cell['cell'] ?? '')) ?>" placeholder="B2"
                               style="padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px">
                        <select name="workbook_fields[]" style="padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px">
                            <option value="">— Campo —</option>
                            <?php foreach ($fieldOptions as $opt): ?>
                                <option value="<?= e($opt['value']) ?>" <?= ($cell['field'] ?? '') === $opt['value'] ? 'selected' : '' ?>>
                                    <?= e($opt['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="workbook_formulas[]" value="<?= e((string) ($cell['formula'] ?? '')) ?>"
                               placeholder='=ASCIIMAYUSC({{full_name}})'
                               style="padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px;font-family:ui-monospace,monospace;font-size:.78rem">
                        <button type="button" class="btn btn-ghost btn-sm xlsx-cell-remove">✕</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <button class="btn btn-accent" type="submit"><?= $isNew ? 'Crear plantilla' : 'Guardar cambios' ?></button>
            <a class="btn btn-ghost" href="<?= e(url('/admin/plantillas-csv?tipo=xlsx')) ?>">Cancelar</a>
        </div>
    </form>
</div>

<template id="xlsx-cell-tpl">
    <div style="display:grid;grid-template-columns:6rem minmax(9rem,1fr) minmax(14rem,1.4fr) auto;gap:.4rem;margin-bottom:.35rem" class="xlsx-cell-row">
        <input type="text" name="workbook_cells[]" value="" placeholder="B2"
               style="padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px">
        <select name="workbook_fields[]" style="padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px">
            <option value="">— Campo —</option>
            <?php foreach ($fieldOptions as $opt): ?>
                <option value="<?= e($opt['value']) ?>"><?= e($opt['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="workbook_formulas[]" value="" placeholder='=ASCIIMAYUSC({{full_name}})'
               style="padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px;font-family:ui-monospace,monospace;font-size:.78rem">
        <button type="button" class="btn btn-ghost btn-sm xlsx-cell-remove">✕</button>
    </div>
</template>
<script>
(function () {
  var box = document.getElementById('xlsx-cells');
  var tpl = document.getElementById('xlsx-cell-tpl');
  var add = document.getElementById('xlsx-cell-add');
  if (!box || !tpl || !add) return;
  add.addEventListener('click', function () { box.appendChild(tpl.content.cloneNode(true)); });
  box.addEventListener('click', function (e) {
    var btn = e.target.closest('.xlsx-cell-remove');
    if (!btn) return;
    var rows = box.querySelectorAll('.xlsx-cell-row');
    if (rows.length <= 1) return;
    var row = btn.closest('.xlsx-cell-row');
    if (row) row.remove();
  });
})();
</script>

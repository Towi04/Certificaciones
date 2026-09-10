<?php
/** @var array<string,mixed> $template */
/** @var list<array{header:string,field:string}> $columns */
/** @var list<array{value:string,label:string}> $fieldOptions */
/** @var bool $isNew */
$isNew = $isNew ?? empty($template['code']);
$code = (string) ($template['code'] ?? '');
$name = (string) ($template['name'] ?? '');
$batchBy = (string) ($template['batch_by'] ?? 'none');
$active = (int) ($template['is_active'] ?? 1) === 1;
$map = [];
if (!empty($template['mapping_json'])) {
    $decoded = is_string($template['mapping_json'])
        ? json_decode((string) $template['mapping_json'], true)
        : $template['mapping_json'];
    if (is_array($decoded)) {
        $map = $decoded;
    }
}
$normalize = (string) ($map['normalize'] ?? 'none');
$filters = is_array($map['filters'] ?? null) ? $map['filters'] : [];
$productCodes = is_array($filters['product_codes'] ?? null) ? implode(', ', $filters['product_codes']) : '';
$groupCodes = is_array($filters['product_group_codes'] ?? null) ? implode(', ', $filters['product_group_codes']) : '';
$statusCodes = is_array($filters['purchase_status'] ?? null) ? implode(', ', $filters['purchase_status']) : 'paid';
if (!is_array($columns) || $columns === []) {
    $columns = [['header' => '', 'field' => 'first_name']];
}
$fieldOptions = is_array($fieldOptions ?? null) ? $fieldOptions : \App\Services\ExportService::fieldOptions();
$formAction = $isNew ? url('/admin/plantillas-csv/nueva') : url('/admin/plantillas-csv/' . $code);
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;font:inherit;width:100%';
?>
<p class="meta"><a href="<?= e(url('/admin/plantillas-csv')) ?>">← Plantillas CSV</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= $isNew ? 'Nueva plantilla CSV' : e($name !== '' ? $name : $code) ?></h1>
<p class="muted">Código: <code><?= e($code !== '' ? $code : 'por definir') ?></code>
    <?php if (!$active && !$isNew): ?>
        · <strong style="color:#b45309">Plantilla desactivada</strong>
    <?php endif; ?>
</p>

<div class="panel" style="margin-top:1rem;max-width:820px">
    <form method="post" action="<?= e($formAction) ?>" id="csv-template-form">
        <?= csrf_field() ?>

        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <h2 style="margin:0 0 .75rem;font-size:1rem;color:var(--doceo-blue)">Identificación</h2>
            <div style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Nombre *
                    <input type="text" name="name" required maxlength="160" value="<?= e($name) ?>"
                           placeholder="UKS ELET · Registro" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Código *
                    <input type="text" name="code" required pattern="[a-z0-9_\-]{2,64}"
                           value="<?= e($code) ?>" <?= $isNew ? '' : 'readonly' ?>
                           placeholder="uks_elet_registro" style="<?= e($inputStyle) ?>">
                    <span style="font-size:.78rem;font-weight:400">Minúsculas, números, _ y -. No se puede cambiar después.</span>
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Agrupación por defecto
                    <select name="batch_by" style="<?= e($inputStyle) ?>">
                        <option value="none" <?= $batchBy === 'none' ? 'selected' : '' ?>>Sin agrupar (1 fila = 1 alumno)</option>
                        <option value="exam_date" <?= $batchBy === 'exam_date' ? 'selected' : '' ?>>Por fecha de examen</option>
                    </select>
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Normalización (si la columna no tiene fórmula)
                    <select name="normalize" style="<?= e($inputStyle) ?>">
                        <option value="none" <?= $normalize === 'none' ? 'selected' : '' ?>>Ninguna</option>
                        <option value="toefl" <?= $normalize === 'toefl' ? 'selected' : '' ?>>TOEFL (MAYÚSCULAS, sin acentos/Ñ)</option>
                    </select>
                </label>
            </div>
            <label style="display:flex;align-items:center;gap:.45rem;margin-top:.9rem;font-size:.9rem">
                <input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
                Plantilla activa
            </label>
        </div>

        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <h2 style="margin:0 0 .75rem;font-size:1rem;color:var(--doceo-blue)">Filtros (descargas masivas)</h2>
            <p class="muted" style="margin:0 0 .75rem;font-size:.85rem">
                Aplican al descargar pendientes desde UKS import/export. Desde Operación (por alumno o por día)
                se usa el caso actual y se ignoran estos filtros de producto.
            </p>
            <div style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Productos (códigos)
                    <input type="text" name="product_codes" value="<?= e($productCodes) ?>"
                           placeholder="uks_elet, otro" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Grupos (códigos)
                    <input type="text" name="product_group_codes" value="<?= e($groupCodes) ?>"
                           placeholder="toefl, cambridge" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Estados de compra
                    <input type="text" name="purchase_status" value="<?= e($statusCodes) ?>"
                           placeholder="paid" style="<?= e($inputStyle) ?>">
                </label>
            </div>
        </div>

        <div style="margin-bottom:1.25rem;padding:1rem;background:#f4f7fb;border-radius:12px;border:1px solid #dbeafe">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
                <h2 style="margin:0;font-size:1rem;color:var(--doceo-blue)">Columnas del CSV</h2>
                <button type="button" class="btn btn-ghost btn-sm" id="csv-add-col">+ Columna</button>
            </div>
            <div class="table-wrap">
                <table class="data" id="csv-cols-table">
                    <thead>
                    <tr>
                        <th style="width:28%">Título (fila 1)</th>
                        <th style="width:28%">Campo</th>
                        <th style="width:36%">Fórmula (opcional)</th>
                        <th style="width:8%"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($columns as $col): ?>
                        <tr class="csv-col-row">
                            <td>
                                <input type="text" name="col_header[]" required
                                       value="<?= e((string) ($col['header'] ?? '')) ?>"
                                       style="<?= e($inputStyle) ?>">
                            </td>
                            <td>
                                <select name="col_field[]" style="<?= e($inputStyle) ?>">
                                    <?php foreach ($fieldOptions as $opt): ?>
                                        <?php
                                        $fv = (string) ($opt['value'] ?? '');
                                        $fl = (string) ($opt['label'] ?? $fv);
                                        ?>
                                        <option value="<?= e($fv) ?>" <?= ((string) ($col['field'] ?? '') === $fv) ? 'selected' : '' ?>>
                                            <?= e($fl) ?> (<?= e($fv) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="col_formula[]"
                                       value="<?= e((string) ($col['formula'] ?? '')) ?>"
                                       placeholder='=MAYUSC({{first_name}})'
                                       style="<?= e($inputStyle) ?>;font-family:ui-monospace,monospace;font-size:.8rem">
                            </td>
                            <td>
                                <button type="button" class="btn btn-ghost btn-sm csv-col-del" title="Quitar">✕</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted" style="margin:.65rem 0 0;font-size:.85rem">
                La fórmula (si existe) se evalúa y se escribe el <strong>valor</strong> en el CSV.
                Ej.: Cambridge <code>=MAYUSC({{full_name}})</code> (respeta Ñ/acentos);
                TOEFL <code>=ASCIIMAYUSC({{full_name}})</code>;
                fechas <code>=TEXTO({{exam_date}};"dd/mm/aaaa")</code>;
                hora <code>=TEXTO({{exam_time}};"hh:mm")</code>.
                La normalización global TOEFL solo aplica si la columna no tiene fórmula.
            </p>
        </div>

        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <button class="btn btn-accent" type="submit"><?= $isNew ? 'Crear plantilla' : 'Guardar cambios' ?></button>
            <a class="btn btn-ghost" href="<?= e(url('/admin/plantillas-csv')) ?>">Cancelar</a>
        </div>
    </form>
</div>

<template id="csv-col-tpl">
    <tr class="csv-col-row">
        <td><input type="text" name="col_header[]" required value="" style="<?= e($inputStyle) ?>"></td>
        <td>
            <select name="col_field[]" style="<?= e($inputStyle) ?>">
                <?php foreach ($fieldOptions as $opt): ?>
                    <?php $fv = (string) ($opt['value'] ?? ''); $fl = (string) ($opt['label'] ?? $fv); ?>
                    <option value="<?= e($fv) ?>"><?= e($fl) ?> (<?= e($fv) ?>)</option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text" name="col_formula[]" value="" placeholder='=MAYUSC({{first_name}})'
                   style="<?= e($inputStyle) ?>;font-family:ui-monospace,monospace;font-size:.8rem">
        </td>
        <td><button type="button" class="btn btn-ghost btn-sm csv-col-del" title="Quitar">✕</button></td>
    </tr>
</template>

<script>
(function () {
  var table = document.getElementById('csv-cols-table');
  var tpl = document.getElementById('csv-col-tpl');
  var addBtn = document.getElementById('csv-add-col');
  if (!table || !tpl || !addBtn) return;
  addBtn.addEventListener('click', function () {
    table.querySelector('tbody').appendChild(tpl.content.cloneNode(true));
  });
  table.addEventListener('click', function (e) {
    var btn = e.target.closest('.csv-col-del');
    if (!btn) return;
    var rows = table.querySelectorAll('.csv-col-row');
    if (rows.length <= 1) return;
    var tr = btn.closest('tr');
    if (tr) tr.remove();
  });
})();
</script>

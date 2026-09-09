<?php
/** @var array<string,mixed>|null $group */
/** @var list<array<string,mixed>> $suppliers */
/** @var string $defaultConfig */
/** @var array<string,mixed> $extras */
/** @var array<string,string> $usedDocCodes */
$group = is_array($group ?? null) ? $group : null;
$groupId = (int) ($group['id'] ?? 0);
$isEdit = $groupId > 0;
$action = $isEdit ? url('/admin/grupos/' . $groupId) : url('/admin/grupos/nuevo');
$extras = $extras ?? \App\Services\ProductAdminService::groupFormExtrasFromConfig($defaultConfig ?? null);
$usedDocCodes = $usedDocCodes ?? [];
$preselectSupplier = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
if (!$isEdit && $preselectSupplier > 0 && (int) ($group['supplier_id'] ?? 0) < 1) {
    $group = ['supplier_id' => $preselectSupplier];
}
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$dayLabels = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 0 => 'Dom'];
$days = is_array($extras['schedule_days'] ?? null) ? $extras['schedule_days'] : [
    1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 0 => false,
];
$groupCode = (string) ($group['code'] ?? '');
$autoDocCode = 'reglamento_' . ($groupCode !== '' ? preg_replace('/[^a-z0-9]+/', '_', strtolower($groupCode)) : 'nuevo');
$docCode = trim((string) ($extras['reglamento_doc_code'] ?? ''));
if ($docCode === '') {
    $docCode = (string) $autoDocCode;
}
$msiMonths = is_array($extras['msi_months'] ?? null) ? array_map('intval', $extras['msi_months']) : [1, 3, 6, 9, 12];
$checkoutFields = is_array($extras['checkout_fields'] ?? null)
    ? $extras['checkout_fields']
    : ['email', 'first_name', 'last_name_p', 'last_name_m', 'phone'];
$checkoutFieldRequired = is_array($extras['checkout_field_required'] ?? null)
    ? $extras['checkout_field_required']
    : [];
$alwaysFields = ['email', 'first_name', 'last_name_p', 'phone'];
$fieldMeta = \App\Services\CheckoutRequirements::allFieldMeta();
$mailTemplates = isset($mailTemplates) && is_array($mailTemplates) ? $mailTemplates : [];
$csvTemplates = isset($csvTemplates) && is_array($csvTemplates) ? $csvTemplates : [];
$emailsCfg = is_array($extras['emails'] ?? null)
    ? $extras['emails']
    : \App\Services\GroupEmailAutomation::normalize(null);
$emailExam = is_array($emailsCfg['student_exam_access'] ?? null) ? $emailsCfg['student_exam_access'] : ['enabled' => true, 'template_code' => 'student_elet_exam_access', 'mode' => 'admin'];
$emailSteps = is_array($emailsCfg['on_steps'] ?? null) ? $emailsCfg['on_steps'] : [];
if ($emailSteps === []) {
    $emailSteps = [['step_code' => '', 'template_code' => '', 'mode' => 'admin', 'audience' => 'student']];
}
$renderMailTemplateField = static function (
    string $name,
    string $value,
    array $templates,
    string $inputStyle,
    string $placeholder = ''
): void {
    $value = trim($value);
    if ($templates !== []) {
        echo '<select name="' . e($name) . '" style="' . e($inputStyle) . '">';
        echo '<option value="">— Elegir plantilla —</option>';
        $found = false;
        foreach ($templates as $tpl) {
            $code = trim((string) ($tpl['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $label = trim((string) ($tpl['name'] ?? $code));
            $sel = $code === $value;
            if ($sel) {
                $found = true;
            }
            echo '<option value="' . e($code) . '"' . ($sel ? ' selected' : '') . '>'
                . e($label) . ' (' . e($code) . ')</option>';
        }
        if ($value !== '' && !$found) {
            echo '<option value="' . e($value) . '" selected>' . e($value) . ' (actual)</option>';
        }
        echo '</select>';

        return;
    }
    echo '<input type="text" name="' . e($name) . '" value="' . e($value) . '"'
        . ' placeholder="' . e($placeholder !== '' ? $placeholder : 'código_de_plantilla') . '"'
        . ' style="' . e($inputStyle) . '">';
};
?>
<p class="meta"><a href="<?= e(url('/admin/grupos')) ?>">← Grupos de proceso</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar grupo' : 'Nuevo grupo de producto' ?>
</h1>

<nav class="group-tabs" role="tablist" aria-label="Secciones del grupo">
    <button type="button" class="group-tab active" data-tab="general" role="tab" aria-selected="true">General</button>
    <button type="button" class="group-tab" data-tab="fields" role="tab" aria-selected="false">Datos del alumno</button>
    <button type="button" class="group-tab" data-tab="schedule" role="tab" aria-selected="false">Fechas y horarios</button>
    <button type="button" class="group-tab" data-tab="inventory" role="tab" aria-selected="false">Inventario</button>
    <button type="button" class="group-tab" data-tab="results" role="tab" aria-selected="false">Resultados</button>
    <button type="button" class="group-tab" data-tab="extra" role="tab" aria-selected="false">Campo extra</button>
    <button type="button" class="group-tab" data-tab="docs" role="tab" aria-selected="false">Docs</button>
    <button type="button" class="group-tab" data-tab="rules" role="tab" aria-selected="false">Reglamento</button>
    <button type="button" class="group-tab" data-tab="payments" role="tab" aria-selected="false">Pagos</button>
    <button type="button" class="group-tab" data-tab="progress" role="tab" aria-selected="false">Progreso y acciones</button>
    <button type="button" class="group-tab" data-tab="advanced" role="tab" aria-selected="false">Experto</button>
</nav>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:.75rem;max-width:960px" id="group-form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="apply_structured_config" value="1">

    <div class="group-panel" data-panel="general">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos del grupo</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Nombre *
                <input type="text" name="name" required
                       value="<?= e((string) ($group['name'] ?? '')) ?>"
                       placeholder="Ej. iTEP / Oxford · Exámenes"
                       style="<?= e($inputStyle) ?>">
            </label>
            <?php if ($isEdit): ?>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Código interno
                    <input type="text" name="code" id="group-code" readonly maxlength="40"
                           value="<?= e($groupCode) ?>"
                           style="<?= e($inputStyle) ?>;background:#f4f7fb">
                </label>
            <?php else: ?>
                <input type="hidden" name="code" id="group-code" value="">
                <p class="muted" style="margin:0;font-size:.8rem;align-self:end;padding-bottom:.35rem">
                    El código interno se genera solo a partir del nombre.
                </p>
            <?php endif; ?>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Proveedor
                <select name="supplier_id" style="<?= e($inputStyle) ?>">
                    <option value="">— Ninguno —</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) ($group['supplier_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?> (<?= e($s['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if (!$isEdit): ?>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Plantilla inicial
                    <select name="template" style="<?= e($inputStyle) ?>">
                        <option value="cert">Certificación / trámite (MSI 1–12)</option>
                        <option value="course">Curso Moodle (sin MSI)</option>
                    </select>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="group-panel" data-panel="fields" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos que se piden al alumno</h2>
        <div class="field-check-grid" id="checkout-field-grid">
            <?php foreach ($fieldMeta as $code => $meta): ?>
                <?php
                $locked = in_array($code, $alwaysFields, true);
                $checked = $locked || in_array($code, $checkoutFields, true);
                $isCustom = !empty($meta['custom']);
                $defaultRequired = !empty($meta['required']);
                $isRequired = array_key_exists($code, $checkoutFieldRequired)
                    ? !empty($checkoutFieldRequired[$code])
                    : $defaultRequired;
                ?>
                <div class="field-check<?= $locked ? ' field-check--locked' : '' ?>" data-field-code="<?= e($code) ?>" data-custom="<?= $isCustom ? '1' : '0' ?>">
                    <label class="field-check-main">
                        <?php if ($locked): ?>
                            <input type="hidden" name="checkout_fields[]" value="<?= e($code) ?>">
                            <input type="checkbox" checked disabled>
                        <?php else: ?>
                            <input type="checkbox" class="field-include" name="checkout_fields[]" value="<?= e($code) ?>"
                                <?= $checked ? 'checked' : '' ?>>
                        <?php endif; ?>
                        <span>
                            <strong class="field-label-text"><?= e((string) $meta['label']) ?></strong>
                            <?php if ($isCustom): ?>
                                <span class="field-custom-badge">Personalizado</span>
                            <?php endif; ?>
                            <?php if ($code === 'sex'): ?>
                                <span class="field-custom-badge" title="El alumno elige Femenino o Masculino; en BD y proveedor se guarda F o M">Selector M/F</span>
                            <?php endif; ?>
                        </span>
                    </label>
                    <?php if ($locked): ?>
                        <span class="muted" style="display:block;font-size:.75rem;font-weight:500;margin:.25rem 0 0 1.55rem">
                            Obligatorio en toda compra
                        </span>
                    <?php else: ?>
                        <label class="field-required-toggle muted" title="Si el campo se pide al alumno">
                            <select name="checkout_field_required[<?= e($code) ?>]" class="field-required-select"
                                    aria-label="Obligatorio u opcional">
                                <option value="1" <?= $isRequired ? 'selected' : '' ?>>Obligatorio</option>
                                <option value="0" <?= !$isRequired ? 'selected' : '' ?>>Opcional</option>
                            </select>
                        </label>
                        <?php if ($isCustom): ?>
                            <?php
                            $optLines = '';
                            if (($meta['type'] ?? '') === 'select' && is_array($meta['options'] ?? null)) {
                                $optLines = implode("\n", array_map(
                                    static fn ($o) => (string) ($o['label'] ?? $o['value'] ?? ''),
                                    $meta['options']
                                ));
                            }
                            ?>
                            <button type="button" class="btn-link field-edit-btn" data-code="<?= e($code) ?>"
                                    data-label="<?= e((string) $meta['label']) ?>"
                                    data-type="<?= e((string) ($meta['type'] ?? 'text')) ?>"
                                    data-required="<?= $defaultRequired ? '1' : '0' ?>"
                                    data-options="<?= e($optLines) ?>">
                                Editar campo
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="add-checkout-field" style="margin-top:1.1rem;padding:1rem;border:1px dashed #9db7e8;border-radius:14px;background:#f7faff">
            <h3 style="margin:0 0 .75rem;font-size:.95rem;color:var(--doceo-blue)">Agregar campo nuevo</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.65rem;align-items:end">
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
                    Nombre del campo *
                    <input type="text" name="new_checkout_field_label" id="new_checkout_field_label"
                           placeholder="Ej. Escuela de procedencia"
                           style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
                    Tipo
                    <select name="new_checkout_field_type" id="new_checkout_field_type"
                            style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                        <option value="text">Texto</option>
                        <option value="email">Correo</option>
                        <option value="tel">Teléfono</option>
                        <option value="date">Fecha</option>
                        <option value="number">Número</option>
                        <option value="select">Opción múltiple (lista)</option>
                    </select>
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
                    Si se pide
                    <select name="new_checkout_field_required" id="new_checkout_field_required"
                            style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                        <option value="1" selected>Obligatorio</option>
                        <option value="0">Opcional</option>
                    </select>
                </label>
                <div style="padding-bottom:.15rem">
                    <button type="button" class="btn btn-ghost" id="add-checkout-field-btn">Agregar a la lista</button>
                </div>
            </div>
            <div id="new-checkout-field-options-wrap" hidden style="margin-top:.75rem">
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
                    Opciones (una por línea) *
                    <textarea name="new_checkout_field_options" id="new_checkout_field_options" rows="4"
                              placeholder="Matutino&#10;Vespertino&#10;Sábado"
                              style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px;resize:vertical"></textarea>
                    <span style="font-size:.75rem;font-weight:500">El alumno solo podrá elegir una de estas opciones.</span>
                </label>
            </div>
            <p class="muted" id="add-checkout-field-msg" style="margin:.55rem 0 0;font-size:.78rem;display:none"></p>
        </div>

        <div id="edit-checkout-field-panel" hidden style="margin-top:1rem;padding:1rem;border:1px solid #9db7e8;border-radius:14px;background:#fff">
            <h3 style="margin:0 0 .35rem;font-size:.95rem;color:var(--doceo-blue)">Editar campo personalizado</h3>
            <p class="muted" style="margin:0 0 .75rem;font-size:.8rem">
                Los cambios aplican al catálogo global (todos los grupos).
            </p>
            <input type="hidden" id="edit_checkout_field_code" value="">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.65rem;align-items:end">
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
                    Nombre *
                    <input type="text" id="edit_checkout_field_label"
                           style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
                    Tipo
                    <select id="edit_checkout_field_type"
                            style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                        <option value="text">Texto</option>
                        <option value="email">Correo</option>
                        <option value="tel">Teléfono</option>
                        <option value="date">Fecha</option>
                        <option value="number">Número</option>
                        <option value="select">Opción múltiple (lista)</option>
                    </select>
                </label>
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
                    Default si se pide
                    <select id="edit_checkout_field_required"
                            style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px">
                        <option value="1">Obligatorio</option>
                        <option value="0">Opcional</option>
                    </select>
                </label>
                <div style="display:flex;gap:.5rem;padding-bottom:.15rem">
                    <button type="button" class="btn btn-accent" id="save-checkout-field-btn">Guardar cambios</button>
                    <button type="button" class="btn btn-ghost" id="cancel-edit-checkout-field-btn">Cancelar</button>
                </div>
            </div>
            <div id="edit-checkout-field-options-wrap" hidden style="margin-top:.75rem">
                <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
                    Opciones (una por línea) *
                    <textarea id="edit_checkout_field_options" rows="4"
                              placeholder="Opción A&#10;Opción B"
                              style="padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px;resize:vertical"></textarea>
                </label>
            </div>
            <p class="muted" id="edit-checkout-field-msg" style="margin:.55rem 0 0;font-size:.78rem;display:none"></p>
        </div>
    </div>

    <div class="group-panel" data-panel="schedule" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Fechas y horarios de aplicación</h2>

        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.65rem">
            <input type="checkbox" name="exam_choose_at_checkout" value="1"
                <?= !empty($extras['exam_choose_at_checkout']) ? 'checked' : '' ?>>
            Pedir fecha y hora de aplicación en el checkout
        </label>

        <label class="muted" style="<?= e($labelStyle) ?>;margin-bottom:.85rem">
            Modo de agenda
            <select name="schedule_mode" id="schedule_mode" style="<?= e($inputStyle) ?>">
                <?php
                $scheduleMode = (string) ($extras['schedule_mode'] ?? 'window');
                $modeOptions = [
                    'window' => 'Ventana continua (ELeT / Cambridge flexible Lun–Vie)',
                    'fixed_slots' => 'Horarios fijos por día (TOEFL sábados 11:00 / 13:00)',
                    'dated_list' => 'Lista de fechas del proveedor (Cambridge convocatorias)',
                ];
                foreach ($modeOptions as $modeVal => $modeLabel):
                ?>
                    <option value="<?= e($modeVal) ?>" <?= $scheduleMode === $modeVal ? 'selected' : '' ?>>
                        <?= e($modeLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="muted" style="<?= e($labelStyle) ?>;margin-bottom:.85rem">
            Texto de ayuda en checkout (opcional)
            <input type="text" name="schedule_checkout_help"
                   value="<?= e((string) ($extras['schedule_checkout_help'] ?? '')) ?>"
                   placeholder="Ej. Elige un sábado a las 11:00 o 13:00…"
                   style="<?= e($inputStyle) ?>">
        </label>

        <label class="muted" style="display:flex;align-items:flex-start;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:1rem">
            <input type="checkbox" name="schedule_available_365" value="1" style="margin-top:.2rem"
                <?= !empty($extras['schedule_available_365']) ? 'checked' : '' ?>>
            <span>Disponible los 365 días del año</span>
        </label>

        <div style="margin-bottom:1rem">
            <div class="muted" style="font-size:.88rem;font-weight:600;margin-bottom:.45rem">Días en que se puede aplicar</div>
            <div style="display:flex;flex-wrap:wrap;gap:.55rem">
                <?php foreach ($dayLabels as $dow => $label): ?>
                    <label class="day-check">
                        <input type="checkbox" name="schedule_days[<?= (int) $dow ?>]" value="1"
                            <?= !empty($days[$dow]) || !empty($days[(string) $dow]) ? 'checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Minutos por bloque
                <input type="number" name="exam_slot_minutes" min="15" step="5"
                       value="<?= (int) ($extras['exam_slot_minutes'] ?? 30) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Antelación (días)
                <input type="number" name="schedule_min_advance_days" min="0" step="1"
                       value="<?= (int) ($extras['schedule_min_advance_days'] ?? 2) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Caducidad
                <input type="number" name="exam_validity_months" min="1" max="36" step="1"
                       value="<?= (int) ($extras['exam_validity_months'] ?? 6) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Lun–Vie desde
                <input type="text" name="schedule_weekdays_start" placeholder="10:00"
                       value="<?= e((string) ($extras['schedule_weekdays_start'] ?? '10:00')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Lun–Vie hasta
                <input type="text" name="schedule_weekdays_end" placeholder="17:30"
                       value="<?= e((string) ($extras['schedule_weekdays_end'] ?? '17:30')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Fin de semana desde
                <input type="text" name="schedule_saturday_start" placeholder="08:00"
                       value="<?= e((string) ($extras['schedule_saturday_start'] ?? '08:00')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Fin de semana hasta
                <input type="text" name="schedule_saturday_end" placeholder="12:00"
                       value="<?= e((string) ($extras['schedule_saturday_end'] ?? '12:00')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
        </div>
        <p class="muted" style="font-size:.78rem;margin:.75rem 0 0">
            Vacaciones DOCEO: <a href="<?= e(url('/admin/vacaciones')) ?>">Administrar fechas globales</a>
        </p>

        <div id="schedule-mode-fixed" style="margin-top:1.1rem" <?= $scheduleMode === 'fixed_slots' ? '' : 'hidden' ?>>
            <h3 style="margin:0 0 .45rem;font-size:.98rem;color:var(--doceo-blue)">Horarios fijos (TOEFL)</h3>
            <p class="muted" style="font-size:.8rem;margin:0 0 .55rem">
                Una línea por horario: <code>día|HH:MM|etiqueta</code>.
                Día: 0=Dom … 6=Sáb. Ejemplo: <code>6|11:00|Sábado 11:00</code>
            </p>
            <textarea name="schedule_fixed_slots_text" rows="4"
                      style="<?= e($inputStyle) ?>;font-family:ui-monospace,monospace;font-size:.82rem;width:100%;resize:vertical"
                      placeholder="6|11:00|Sábado 11:00&#10;6|13:00|Sábado 13:00"><?= e((string) ($extras['schedule_fixed_slots_text'] ?? '')) ?></textarea>
            <div style="margin-top:.75rem;padding:.75rem;border:1px solid #dbe3ef;border-radius:12px;background:#f8fafc">
                <label class="muted" style="display:flex;align-items:center;gap:.45rem;font-size:.88rem;font-weight:600">
                    <input type="checkbox" name="extraordinary_enabled" value="1"
                        <?= !empty($extras['extraordinary_enabled']) ? 'checked' : '' ?>>
                    Permitir fecha extraordinaria (con costo extra)
                </label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.65rem;margin-top:.65rem">
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Costo extra ($)
                        <input type="number" step="0.01" min="0" name="extraordinary_surcharge"
                               value="<?= e((string) ($extras['extraordinary_surcharge'] ?? '0')) ?>"
                               style="<?= e($inputStyle) ?>">
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Etiqueta del cargo
                        <input type="text" name="extraordinary_label"
                               value="<?= e((string) ($extras['extraordinary_label'] ?? 'Fecha extraordinaria')) ?>"
                               style="<?= e($inputStyle) ?>">
                    </label>
                    <label class="muted" style="display:flex;align-items:flex-start;gap:.4rem;font-size:.85rem;font-weight:600;margin-top:1.4rem">
                        <input type="checkbox" name="extraordinary_requires_admin" value="1" style="margin-top:.2rem"
                            <?= !empty($extras['extraordinary_requires_admin']) ? 'checked' : '' ?>>
                        Requiere autorización del admin
                    </label>
                </div>
            </div>
        </div>

        <div id="schedule-mode-dated" style="margin-top:1.1rem" <?= $scheduleMode === 'dated_list' ? '' : 'hidden' ?>>
            <h3 style="margin:0 0 .45rem;font-size:.98rem;color:var(--doceo-blue)">Convocatorias del proveedor (Cambridge)</h3>
            <p class="muted" style="font-size:.8rem;margin:0 0 .55rem">
                Una línea por fecha: <code>fecha examen|hora|fecha límite inscripción|etiqueta</code>.
                Ejemplo: <code>2026-11-15|10:00|2026-10-20|Noviembre</code>
            </p>
            <textarea name="schedule_sessions_text" rows="6"
                      style="<?= e($inputStyle) ?>;font-family:ui-monospace,monospace;font-size:.82rem;width:100%;resize:vertical"
                      placeholder="2026-11-15|10:00|2026-10-20|Noviembre&#10;2027-03-12|10:00|2027-02-15|Marzo"><?= e((string) ($extras['schedule_sessions_text'] ?? '')) ?></textarea>
        </div>
    </div>

    <div class="group-panel" data-panel="inventory" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Inventario de códigos (folio/clave)</h2>
        <label class="muted" style="display:flex;align-items:flex-start;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.75rem">
            <input type="checkbox" name="inventory_enabled" id="inventory-enabled-toggle" value="1" style="margin-top:.2rem"
                <?= !empty($extras['inventory_enabled']) ? 'checked' : '' ?>>
            <span>Activar inventario automático en este grupo</span>
        </label>
        <div id="inventory-controls" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Enviar acceso N días antes
                <input type="number" min="0" name="inventory_send_days_before"
                       value="<?= (int) ($extras['inventory_send_days_before'] ?? 3) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Urgencia ≤ días (reasignar)
                <input type="number" min="0" name="inventory_assign_within_days"
                       value="<?= (int) ($extras['inventory_assign_within_days'] ?? 3) ?>"
                       title="Solo si el examen urgente es en ≤ N días se puede quitar un código de alguien con fecha lejana"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Alerta stock ≤
                <input type="number" min="0" name="inventory_low_stock_threshold"
                       value="<?= (int) ($extras['inventory_low_stock_threshold'] ?? 5) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Validez alumno (meses)
                <input type="number" min="1" name="inventory_student_months"
                       value="<?= (int) ($extras['inventory_student_months'] ?? 6) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Validez proveedor (meses)
                <input type="number" min="1" name="inventory_provider_months"
                       value="<?= (int) ($extras['inventory_provider_months'] ?? 12) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Reasignar si faltan ≥ días
                <input type="number" min="1" name="inventory_reallocate_min_future_days"
                       value="<?= (int) ($extras['inventory_reallocate_min_future_days'] ?? 14) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Plantilla alerta stock
                <select name="inventory_low_stock_mail_template" style="<?= e($inputStyle) ?>">
                    <option value="">— Sin alerta —</option>
                    <?php
                    $lowStockTpl = (string) ($extras['inventory_low_stock_mail_template'] ?? '');
                    foreach ($mailTemplates as $tpl):
                        $tplCode = (string) ($tpl['code'] ?? '');
                        if ($tplCode === '') {
                            continue;
                        }
                        ?>
                        <option value="<?= e($tplCode) ?>" <?= $lowStockTpl === $tplCode ? 'selected' : '' ?>>
                            <?= e((string) ($tpl['name'] ?? $tplCode)) ?> (<?= e($tplCode) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label id="inventory-reallocate-label" class="muted" style="display:flex;align-items:center;gap:.45rem;font-size:.88rem;font-weight:600;margin-top:.75rem">
            <input type="checkbox" name="inventory_reallocate_enabled" value="1"
                <?= !isset($extras['inventory_reallocate_enabled']) || !empty($extras['inventory_reallocate_enabled']) ? 'checked' : '' ?>>
            Permitir reasignar códigos de exámenes lejanos si hay urgencia y stock 0
        </label>
    </div>

    <div class="group-panel" data-panel="results" hidden>
        <?php
        $resultsDelivery = is_array($extras['results_delivery'] ?? null)
            ? $extras['results_delivery']
            : \App\Services\ResultsDeliveryService::fromConfig([]);
        $resultsMode = (string) ($resultsDelivery['mode'] ?? \App\Services\ResultsDeliveryService::MODE_NONE);
        $resultsCancelTpl = (string) ($resultsDelivery['cancel_template'] ?? '');
        ?>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Entrega de resultados</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
            Define cómo se publican resultados o cancelaciones desde el detalle del caso.
            Enlace un paso de Progreso con acción
            <strong>Enviar resultados / cancelación</strong> para el botón en Operación.
            Placeholders útiles: <code>results_url</code>, <code>score_report_url</code>,
            <code>results_pdf_url</code>, <code>cancel_reason</code>, <code>results_score</code>,
            <code>results_level</code>.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;max-width:40rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Modo de entrega
                <select name="results_delivery_mode" style="<?= e($inputStyle) ?>">
                    <?php foreach (\App\Services\ResultsDeliveryService::MODES as $modeCode => $modeLabel): ?>
                        <option value="<?= e($modeCode) ?>" <?= $resultsMode === $modeCode ? 'selected' : '' ?>>
                            <?= e($modeLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Plantilla de cancelación
                <select name="results_cancel_template" style="<?= e($inputStyle) ?>">
                    <option value="">— Plantilla —</option>
                    <?php foreach ($mailTemplates as $tpl):
                        $tplCode = (string) ($tpl['code'] ?? '');
                        if ($tplCode === '') {
                            continue;
                        }
                        ?>
                        <option value="<?= e($tplCode) ?>" <?= $resultsCancelTpl === $tplCode ? 'selected' : '' ?>>
                            <?= e((string) ($tpl['name'] ?? $tplCode)) ?> (<?= e($tplCode) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <div class="group-panel" data-panel="extra" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Campo extra de acceso</h2>
        <label class="muted" style="display:flex;align-items:flex-start;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:1rem">
            <input type="checkbox" name="exam_capture_zoom" id="exam-extra-toggle" value="1" style="margin-top:.2rem"
                <?= !empty($extras['exam_capture_zoom']) ? 'checked' : '' ?>>
            <span>Mostrar esta columna en Operación (junto a folio/clave)</span>
        </label>
        <div id="exam-extra-controls">
            <label class="muted" style="<?= e($labelStyle) ?>;max-width:28rem">
                Etiqueta de la columna / dato
                <input type="text" name="exam_extra_field_label"
                       value="<?= e((string) ($extras['exam_extra_field_label'] ?? '')) ?>"
                       placeholder="Ej. Zoom, ID escuela, Código de acceso…"
                       style="<?= e($inputStyle) ?>">
                <span class="muted" style="font-weight:500;font-size:.75rem;margin-top:.25rem;display:block">
                    Si la dejas vacía se usa «Zoom».
                </span>
            </label>
        </div>
    </div>

    <div class="group-panel" data-panel="docs" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Docs para correos</h2>
        <?php
        $instructionDocs = is_array($extras['instruction_docs'] ?? null) ? $extras['instruction_docs'] : [];
        if ($instructionDocs === []) {
            $instructionDocs = [[
                'code' => '',
                'label' => '',
                'kind' => 'file',
                'path' => '',
                'url' => '',
            ]];
        }
        ?>
        <div id="instruction-docs-list" style="display:grid;gap:.85rem">
            <?php foreach ($instructionDocs as $docIdx => $docRow): ?>
                <?php
                $docKind = (string) ($docRow['kind'] ?? 'file');
                if (!in_array($docKind, ['file', 'link', 'video'], true)) {
                    $docKind = 'file';
                }
                $docPath = trim((string) ($docRow['path'] ?? ''));
                $docUrl = trim((string) ($docRow['url'] ?? ''));
                ?>
                <div class="instruction-doc-row panel" style="margin:0;padding:.85rem;border:1px solid #e6edf7;border-radius:12px;background:#fafcff">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.65rem">
                        <label class="muted" style="<?= e($labelStyle) ?>">
                            Código (placeholder)
                            <input type="text" name="instruction_doc_code[]"
                                   value="<?= e((string) ($docRow['code'] ?? '')) ?>"
                                   placeholder="temario"
                                   pattern="[a-z0-9_\-]*"
                                   style="<?= e($inputStyle) ?>">
                        </label>
                        <label class="muted" style="<?= e($labelStyle) ?>">
                            Etiqueta
                            <input type="text" name="instruction_doc_label[]"
                                   value="<?= e((string) ($docRow['label'] ?? '')) ?>"
                                   placeholder="Temario del curso"
                                   style="<?= e($inputStyle) ?>">
                        </label>
                        <label class="muted" style="<?= e($labelStyle) ?>">
                            Tipo
                            <select name="instruction_doc_kind[]" class="instruction-doc-kind" style="<?= e($inputStyle) ?>">
                                <option value="file" <?= $docKind === 'file' ? 'selected' : '' ?>>Archivo (PDF/DOC)</option>
                                <option value="link" <?= $docKind === 'link' ? 'selected' : '' ?>>Enlace externo</option>
                                <option value="video" <?= $docKind === 'video' ? 'selected' : '' ?>>Video (YouTube u otro)</option>
                            </select>
                        </label>
                        <label class="muted instruction-doc-url-wrap" style="<?= e($labelStyle) ?><?= $docKind === 'file' && $docUrl === '' ? ';opacity:.65' : '' ?>">
                            URL (enlace / video)
                            <input type="url" name="instruction_doc_url[]"
                                   value="<?= e($docUrl) ?>"
                                   placeholder="https://…"
                                   style="<?= e($inputStyle) ?>">
                        </label>
                        <label class="muted" style="<?= e($labelStyle) ?>">
                            Subir archivo
                            <input type="hidden" name="instruction_doc_path[]" value="<?= e($docPath) ?>">
                            <input type="file" name="instruction_doc_file[<?= (int) $docIdx ?>]"
                                   accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                   style="<?= e($inputStyle) ?>">
                            <?php if ($docPath !== ''): ?>
                                <span style="font-weight:500;font-size:.75rem">
                                    Actual:
                                    <a href="<?= e(\App\Services\ExamInstructionAssets::absoluteUrl($docPath)) ?>"
                                       target="_blank" rel="noopener">ver archivo</a>
                                </span>
                                <label style="display:flex;align-items:center;gap:.35rem;font-weight:500;font-size:.78rem;margin-top:.35rem">
                                    <input type="checkbox" name="instruction_doc_clear[<?= (int) $docIdx ?>]" value="1"> Quitar archivo subido
                                </label>
                            <?php endif; ?>
                        </label>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm instruction-doc-remove" style="margin-top:.65rem">Quitar fila</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="instruction-doc-add" style="margin-top:.85rem">+ Agregar documento / enlace</button>
        <?php
        $instrPreview = \App\Services\ExamInstructionAssets::mailVars([
            'exam_instructions' => [
                'documents' => $instructionDocs,
            ],
        ]);
        ?>
        <?php if (trim((string) ($instrPreview['instruction_docs_html'] ?? '')) !== ''): ?>
            <div class="callout callout-info" style="margin-top:.9rem;font-size:.85rem">
                <strong>Vista previa de {{instruction_docs_html}}:</strong>
                <?= $instrPreview['instruction_docs_html'] ?>
            </div>
        <?php endif; ?>
        <template id="instruction-doc-row-template">
            <div class="instruction-doc-row panel" style="margin:0;padding:.85rem;border:1px solid #e6edf7;border-radius:12px;background:#fafcff">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.65rem">
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Código (placeholder)
                        <input type="text" name="instruction_doc_code[]" value="" placeholder="temario" style="<?= e($inputStyle) ?>">
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Etiqueta
                        <input type="text" name="instruction_doc_label[]" value="" placeholder="Temario del curso" style="<?= e($inputStyle) ?>">
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Tipo
                        <select name="instruction_doc_kind[]" class="instruction-doc-kind" style="<?= e($inputStyle) ?>">
                            <option value="file" selected>Archivo (PDF/DOC)</option>
                            <option value="link">Enlace externo</option>
                            <option value="video">Video (YouTube u otro)</option>
                        </select>
                    </label>
                    <label class="muted instruction-doc-url-wrap" style="<?= e($labelStyle) ?>">
                        URL (enlace / video)
                        <input type="url" name="instruction_doc_url[]" value="" placeholder="https://…" style="<?= e($inputStyle) ?>">
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Subir archivo
                        <input type="hidden" name="instruction_doc_path[]" value="">
                        <input type="file" data-instruction-doc-file accept=".pdf,.doc,.docx,application/pdf">
                    </label>
                </div>
                <button type="button" class="btn btn-ghost btn-sm instruction-doc-remove" style="margin-top:.65rem">Quitar fila</button>
            </div>
        </template>
    </div>

    <div class="group-panel" data-panel="rules" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Reglamento</h2>
        <label class="muted" style="display:flex;align-items:flex-start;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.75rem">
            <input type="checkbox" name="reglamento_enabled" value="1" style="margin-top:.2rem"
                <?= !empty($extras['reglamento_enabled']) ? 'checked' : '' ?>>
            <span>
                Este grupo requiere reglamento firmado
                <span class="muted" style="display:block;font-weight:500;font-size:.78rem;margin-top:.15rem">
                    Si se marca, el alumno deberá firmarlo <strong>antes de pagar</strong>
                    (queda como paso obligatorio del checkout). No hace falta otro check aparte.
                </span>
            </span>
        </label>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Ruta / URL de la plantilla PDF
                <input type="text" name="reglamento_template_path"
                       value="<?= e((string) ($extras['reglamento_template_path'] ?? '')) ?>"
                       placeholder="/assets/reglamentos/elet-reglamento.pdf"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Link externo (referencia / respaldo)
                <input type="url" name="reglamento_source_url"
                       value="<?= e((string) ($extras['reglamento_source_url'] ?? '')) ?>"
                       placeholder="https://drive.google.com/..."
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Código del documento
                <input type="text" name="reglamento_doc_code" id="reglamento_doc_code"
                       value="<?= e($docCode) ?>"
                       style="<?= e($inputStyle) ?>">
                <span id="doc-code-hint" style="font-weight:500;font-size:.78rem"></span>
            </label>
        </div>
    </div>

    <div class="group-panel" data-panel="payments" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Pagos y MSI</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Elige cómo pueden pagar los alumnos.
        </p>
        <div style="display:flex;flex-direction:column;gap:.55rem;margin-bottom:1rem">
            <label style="display:flex;gap:.45rem;align-items:center;font-weight:600">
                <input type="checkbox" name="pay_transfer" value="1" <?= !empty($extras['pay_transfer']) ? 'checked' : '' ?>>
                Transferencia / CLABE + comprobante
            </label>
            <label style="display:flex;gap:.45rem;align-items:center;font-weight:600">
                <input type="checkbox" name="pay_oxxo" value="1" <?= !empty($extras['pay_oxxo']) ? 'checked' : '' ?>>
                OXXO / tienda OpenPay
            </label>
            <label style="display:flex;gap:.45rem;align-items:center;font-weight:600">
                <input type="checkbox" name="pay_card" value="1" <?= !empty($extras['pay_card']) ? 'checked' : '' ?>>
                Tarjeta crédito / débito (OpenPay)
            </label>
        </div>
        <label style="display:flex;gap:.45rem;align-items:center;font-weight:600;margin-bottom:.65rem">
            <input type="checkbox" name="msi_enabled" value="1" <?= !empty($extras['msi_enabled']) ? 'checked' : '' ?>>
            Permitir meses sin intereses (MSI)
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:.55rem">
            <?php foreach ([1, 3, 6, 9, 12] as $m): ?>
                <label class="day-check">
                    <input type="checkbox" name="msi_months[]" value="<?= (int) $m ?>"
                        <?= in_array($m, $msiMonths, true) ? 'checked' : '' ?>>
                    <?= (int) $m === 1 ? '1 mes' : ($m . ' meses') ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    $pipelines = $pipelines ?? [];
    $pipelineStepsByCode = $pipelineStepsByCode ?? [];
    $selectedPipeline = (string) ($extras['pipeline_code'] ?? '');
    ?>
    <div class="group-panel" data-panel="progress" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Progreso y acciones</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Define los pasos del caso. Cada paso puede ser visible al alumno o solo admin,
            y puede mostrar un <strong>botón en Operación</strong>.
            En el tablero solo aparecen los pasos con «Mostrar en Operación» (más Confirmar pago
            si el caso aún no está pagado). El comprobante DOCEO→proveedor se sube en el detalle del caso.
            El destinatario del correo lo define la <strong>plantilla</strong> (alumno o proveedor/UKS).
        </p>

        <div class="panel" style="margin:0 0 1rem;padding:.85rem 1rem;background:#f8fafc">
            <strong style="color:var(--doceo-blue);font-size:.92rem">Resumen de correos</strong>
            <div id="step-emails-summary" class="muted" style="font-size:.82rem;margin:.45rem 0 0"></div>
        </div>

        <?php if ($pipelines === []): ?>
            <div class="flash flash-error" style="margin:0">
                No hay plantillas de progreso en la base de datos.
                Ejecuta el seed del catálogo o carga grupos sugeridos para crearlas.
            </div>
        <?php else: ?>
            <label class="muted" style="<?= e($labelStyle) ?>;max-width:34rem;margin-bottom:.85rem">
                Plantilla de progreso
                <select name="pipeline_code" id="pipeline-code-select" style="<?= e($inputStyle) ?>">
                    <option value="">— Sin plantilla específica (usa la del tipo de producto) —</option>
                    <?php foreach ($pipelines as $tpl): ?>
                        <option value="<?= e((string) $tpl['code']) ?>"
                            <?= $selectedPipeline === (string) $tpl['code'] ? 'selected' : '' ?>>
                            <?= e((string) $tpl['name']) ?>
                            (<?= e((string) $tpl['code']) ?> · <?= e((string) $tpl['product_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:.55rem">
                <strong style="color:var(--doceo-blue)">Pasos</strong>
                <button type="button" class="btn btn-ghost btn-sm" id="pipeline-add-step">+ Agregar paso</button>
            </div>
            <div id="pipeline-steps-body" class="progress-steps-list"></div>
            <p id="pipeline-steps-empty" class="muted" style="display:none;margin:.5rem 0 0">
                Elige una plantilla para editar sus pasos.
            </p>
        <?php endif; ?>
    </div>

    <div class="group-panel" data-panel="provider" hidden>
        <p class="muted">La solicitud a proveedor ahora se configura como un paso en <strong>Progreso y acciones</strong>
            (acción “Enviar correo”, audiencia proveedor). Esta sección quedó oculta.</p>
    </div>

    <div class="group-panel" data-panel="emails" hidden>
        <p class="muted">Los correos por paso se configuran en cada fila de <strong>Progreso y acciones</strong>.</p>
    </div>

    <div class="group-panel" data-panel="advanced" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Modo experto (JSON)</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
            Vista avanzada del <code>config_json</code>. Al abrir esta pestaña (y al guardar)
            se sincroniza automáticamente con lo configurado en las demás pestañas:
            datos del alumno, horarios, reglamento, pagos y correos. Esas pestañas tienen prioridad
            sobre las mismas claves del JSON.
        </p>
        <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
            Usa el JSON solo para opciones poco frecuentes (documentos del expediente, etc.).
            La tarjeta de progreso se configura en <strong>Progreso</strong>; los correos del ciclo
            del alumno en <strong>Correos</strong>; campos del alumno en <strong>Datos del alumno</strong>.
        </p>
        <details open>
            <summary style="cursor:pointer;font-weight:700;color:var(--doceo-blue)">Mostrar / editar JSON crudo</summary>
            <label class="muted" style="<?= e($labelStyle) ?>;margin-top:.75rem">
                config_json
                <textarea name="config_json" id="group-config-json" rows="18"
                          style="padding:.65rem .75rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem"><?= e($defaultConfig) ?></textarea>
            </label>
            <button type="button" class="btn btn-ghost btn-sm" id="sync-config-json" style="margin-top:.5rem">
                Actualizar JSON desde las pestañas
            </button>
        </details>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.1rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar grupo' : 'Crear grupo' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos')) ?>">Cancelar</a>
    </div>
</form>

<style>
.group-tabs { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:1rem; }
.group-tab {
    border:1px solid #cfd8e6; background:#fff; color:var(--doceo-blue);
    border-radius:999px; padding:.45rem .9rem; font-weight:700; font-size:.86rem; cursor:pointer;
}
.group-tab.active { background:var(--doceo-blue); border-color:var(--doceo-blue); color:#fff; }
.progress-steps-list { display:flex; flex-direction:column; gap:.75rem; }
.progress-step-card {
  border:1px solid #dbe3ef; border-radius:12px; padding:.75rem .85rem; background:#fff;
}
.progress-step-card.is-dragging { opacity:.55; border-style:dashed; }
.progress-step-card.drag-over { border-color:var(--doceo-blue); box-shadow:0 0 0 2px rgba(26,74,140,.15); }
.progress-step-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:.55rem; gap:.5rem; }
.progress-step-drag {
  cursor:grab; user-select:none; color:var(--doceo-muted); font-size:1rem; padding:.15rem .35rem;
  border:1px solid transparent; border-radius:8px; background:transparent;
}
.progress-step-drag:active { cursor:grabbing; }
.progress-step-head-left { display:flex; align-items:center; gap:.45rem; }
.progress-step-grid {
  display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:.55rem;
}
.progress-step-flags {
  display:flex; flex-wrap:wrap; gap:.65rem 1rem; margin:.7rem 0 .35rem; font-size:.84rem;
}
.progress-step-email { margin-top:.45rem; padding-top:.55rem; border-top:1px dashed #e6ebf2; }
.day-check {
    display:inline-flex; align-items:center; gap:.35rem;
    border:1px solid #cfd8e6; border-radius:999px; padding:.35rem .7rem;
    font-size:.86rem; font-weight:600; background:#fff;
}
.day-check:has(input:checked) { background:#eef4ff; border-color:#9db7e8; color:var(--doceo-blue); }
.field-check-grid {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.65rem;
}
.field-check {
    display:flex; gap:.55rem; align-items:flex-start;
    border:1px solid #cfd8e6; border-radius:12px; padding:.7rem .8rem; background:#fff;
    cursor:pointer; font-size:.88rem;
}
.field-check:has(input:checked) { background:#eef4ff; border-color:#9db7e8; }
.field-custom-badge{display:inline-block;margin-left:.35rem;padding:.05rem .4rem;border-radius:999px;background:#e8f0ff;color:#2a4d8f;font-size:.68rem;font-weight:700;vertical-align:middle}
.field-check-main { display:flex; gap:.65rem; align-items:flex-start; cursor:pointer; }
.field-required-toggle {
  display:block; margin:.35rem 0 0 1.55rem;
  font-size:.75rem; font-weight:600;
}
.field-required-select {
  display:block; width:100%; max-width:100%; box-sizing:border-box;
  font:inherit; font-size:.75rem; padding:.25rem .4rem; border:1px solid #cfd8e6; border-radius:8px;
}
.btn-link {
  background:none; border:0; color:var(--doceo-blue); cursor:pointer; font:inherit;
  font-size:.75rem; font-weight:700; text-decoration:underline; margin:.25rem 0 0 1.55rem; padding:0;
}
.btn-link:hover { color:#1a3f73; }
.field-check--locked { opacity:.92; cursor:default; background:#f7f9fc; }
.field-check input { margin-top:.15rem; accent-color:var(--doceo-blue); }
#reglamento_doc_code.is-duplicate { border-color:#d64545 !important; background:#fff5f5; color:#a11; }
.doc-code-error { color:#c0392b; font-weight:700; }
</style>
<script>
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.group-tab[data-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.group-panel[data-panel]'));
  var form = document.getElementById('group-form');
  var jsonTa = document.getElementById('group-config-json');
  var syncBtn = document.getElementById('sync-config-json');
  if (!tabs.length) return;

  function activate(name) {
    if (name === 'advanced') {
      syncJsonFromTabs();
    }
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tab') === name;
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-panel') !== name;
    });
    if (history.replaceState) history.replaceState(null, '', '#' + name);
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { activate(tab.getAttribute('data-tab')); });
  });
  var hash = (location.hash || '').replace(/^#/, '');
  if (hash === 'zoom') hash = 'extra';
  if (hash && document.querySelector('.group-panel[data-panel="' + hash + '"]')) activate(hash);

  function syncScheduleModePanels() {
    var modeEl = document.getElementById('schedule_mode');
    var mode = modeEl ? String(modeEl.value || 'window') : 'window';
    var fixed = document.getElementById('schedule-mode-fixed');
    var dated = document.getElementById('schedule-mode-dated');
    if (fixed) fixed.hidden = mode !== 'fixed_slots';
    if (dated) dated.hidden = mode !== 'dated_list';
  }
  var scheduleModeSelect = document.getElementById('schedule_mode');
  if (scheduleModeSelect) {
    scheduleModeSelect.addEventListener('change', syncScheduleModePanels);
    syncScheduleModePanels();
  }

  function setControlsEnabled(root, enabled) {
    if (!root) return;
    root.style.opacity = enabled ? '1' : '.55';
    root.querySelectorAll('input, select, textarea, button').forEach(function (el) {
      el.disabled = !enabled;
    });
  }

  (function setupInventoryToggle() {
    var toggle = document.getElementById('inventory-enabled-toggle');
    var controls = document.getElementById('inventory-controls');
    var realloc = document.getElementById('inventory-reallocate-label');
    if (!toggle) return;
    function sync() {
      var on = !!toggle.checked;
      setControlsEnabled(controls, on);
      setControlsEnabled(realloc, on);
    }
    toggle.addEventListener('change', sync);
    sync();
  })();

  (function setupExtraFieldToggle() {
    var toggle = document.getElementById('exam-extra-toggle');
    var controls = document.getElementById('exam-extra-controls');
    if (!toggle || !controls) return;
    function sync() {
      setControlsEnabled(controls, !!toggle.checked);
    }
    toggle.addEventListener('change', sync);
    sync();
  })();

  (function setupInstructionDocs() {
    var list = document.getElementById('instruction-docs-list');
    var tpl = document.getElementById('instruction-doc-row-template');
    var addBtn = document.getElementById('instruction-doc-add');
    if (!list || !tpl || !addBtn) return;

    function reindexDocFiles() {
      var rows = list.querySelectorAll('.instruction-doc-row');
      rows.forEach(function (row, idx) {
        var file = row.querySelector('input[type="file"]');
        if (file) file.setAttribute('name', 'instruction_doc_file[' + idx + ']');
        var clear = row.querySelector('[name^="instruction_doc_clear"]');
        if (clear) clear.setAttribute('name', 'instruction_doc_clear[' + idx + ']');
      });
    }

    addBtn.addEventListener('click', function () {
      var node = tpl.content.cloneNode(true);
      list.appendChild(node);
      reindexDocFiles();
    });

    list.addEventListener('click', function (ev) {
      var btn = ev.target && ev.target.closest ? ev.target.closest('.instruction-doc-remove') : null;
      if (!btn) return;
      var row = btn.closest('.instruction-doc-row');
      if (!row) return;
      if (list.querySelectorAll('.instruction-doc-row').length <= 1) {
        row.querySelectorAll('input[type="text"], input[type="url"], input[type="hidden"]').forEach(function (el) {
          el.value = '';
        });
        var kind = row.querySelector('select');
        if (kind) kind.value = 'file';
        var file = row.querySelector('input[type="file"]');
        if (file) file.value = '';
        return;
      }
      row.remove();
      reindexDocFiles();
    });

    reindexDocFiles();
  })();

  var usedDocCodes = <?= json_encode($usedDocCodes, JSON_UNESCAPED_UNICODE) ?>;
  var currentGroupCode = <?= json_encode($groupCode, JSON_UNESCAPED_UNICODE) ?>;
  var docInput = document.getElementById('reglamento_doc_code');
  var codeInput = document.getElementById('group-code');
  var nameInput = form && form.querySelector('input[name="name"]');
  var hint = document.getElementById('doc-code-hint');
  var docTouched = <?= json_encode(trim((string) ($extras['reglamento_doc_code'] ?? '')) !== '') ?>;

  function slugify(v) {
    return String(v || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'nuevo';
  }
  function autoDoc() {
    var code = codeInput && codeInput.value ? codeInput.value : currentGroupCode;
    if (!code && nameInput && nameInput.value) {
      code = nameInput.value;
    }
    return 'reglamento_' + slugify(code);
  }
  function validateDocCode() {
    if (!docInput) return true;
    var val = (docInput.value || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^_|_$/g, '');
    docInput.value = val;
    var owner = usedDocCodes[val];
    var duplicate = !!(owner && owner !== currentGroupCode);
    docInput.classList.toggle('is-duplicate', duplicate);
    if (hint) {
      if (duplicate) {
        hint.innerHTML = '<span class="doc-code-error">Este código ya lo usa el grupo <code>' + owner + '</code>.</span>';
      } else {
        hint.textContent = '';
      }
    }
    return !duplicate;
  }
  if (docInput) {
    docInput.addEventListener('input', function () { docTouched = true; validateDocCode(); });
    validateDocCode();
  }
  if (codeInput && !codeInput.readOnly) {
    codeInput.addEventListener('input', function () {
      if (!docTouched && docInput) {
        docInput.value = autoDoc();
        validateDocCode();
      }
    });
  }
  if (nameInput && !(codeInput && codeInput.readOnly && codeInput.value)) {
    nameInput.addEventListener('input', function () {
      if (!docTouched && docInput) {
        docInput.value = autoDoc();
        validateDocCode();
      }
    });
  }

  function checked(name) {
    var el = form && form.querySelector('[name="' + name + '"]');
    return !!(el && el.checked);
  }
  function val(name, fallback) {
    var el = form && form.querySelector('[name="' + name + '"]');
    if (!el) return fallback;
    var v = String(el.value || '').trim();
    return v !== '' ? v : fallback;
  }
  function intVal(name, fallback) {
    var n = parseInt(val(name, String(fallback)), 10);
    return isNaN(n) ? fallback : n;
  }
  function selectedFields() {
    var out = [];
    if (!form) return out;
    form.querySelectorAll('input[name="checkout_fields[]"]').forEach(function (el) {
      if (el.type === 'hidden' || el.checked) {
        if (out.indexOf(el.value) === -1) out.push(el.value);
      }
    });
    ['email', 'first_name', 'last_name_p', 'phone'].forEach(function (must) {
      if (out.indexOf(must) === -1) out.unshift(must);
    });
    return out;
  }
  function selectedFieldRequiredMap() {
    var map = {};
    var selected = selectedFields();
    selected.forEach(function (code) {
      if (['email', 'first_name', 'last_name_p', 'phone'].indexOf(code) !== -1) return;
      var sel = form && form.querySelector('select[name="checkout_field_required[' + code + ']"]');
      if (sel) map[code] = sel.value === '1';
    });
    return map;
  }
  function selectedDays() {
    var days = {};
    [0, 1, 2, 3, 4, 5, 6].forEach(function (d) {
      var el = form && form.querySelector('[name="schedule_days[' + d + ']"]');
      days[String(d)] = !!(el && el.checked);
    });
    return days;
  }
  function selectedMsiMonths() {
    var months = [];
    if (!form) return [1];
    form.querySelectorAll('input[name="msi_months[]"]:checked').forEach(function (el) {
      months.push(parseInt(el.value, 10));
    });
    months = months.filter(function (m) { return [1, 3, 6, 9, 12].indexOf(m) !== -1; });
    return months.length ? months : [1];
  }
  function paymentOrder() {
    var order = [];
    if (checked('pay_transfer')) order.push('transfer_proof');
    if (checked('pay_oxxo')) order.push('openpay_store');
    if (checked('pay_card')) order.push('openpay_card');
    return order.length ? order : ['transfer_proof', 'openpay_store', 'openpay_card'];
  }

  function syncJsonFromTabs() {
    if (!jsonTa) return;
    var base = {};
    try {
      base = JSON.parse(jsonTa.value || '{}') || {};
    } catch (e) {
      base = {};
    }
    if (typeof base !== 'object' || Array.isArray(base) || base === null) base = {};

    base.checkout_fields = selectedFields();
    base.checkout_field_required = selectedFieldRequiredMap();
    base.exam = Object.assign({}, base.exam || {}, {
      choose_at_checkout: checked('exam_choose_at_checkout'),
      slot_minutes: Math.max(15, intVal('exam_slot_minutes', 30)),
      validity_months: Math.max(1, Math.min(36, intVal('exam_validity_months', 6))),
      capture_zoom: checked('exam_capture_zoom')
    });
    var extraLabel = val('exam_extra_field_label', '');
    if (extraLabel) {
      base.exam.extra_field_label = extraLabel;
    } else {
      delete base.exam.extra_field_label;
    }
    var instrDocs = [];
    var docRows = document.querySelectorAll('#instruction-docs-list .instruction-doc-row');
    docRows.forEach(function (row) {
      var codeEl = row.querySelector('[name="instruction_doc_code[]"]');
      var labelEl = row.querySelector('[name="instruction_doc_label[]"]');
      var kindEl = row.querySelector('[name="instruction_doc_kind[]"]');
      var urlEl = row.querySelector('[name="instruction_doc_url[]"]');
      var pathEl = row.querySelector('[name="instruction_doc_path[]"]');
      var clearEl = row.querySelector('[name^="instruction_doc_clear"]');
      var code = codeEl ? String(codeEl.value || '').trim() : '';
      var label = labelEl ? String(labelEl.value || '').trim() : '';
      var kind = kindEl ? String(kindEl.value || 'file') : 'file';
      var url = urlEl ? String(urlEl.value || '').trim() : '';
      var path = pathEl ? String(pathEl.value || '').trim() : '';
      if (clearEl && clearEl.checked) path = '';
      if (!code && !label && !url && !path) return;
      if (!code) {
        code = (label || (kind === 'video' ? 'video' : 'doc')).toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^_|_$/g, '');
      }
      if (!code) return;
      if (kind === 'file' && !path && !url) return;
      if ((kind === 'link' || kind === 'video') && !url) return;
      instrDocs.push({
        code: code,
        label: label || code,
        kind: kind,
        path: path || null,
        url: url || null
      });
    });
    if (instrDocs.length) {
      var firstFile = null;
      var firstVideo = null;
      instrDocs.forEach(function (d) {
        if (!firstFile && (d.kind === 'file' || d.kind === 'link')) firstFile = d;
        if (!firstVideo && d.kind === 'video') firstVideo = d;
      });
      base.exam_instructions = {
        pdf_path: firstFile && firstFile.path ? firstFile.path : null,
        pdf_url: firstFile && firstFile.url ? firstFile.url : null,
        pdf_label: firstFile ? (firstFile.label || 'Guía / PDF de instrucciones') : 'Guía / PDF de instrucciones',
        video_url: firstVideo && firstVideo.url ? firstVideo.url : null,
        video_label: firstVideo ? (firstVideo.label || 'Video de instrucciones') : 'Video de instrucciones',
        documents: instrDocs
      };
    } else {
      delete base.exam_instructions;
    }
    var scheduleModeEl = document.querySelector('[name="schedule_mode"]');
    var scheduleMode = scheduleModeEl ? String(scheduleModeEl.value || 'window') : 'window';
    base.schedule = Object.assign({}, base.schedule || {}, {
      mode: scheduleMode,
      min_advance_days: Math.max(0, intVal('schedule_min_advance_days', 2)),
      available_365: checked('schedule_available_365'),
      checkout_help: val('schedule_checkout_help', ''),
      days: selectedDays(),
      weekdays: {
        start: val('schedule_weekdays_start', '10:00'),
        end: val('schedule_weekdays_end', '17:30')
      },
      saturday: {
        start: val('schedule_saturday_start', '08:00'),
        end: val('schedule_saturday_end', '12:00')
      }
    });
    delete base.schedule.blocked_dates;

    var order = paymentOrder();
    base.payments = Object.assign({}, base.payments || {}, {
      default_method: order[0],
      order: order,
      price_includes_fee: !!(base.payments && base.payments.price_includes_fee)
    });
    base.card_msi = {
      enabled: checked('msi_enabled'),
      months: selectedMsiMonths(),
      min_amount: 0
    };

    if (checked('reglamento_enabled')) {
      var path = val('reglamento_template_path', '');
      var source = val('reglamento_source_url', '');
      var doc = val('reglamento_doc_code', autoDoc()).toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^_|_$/g, '');
      base.reglamento = {
        template_path: path,
        source_url: source,
        signature_mode: 'append_to_pdf',
        required_before_checkout: true,
        doc_code: doc || autoDoc()
      };
    } else {
      delete base.reglamento;
    }

    var pipelineCode = val('pipeline_code', '');
    if (pipelineCode) base.pipeline_code = pipelineCode;
    else delete base.pipeline_code;
    delete base.initial_step_code;

    if (document.getElementById('provider-request-enabled') && document.getElementById('provider-request-enabled').checked) {
      var cellMap = [];
      document.querySelectorAll('#provider-cell-map .provider-cell-row').forEach(function (row) {
        var cell = row.querySelector('input[name="provider_request_cells[]"]');
        var field = row.querySelector('select[name="provider_request_fields[]"]');
        if (cell && field && cell.value && field.value) {
          cellMap.push({ cell: String(cell.value).toUpperCase(), field: field.value });
        }
      });
      base.provider_request = {
        enabled: true,
        auto_send_on_payment: !!(document.querySelector('[name="provider_request_auto_send"]') || {}).checked,
        require_admin_payment_proof: !!(document.querySelector('[name="provider_request_require_admin_proof"]') || {}).checked,
        auto_send_on_admin_proof: !!(document.querySelector('[name="provider_request_auto_send_admin_proof"]') || {}).checked,
        step_code: val('provider_request_step_code', 'solicitud_proveedor'),
        to: val('provider_request_to', ''),
        cc: val('provider_request_cc', ''),
        mail_template_code: val('provider_request_mail_template', ''),
        include_student_data: true,
        include_exam_schedule: true,
        include_reglamento: !!(document.querySelector('[name="provider_request_include_reglamento"]') || {}).checked,
        include_payment_proof: !!(document.querySelector('[name="provider_request_include_proof"]') || {}).checked,
        require_reglamento: !!(document.querySelector('[name="provider_request_require_reglamento"]') || {}).checked,
        delivery: 'links',
        workbook: {
          enabled: !!(document.querySelector('[name="provider_request_workbook_enabled"]') || {}).checked,
          template_path: (base.provider_request && base.provider_request.workbook && base.provider_request.workbook.template_path) || '',
          attach: false,
          sheet: val('provider_request_workbook_sheet', ''),
          normalize: val('provider_request_workbook_normalize', 'none'),
          cell_map: cellMap
        }
      };
    } else {
      delete base.provider_request;
    }

    var onSteps = [];
    document.querySelectorAll('#email-step-rows .email-step-row').forEach(function (row) {
      var stepEl = row.querySelector('[name="email_step_codes[]"]');
      var tplEl = row.querySelector('[name="email_step_templates[]"]');
      var modeEl = row.querySelector('[name="email_step_modes[]"]');
      var audEl = row.querySelector('[name="email_step_audiences[]"]');
      var step = stepEl ? String(stepEl.value || '').trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_|_$/g, '') : '';
      var tpl = tplEl ? String(tplEl.value || '').trim() : '';
      if (!step || !tpl) return;
      onSteps.push({
        step_code: step,
        template_code: tpl,
        mode: modeEl && modeEl.value === 'auto' ? 'auto' : 'admin',
        audience: audEl && audEl.value === 'provider' ? 'provider' : 'student'
      });
    });
    function emailTpl(name, fallback) {
      var el = document.querySelector('[name="' + name + '"]');
      var v = el ? String(el.value || '').trim() : '';
      return v || fallback;
    }
    // Correo de accesos: si ya no hay checkbox de ciclo, derivarlo del paso exam_access.
    var examAccessEmail = (function () {
      var cycleCb = document.querySelector('[name="email_exam_access_enabled"]');
      if (cycleCb) {
        return {
          enabled: !!cycleCb.checked,
          template_code: emailTpl('email_exam_access_template', 'student_elet_exam_access'),
          mode: (function () {
            var m = document.querySelector('[name="email_exam_access_mode"]');
            return m && m.value === 'auto' ? 'auto' : 'admin';
          })()
        };
      }
      var enabled = false;
      var template = 'student_elet_exam_access';
      var mode = 'admin';
      if (typeof currentStepsFromDom === 'function') {
        currentStepsFromDom().forEach(function (s) {
          if (enabled) return;
          if (String(s.action || '') !== 'exam_access') return;
          if (s.email_enabled && String(s.email_template || '').trim()) {
            enabled = true;
            template = String(s.email_template).trim();
            mode = s.email_trigger === 'auto' ? 'auto' : 'admin';
          }
        });
      }
      return { enabled: enabled, template_code: template, mode: mode };
    })();
    base.emails = {
      // Legacy: ya no se configuran aquí; usar pasos del progreso.
      student_registration: {
        enabled: false,
        template_code: 'student_registration'
      },
      student_payment_confirmed: {
        enabled: false,
        template_code: 'student_payment_confirmed'
      },
      student_exam_access: examAccessEmail,
      on_steps: onSteps
    };

    jsonTa.value = JSON.stringify(base, null, 2);
  }

  if (syncBtn) {
    syncBtn.addEventListener('click', function () { syncJsonFromTabs(); });
  }
  if (form) {
    form.addEventListener('submit', function (e) {
      syncJsonFromTabs();
      if (!validateDocCode()) {
        e.preventDefault();
        activate('rules');
        if (docInput) docInput.focus();
        alert('El código del documento ya está en uso. Cámbialo antes de guardar.');
      }
    });
  }

  var addFieldBtn = document.getElementById('add-checkout-field-btn');
  var addFieldMsg = document.getElementById('add-checkout-field-msg');
  var fieldGrid = document.getElementById('checkout-field-grid');
  var editPanel = document.getElementById('edit-checkout-field-panel');
  var editMsg = document.getElementById('edit-checkout-field-msg');
  var newTypeEl = document.getElementById('new_checkout_field_type');
  var newOptionsWrap = document.getElementById('new-checkout-field-options-wrap');
  var newOptionsEl = document.getElementById('new_checkout_field_options');
  var editTypeEl = document.getElementById('edit_checkout_field_type');
  var editOptionsWrap = document.getElementById('edit-checkout-field-options-wrap');
  var editOptionsEl = document.getElementById('edit_checkout_field_options');

  function toggleOptionsWrap(typeEl, wrapEl) {
    if (!wrapEl) return;
    wrapEl.hidden = !(typeEl && typeEl.value === 'select');
  }
  function optionsToText(options) {
    if (!Array.isArray(options)) return '';
    return options.map(function (o) {
      return String((o && (o.label || o.value)) || '').trim();
    }).filter(Boolean).join('\n');
  }
  if (newTypeEl) {
    newTypeEl.addEventListener('change', function () { toggleOptionsWrap(newTypeEl, newOptionsWrap); });
    toggleOptionsWrap(newTypeEl, newOptionsWrap);
  }
  if (editTypeEl) {
    editTypeEl.addEventListener('change', function () { toggleOptionsWrap(editTypeEl, editOptionsWrap); });
  }

  function showAddFieldMsg(text, isError) {
    if (!addFieldMsg) return;
    addFieldMsg.style.display = 'block';
    addFieldMsg.style.color = isError ? '#b42318' : '#176b3a';
    addFieldMsg.textContent = text;
  }
  function showEditFieldMsg(text, isError) {
    if (!editMsg) return;
    editMsg.style.display = 'block';
    editMsg.style.color = isError ? '#b42318' : '#176b3a';
    editMsg.textContent = text;
  }
  function csrfToken() {
    var csrf = form && (form.querySelector('input[name="_csrf"]') || form.querySelector('input[name="csrf_token"]'));
    return csrf ? csrf.value : '';
  }
  function appendFieldCard(field) {
    if (!fieldGrid || !field || !field.code) return;
    var existingCard = fieldGrid.querySelector('[data-field-code="' + field.code + '"]');
    if (existingCard) {
      var existing = existingCard.querySelector('input.field-include');
      if (existing && !existing.disabled) existing.checked = true;
      var reqSel = existingCard.querySelector('.field-required-select');
      if (reqSel) reqSel.value = field.required ? '1' : '0';
      var strong = existingCard.querySelector('.field-label-text');
      if (strong && field.label) strong.textContent = field.label;
      return;
    }
    var card = document.createElement('div');
    card.className = 'field-check';
    card.setAttribute('data-field-code', field.code);
    card.setAttribute('data-custom', '1');
    card.innerHTML = ''
      + '<label class="field-check-main">'
      + '<input type="checkbox" class="field-include" name="checkout_fields[]" value="' + field.code + '" checked>'
      + '<span><strong class="field-label-text"></strong>'
      + '<span class="field-custom-badge">Personalizado</span></span></label>'
      + '<label class="field-required-toggle muted" title="Si el campo se pide al alumno">'
      + '<select name="checkout_field_required[' + field.code + ']" class="field-required-select" aria-label="Obligatorio u opcional">'
      + '<option value="1">Obligatorio</option><option value="0">Opcional</option></select></label>'
      + '<button type="button" class="btn-link field-edit-btn">Editar campo</button>';
    card.querySelector('.field-label-text').textContent = field.label || field.code;
    card.querySelector('.field-required-select').value = field.required ? '1' : '0';
    var editBtn = card.querySelector('.field-edit-btn');
    editBtn.setAttribute('data-code', field.code);
    editBtn.setAttribute('data-label', field.label || field.code);
    editBtn.setAttribute('data-type', field.type || 'text');
    editBtn.setAttribute('data-required', field.required ? '1' : '0');
    editBtn.setAttribute('data-options', optionsToText(field.options || []));
    fieldGrid.appendChild(card);
  }
  if (addFieldBtn) {
    addFieldBtn.addEventListener('click', function () {
      var labelEl = document.getElementById('new_checkout_field_label');
      var typeEl = document.getElementById('new_checkout_field_type');
      var reqEl = document.getElementById('new_checkout_field_required');
      var label = labelEl ? String(labelEl.value || '').trim() : '';
      var type = typeEl ? typeEl.value : 'text';
      var optionsText = newOptionsEl ? String(newOptionsEl.value || '') : '';
      if (!label) {
        showAddFieldMsg('Escribe el nombre del campo.', true);
        if (labelEl) labelEl.focus();
        return;
      }
      if (type === 'select' && !optionsText.trim()) {
        showAddFieldMsg('Indica al menos una opción (una por línea).', true);
        if (newOptionsEl) newOptionsEl.focus();
        return;
      }
      addFieldBtn.disabled = true;
      var body = new FormData();
      body.append('_csrf', csrfToken());
      body.append('label', label);
      body.append('type', type);
      if (reqEl && String(reqEl.value) === '1') body.append('required', '1');
      if (type === 'select') body.append('options_text', optionsText);
      fetch(<?= json_encode(url('/admin/campos-checkout')) ?>, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
        .then(function (res) {
          if (!res.ok || !res.data || !res.data.ok) {
            throw new Error((res.data && res.data.error) || 'No se pudo crear el campo.');
          }
          appendFieldCard(res.data.field);
          if (labelEl) labelEl.value = '';
          if (reqEl) reqEl.value = '1';
          if (newOptionsEl) newOptionsEl.value = '';
          if (typeEl) typeEl.value = 'text';
          toggleOptionsWrap(typeEl, newOptionsWrap);
          showAddFieldMsg('Campo agregado al catálogo global y marcado en este grupo.', false);
          if (typeof syncJsonFromTabs === 'function') syncJsonFromTabs();
        })
        .catch(function (err) {
          showAddFieldMsg(err.message || 'Error al agregar el campo.', true);
        })
        .finally(function () { addFieldBtn.disabled = false; });
    });
  }

  function openEditPanel(btn) {
    if (!editPanel || !btn) return;
    document.getElementById('edit_checkout_field_code').value = btn.getAttribute('data-code') || '';
    document.getElementById('edit_checkout_field_label').value = btn.getAttribute('data-label') || '';
    document.getElementById('edit_checkout_field_type').value = btn.getAttribute('data-type') || 'text';
    document.getElementById('edit_checkout_field_required').value = btn.getAttribute('data-required') === '1' ? '1' : '0';
    if (editOptionsEl) editOptionsEl.value = btn.getAttribute('data-options') || '';
    toggleOptionsWrap(editTypeEl, editOptionsWrap);
    editPanel.hidden = false;
    if (editMsg) editMsg.style.display = 'none';
    editPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  if (fieldGrid) {
    fieldGrid.addEventListener('click', function (e) {
      var btn = e.target.closest('.field-edit-btn');
      if (!btn) return;
      e.preventDefault();
      openEditPanel(btn);
    });
  }
  var cancelEditBtn = document.getElementById('cancel-edit-checkout-field-btn');
  if (cancelEditBtn) {
    cancelEditBtn.addEventListener('click', function () {
      if (editPanel) editPanel.hidden = true;
    });
  }
  var saveEditBtn = document.getElementById('save-checkout-field-btn');
  if (saveEditBtn) {
    saveEditBtn.addEventListener('click', function () {
      var code = document.getElementById('edit_checkout_field_code').value;
      var label = String(document.getElementById('edit_checkout_field_label').value || '').trim();
      var type = document.getElementById('edit_checkout_field_type').value || 'text';
      var required = document.getElementById('edit_checkout_field_required').value === '1';
      var optionsText = editOptionsEl ? String(editOptionsEl.value || '') : '';
      if (!code || !label) {
        showEditFieldMsg('Indica el nombre del campo.', true);
        return;
      }
      if (type === 'select' && !optionsText.trim()) {
        showEditFieldMsg('Indica al menos una opción (una por línea).', true);
        return;
      }
      saveEditBtn.disabled = true;
      var body = new FormData();
      body.append('_csrf', csrfToken());
      body.append('code', code);
      body.append('label', label);
      body.append('type', type);
      if (required) body.append('required', '1');
      if (type === 'select') body.append('options_text', optionsText);
      fetch(<?= json_encode(url('/admin/campos-checkout/editar')) ?>, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
        .then(function (res) {
          if (!res.ok || !res.data || !res.data.ok) {
            throw new Error((res.data && res.data.error) || 'No se pudo guardar el campo.');
          }
          var field = res.data.field;
          var card = fieldGrid && fieldGrid.querySelector('[data-field-code="' + field.code + '"]');
          if (card) {
            var strong = card.querySelector('.field-label-text');
            if (strong) strong.textContent = field.label;
            var reqSel = card.querySelector('.field-required-select');
            if (reqSel) reqSel.value = field.required ? '1' : '0';
            var editBtn = card.querySelector('.field-edit-btn');
            if (editBtn) {
              editBtn.setAttribute('data-label', field.label);
              editBtn.setAttribute('data-type', field.type);
              editBtn.setAttribute('data-required', field.required ? '1' : '0');
              editBtn.setAttribute('data-options', optionsToText(field.options || []));
            }
          }
          showEditFieldMsg('Campo actualizado en el catálogo global.', false);
          if (typeof syncJsonFromTabs === 'function') syncJsonFromTabs();
        })
        .catch(function (err) {
          showEditFieldMsg(err.message || 'Error al guardar.', true);
        })
        .finally(function () { saveEditBtn.disabled = false; });
    });
  }


  // ---- Editor de progreso (tarjeta + acciones ops) ----
  var stepsByCode = <?= json_encode($pipelineStepsByCode ?? [], JSON_UNESCAPED_UNICODE) ?> || {};
  var stepDefs = <?= json_encode($extras['step_defs'] ?? [], JSON_UNESCAPED_UNICODE) ?> || {};
  var mailTemplatesJs = <?= json_encode(array_values(array_map(static function ($t) {
      return ['code' => (string) ($t['code'] ?? ''), 'name' => (string) ($t['name'] ?? '')];
  }, $mailTemplates)), JSON_UNESCAPED_UNICODE) ?> || [];
  var csvTemplatesJs = <?= json_encode(array_values(array_map(static function ($t) {
      return ['code' => (string) ($t['code'] ?? ''), 'name' => (string) ($t['name'] ?? '')];
  }, $csvTemplates)), JSON_UNESCAPED_UNICODE) ?> || [];
  var actionOptions = <?= json_encode(\App\Services\GroupStepConfig::ACTIONS_EDITABLE, JSON_UNESCAPED_UNICODE) ?>;
  var allActionLabels = <?= json_encode(\App\Services\GroupStepConfig::ACTIONS, JSON_UNESCAPED_UNICODE) ?>;
  var opsIconsJs = <?= json_encode(\App\Services\GroupStepConfig::OPS_ICONS, JSON_UNESCAPED_UNICODE) ?>;
  var pipelineSelect = document.getElementById('pipeline-code-select');
  var stepsBody = document.getElementById('pipeline-steps-body');
  var stepsEmpty = document.getElementById('pipeline-steps-empty');
  var addStepBtn = document.getElementById('pipeline-add-step');
  var actors = [
    { value: 'system', label: 'Sistema' },
    { value: 'admin', label: 'Admin' },
    { value: 'student', label: 'Alumno' },
    { value: 'partner', label: 'Partner' },
    { value: 'provider', label: 'Proveedor' }
  ];
  var inp = 'padding:.4rem .5rem;border:1px solid #cfd8e6;border-radius:8px;font:inherit;width:100%';

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function slugStepCode(label) {
    return String(label || '')
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_|_$/g, '') || 'paso';
  }

  function mergeStepDef(s) {
    var def = stepDefs[s.code] || {};
    return Object.assign({}, s, {
      admin_only: def.admin_only ? 1 : 0,
      ops_button: def.ops_button ? 1 : 0,
      ops_label: def.ops_label || '',
      ops_icon: def.ops_icon || s.ops_icon || '',
      action: def.action || 'none',
      email_enabled: def.email && def.email.enabled ? 1 : 0,
      email_trigger: (def.email && def.email.trigger) || 'admin',
      email_template: (def.email && def.email.template_code) || '',
      email_to: (def.email && def.email.to) || '',
      email_cc: (def.email && def.email.cc) || '',
      csv_template: (def.csv && def.csv.template_code) || s.csv_template || '',
      csv_scope: (def.csv && def.csv.scope) || s.csv_scope || 'student'
    });
  }

  function currentStepsFromDom() {
    if (!stepsBody) return [];
    var rows = [];
    stepsBody.querySelectorAll('.progress-step-card').forEach(function (card) {
      var g = function (field) { return card.querySelector('[data-field="' + field + '"]'); };
      var code = g('code');
      var label = g('label');
      var actor = g('actor');
      var adminOnly = g('admin_only');
      var opsBtn = g('ops_button');
      var opsLabel = g('ops_label');
      var opsIcon = g('ops_icon');
      var action = g('action');
      var emailEn = g('email_enabled');
      var emailTr = g('email_trigger');
      var emailTpl = g('email_template');
      var csvTpl = g('csv_template');
      var csvScope = g('csv_scope');
      var labelVal = label ? label.value : '';
      var codeVal = code ? code.value : '';
      if (!codeVal && labelVal) codeVal = slugStepCode(labelVal);
      rows.push({
        code: codeVal,
        label: labelVal,
        actor: actor ? actor.value : 'admin',
        is_terminal: false,
        admin_only: !!(adminOnly && adminOnly.checked),
        ops_button: !!(opsBtn && opsBtn.checked),
        ops_label: opsLabel ? opsLabel.value : '',
        ops_icon: opsIcon ? opsIcon.value : '',
        action: action ? action.value : 'none',
        email_enabled: !!(emailEn && emailEn.checked),
        email_trigger: emailTr ? emailTr.value : 'admin',
        email_template: emailTpl ? emailTpl.value : '',
        email_to: '',
        email_cc: '',
        csv_template: csvTpl ? csvTpl.value : '',
        csv_scope: csvScope ? csvScope.value : 'student'
      });
    });
    if (rows.length) {
      rows[rows.length - 1].is_terminal = true;
    }
    return rows;
  }

  function mailTplSelect(name, idx, value) {
    var html = '<select data-field="email_template" name="pipeline_steps[' + idx + '][email_template]" style="' + inp + '">';
    html += '<option value="">— Plantilla —</option>';
    var found = false;
    mailTemplatesJs.forEach(function (t) {
      if (!t.code) return;
      var sel = t.code === value;
      if (sel) found = true;
      html += '<option value="' + escapeHtml(t.code) + '"' + (sel ? ' selected' : '') + '>'
        + escapeHtml(t.name || t.code) + ' (' + escapeHtml(t.code) + ')</option>';
    });
    if (value && !found) {
      html += '<option value="' + escapeHtml(value) + '" selected>' + escapeHtml(value) + '</option>';
    }
    html += '</select>';
    return html;
  }

  function csvTplSelect(idx, value) {
    var html = '<select data-field="csv_template" name="pipeline_steps[' + idx + '][csv_template]" style="' + inp + '">';
    html += '<option value="">— Plantilla CSV —</option>';
    var found = false;
    csvTemplatesJs.forEach(function (t) {
      if (!t.code) return;
      var sel = t.code === value;
      if (sel) found = true;
      html += '<option value="' + escapeHtml(t.code) + '"' + (sel ? ' selected' : '') + '>'
        + escapeHtml(t.name || t.code) + ' (' + escapeHtml(t.code) + ')</option>';
    });
    if (value && !found) {
      html += '<option value="' + escapeHtml(value) + '" selected>' + escapeHtml(value) + '</option>';
    }
    html += '</select>';
    return html;
  }

  function csvScopeSelect(idx, value) {
    var v = value === 'exam_date' ? 'exam_date' : 'student';
    return '<select data-field="csv_scope" name="pipeline_steps[' + idx + '][csv_scope]" style="' + inp + '">'
      + '<option value="student"' + (v === 'student' ? ' selected' : '') + '>Solo este alumno</option>'
      + '<option value="exam_date"' + (v === 'exam_date' ? ' selected' : '') + '>Todos del mismo día + misma certificación</option>'
      + '</select>';
  }

  function opsIconSelect(idx, value) {
    var html = '<select data-field="ops_icon" name="pipeline_steps[' + idx + '][ops_icon]" style="' + inp + '">';
    html += '<option value="">— Automático —</option>';
    Object.keys(opsIconsJs || {}).forEach(function (k) {
      html += '<option value="' + escapeHtml(k) + '"' + (value === k ? ' selected' : '') + '>'
        + escapeHtml(opsIconsJs[k] || k) + '</option>';
    });
    if (value && !(opsIconsJs && opsIconsJs[value])) {
      html += '<option value="' + escapeHtml(value) + '" selected>' + escapeHtml(value) + '</option>';
    }
    html += '</select>';
    return html;
  }

  function syncStepActionUi(card) {
    if (!card) return;
    var actionEl = card.querySelector('[data-field="action"]');
    var emailBox = card.querySelector('.progress-step-email');
    var csvBox = card.querySelector('.progress-step-csv');
    var emailFlag = card.querySelector('[data-field="email_enabled"]');
    var tplLabel = card.querySelector('.progress-step-tpl-label');
    var action = actionEl ? actionEl.value : 'none';
    var isCsv = action === 'download_csv';
    var isResults = action === 'send_results';
    if (emailBox) emailBox.style.display = isCsv ? 'none' : '';
    if (csvBox) csvBox.style.display = isCsv ? '' : 'none';
    if (emailFlag && emailFlag.closest('label')) {
      emailFlag.closest('label').style.display = (isCsv || isResults) ? 'none' : '';
    }
    if (isResults && emailFlag) {
      emailFlag.checked = true;
    }
    if (tplLabel) {
      tplLabel.textContent = isResults ? 'Plantilla de resultados' : 'Plantilla';
    }
  }

  function actionSelectHtml(idx, current) {
    var legacyMap = <?= json_encode(\App\Services\GroupStepConfig::LEGACY_ACTION_MAP, JSON_UNESCAPED_UNICODE) ?>;
    var selected = current || 'none';
    if (!actionOptions[selected] && legacyMap[selected]) {
      selected = legacyMap[selected];
    }
    var opts = Object.assign({}, actionOptions);
    if (current && !actionOptions[current] && allActionLabels[current]) {
      opts[current] = allActionLabels[current];
    }
    var html = '<select data-field="action" name="pipeline_steps[' + idx + '][action]" style="' + inp + '">';
    Object.keys(opts).forEach(function (k) {
      html += '<option value="' + escapeHtml(k) + '"' + (selected === k ? ' selected' : '') + '>'
        + escapeHtml(opts[k]) + '</option>';
    });
    html += '</select>';
    return html;
  }

  function renderSteps(steps) {
    if (!stepsBody) return;
    stepsBody.innerHTML = '';
    if (!steps || !steps.length) {
      if (stepsEmpty) stepsEmpty.style.display = 'block';
      return;
    }
    if (stepsEmpty) stepsEmpty.style.display = 'none';
    steps.forEach(function (raw, idx) {
      var s = mergeStepDef(raw);
      if (!s.code && s.label) s.code = slugStepCode(s.label);
      var card = document.createElement('div');
      card.className = 'progress-step-card';
      var actorOpts = actors.map(function (a) {
        return '<option value="' + a.value + '"' + ((s.actor || 'admin') === a.value ? ' selected' : '') + '>' + a.label + '</option>';
      }).join('');
      var isCsv = (s.action || 'none') === 'download_csv';
      var isResults = (s.action || 'none') === 'send_results';
      var emailChecked = s.email_enabled == 1 || s.email_enabled === true || isResults;
      card.innerHTML =
        '<div class="progress-step-head">' +
          '<div class="progress-step-head-left">' +
            '<button type="button" class="progress-step-drag" title="Arrastrar para reordenar" aria-label="Arrastrar">☰</button>' +
            '<strong class="muted">#' + (idx + 1) + '</strong>' +
          '</div>' +
          '<button type="button" class="btn btn-ghost btn-sm pipeline-remove-step" title="Quitar">✕</button>' +
        '</div>' +
        '<div class="progress-step-grid">' +
          '<input type="hidden" data-field="code" name="pipeline_steps[' + idx + '][code]" value="' + escapeHtml(s.code || '') + '">' +
          '<label class="muted">Etiqueta<input data-field="label" name="pipeline_steps[' + idx + '][label]" value="' + escapeHtml(s.label || '') + '" style="' + inp + '" required></label>' +
          '<label class="muted">Actor<select data-field="actor" name="pipeline_steps[' + idx + '][actor]" style="' + inp + '">' + actorOpts + '</select></label>' +
          '<label class="muted">Acción en Operación' + actionSelectHtml(idx, s.action || 'none') + '</label>' +
          '<label class="muted">Texto del botón<input data-field="ops_label" name="pipeline_steps[' + idx + '][ops_label]" value="' + escapeHtml(s.ops_label || '') + '" placeholder="Ej. Descargar CSV registro" style="' + inp + '"></label>' +
          '<label class="muted">Icono' + opsIconSelect(idx, s.ops_icon || '') + '</label>' +
        '</div>' +
        '<div class="progress-step-flags">' +
          '<label><input data-field="ops_button" type="checkbox" name="pipeline_steps[' + idx + '][ops_button]" value="1"' + (s.ops_button == 1 || s.ops_button === true ? ' checked' : '') + '> Mostrar en Operación</label>' +
          '<label><input data-field="admin_only" type="checkbox" name="pipeline_steps[' + idx + '][admin_only]" value="1"' + (s.admin_only == 1 || s.admin_only === true ? ' checked' : '') + '> Solo admin (oculto al alumno)</label>' +
          '<label' + ((isCsv || isResults) ? ' style="display:none"' : '') + '><input data-field="email_enabled" type="checkbox" name="pipeline_steps[' + idx + '][email_enabled]" value="1"' + (emailChecked ? ' checked' : '') + '> Enviar correo</label>' +
        '</div>' +
        '<div class="progress-step-grid progress-step-email"' + (isCsv ? ' style="display:none"' : '') + '>' +
          '<label class="muted">Cuándo<select data-field="email_trigger" name="pipeline_steps[' + idx + '][email_trigger]" style="' + inp + '">' +
            '<option value="admin"' + ((s.email_trigger || 'admin') === 'admin' ? ' selected' : '') + '>Al activarlo el admin</option>' +
            '<option value="auto"' + (s.email_trigger === 'auto' ? ' selected' : '') + '>Automático al llegar al paso</option>' +
          '</select></label>' +
          '<label class="muted"><span class="progress-step-tpl-label">' + (isResults ? 'Plantilla de resultados' : 'Plantilla') + '</span>' + mailTplSelect('email_template', idx, s.email_template || '') + '</label>' +
        '</div>' +
        '<div class="progress-step-grid progress-step-csv"' + (isCsv ? '' : ' style="display:none"') + '>' +
          '<label class="muted">Plantilla CSV' + csvTplSelect(idx, s.csv_template || '') + '</label>' +
          '<label class="muted">Alcance' + csvScopeSelect(idx, s.csv_scope || 'student') + '</label>' +
        '</div>';
      stepsBody.appendChild(card);
      var labelInput = card.querySelector('[data-field="label"]');
      var codeHidden = card.querySelector('[data-field="code"]');
      if (labelInput && codeHidden && !codeHidden.value) {
        labelInput.addEventListener('input', function () {
          if (!codeHidden.dataset.locked) {
            codeHidden.value = slugStepCode(labelInput.value);
          }
          refreshStepEmailsSummary();
        });
      } else if (codeHidden && codeHidden.value) {
        codeHidden.dataset.locked = '1';
      }
      card.querySelectorAll('[data-field="email_enabled"], [data-field="email_template"], [data-field="email_trigger"], [data-field="label"], [data-field="ops_label"], [data-field="ops_icon"], [data-field="csv_template"], [data-field="csv_scope"]').forEach(function (el) {
        el.addEventListener('change', refreshStepEmailsSummary);
        el.addEventListener('input', refreshStepEmailsSummary);
      });
      var actionSel = card.querySelector('[data-field="action"]');
      if (actionSel) {
        actionSel.addEventListener('change', function () {
          syncStepActionUi(card);
          refreshStepEmailsSummary();
        });
      }
      syncStepActionUi(card);
    });
    refreshStepEmailsSummary();
    bindStepDrag();
  }

  function refreshStepEmailsSummary() {
    var box = document.getElementById('step-emails-summary');
    if (!box) return;
    var steps = currentStepsFromDom();
    var rows = [];
    var autoCount = 0;
    var adminCount = 0;
    steps.forEach(function (s, i) {
      var isResults = String(s.action || '') === 'send_results';
      if ((!s.email_enabled && !isResults) || !String(s.email_template || '').trim()) return;
      var tplLabel = String(s.email_template).trim();
      var sel = document.querySelector('[name="pipeline_steps[' + i + '][email_template]"]');
      if (sel && sel.selectedOptions && sel.selectedOptions[0]) {
        tplLabel = sel.selectedOptions[0].textContent.trim();
      }
      var isAuto = String(s.email_trigger || 'admin') === 'auto';
      if (isAuto) autoCount++;
      else adminCount++;
      rows.push({
        step: s.label || s.code || ('Paso ' + (i + 1)),
        template: tplLabel,
        when: isAuto ? 'Automático al llegar al paso' : 'Admin en Operación',
        auto: isAuto
      });
    });

    if (!rows.length) {
      box.innerHTML = '<p style="margin:0">Ningún paso tiene «Enviar correo» + plantilla todavía. '
        + 'Configúralos en las tarjetas de abajo.</p>';
      return;
    }

    var html = ''
      + '<div style="display:flex;flex-wrap:wrap;gap:.55rem .85rem;margin:0 0 .65rem">'
      + '<span style="display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .55rem;border-radius:999px;background:#e8f0fe;color:#1e3a5f;font-weight:600">'
      + 'Total: ' + rows.length + '</span>'
      + '<span style="display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .55rem;border-radius:999px;background:#e6f6ed;color:#176b3a;font-weight:600">'
      + 'Automáticos: ' + autoCount + '</span>'
      + '<span style="display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .55rem;border-radius:999px;background:#fff4e5;color:#9a5b00;font-weight:600">'
      + 'Admin (Operación): ' + adminCount + '</span>'
      + '</div>'
      + '<ul style="margin:0;padding-left:1.1rem;line-height:1.45">';
    rows.forEach(function (r) {
      html += '<li><strong>' + escapeHtml(r.step) + '</strong> → '
        + escapeHtml(r.template)
        + ' <span style="opacity:.85">(' + escapeHtml(r.when) + ')</span></li>';
    });
    html += '</ul>';
    box.innerHTML = html;
  }

  var dragSrc = null;
  function bindStepDrag() {
    if (!stepsBody) return;
    stepsBody.querySelectorAll('.progress-step-card').forEach(function (card) {
      card.setAttribute('draggable', 'false');
      var handle = card.querySelector('.progress-step-drag');
      if (handle) {
        handle.addEventListener('mousedown', function () { card.setAttribute('draggable', 'true'); });
        handle.addEventListener('mouseup', function () { card.setAttribute('draggable', 'false'); });
      }
      card.addEventListener('dragstart', function (e) {
        if (card.getAttribute('draggable') !== 'true') {
          e.preventDefault();
          return;
        }
        dragSrc = card;
        card.classList.add('is-dragging');
        try { e.dataTransfer.setData('text/plain', 'step'); e.dataTransfer.effectAllowed = 'move'; } catch (err) {}
      });
      card.addEventListener('dragend', function () {
        card.classList.remove('is-dragging');
        card.setAttribute('draggable', 'false');
        stepsBody.querySelectorAll('.progress-step-card').forEach(function (c) { c.classList.remove('drag-over'); });
        dragSrc = null;
      });
      card.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragSrc || dragSrc === card) return;
        card.classList.add('drag-over');
        try { e.dataTransfer.dropEffect = 'move'; } catch (err) {}
      });
      card.addEventListener('dragleave', function () {
        card.classList.remove('drag-over');
      });
      card.addEventListener('drop', function (e) {
        e.preventDefault();
        card.classList.remove('drag-over');
        if (!dragSrc || dragSrc === card) return;
        var cards = Array.prototype.slice.call(stepsBody.querySelectorAll('.progress-step-card'));
        var from = cards.indexOf(dragSrc);
        var to = cards.indexOf(card);
        if (from < 0 || to < 0) return;
        if (from < to) {
          card.parentNode.insertBefore(dragSrc, card.nextSibling);
        } else {
          card.parentNode.insertBefore(dragSrc, card);
        }
        renderSteps(currentStepsFromDom());
      });
    });
  }

  function loadPipeline(code) {
    if (!code) {
      renderSteps([]);
      if (stepsEmpty) {
        stepsEmpty.style.display = 'block';
        stepsEmpty.textContent = 'Elige una plantilla para editar sus pasos.';
      }
      return;
    }
    var steps = (stepsByCode[code] || []).map(function (s) {
      return {
        code: s.code || '',
        label: s.label || '',
        actor: s.actor || 'admin',
        is_terminal: s.is_terminal
      };
    });
    renderSteps(steps);
  }

  if (pipelineSelect) {
    pipelineSelect.addEventListener('change', function () {
      loadPipeline(pipelineSelect.value);
    });
    loadPipeline(pipelineSelect.value);
  }
  if (addStepBtn) {
    addStepBtn.addEventListener('click', function () {
      var steps = currentStepsFromDom();
      steps.push({ code: '', label: '', actor: 'admin', is_terminal: false });
      renderSteps(steps);
    });
  }
  if (stepsBody) {
    stepsBody.addEventListener('click', function (e) {
      var btn = e.target.closest('.pipeline-remove-step');
      if (!btn) return;
      var card = btn.closest('.progress-step-card');
      if (card) card.remove();
      renderSteps(currentStepsFromDom());
    });
  }

})();


  // ---- (legacy) Paso de solicitud proveedor: elementos eliminados del DOM ----
  (function () {
    var stepSelect = document.getElementById('provider-request-step-code');
    if (!stepSelect) return;
    var stepsByCode = <?= json_encode($pipelineStepsByCode ?? [], JSON_UNESCAPED_UNICODE) ?> || {};
    function fillSteps() {
      var code = pipelineSelect ? String(pipelineSelect.value || '') : '';
      var steps = stepsByCode[code] || [];
      var current = currentEl ? String(currentEl.value || '') : '';
      stepSelect.innerHTML = '';
      var opt0 = document.createElement('option');
      opt0.value = '';
      opt0.textContent = steps.length ? '— Elige un paso —' : '— Elige primero una plantilla de progreso —';
      stepSelect.appendChild(opt0);
      var seen = false;
      steps.forEach(function (s) {
        var opt = document.createElement('option');
        opt.value = s.code || '';
        opt.textContent = (s.label || s.code || '') + (s.code ? ' (' + s.code + ')' : '');
        if (opt.value && opt.value === current) {
          opt.selected = true;
          seen = true;
        }
        stepSelect.appendChild(opt);
      });
      if (current && !seen) {
        var orphan = document.createElement('option');
        orphan.value = current;
        orphan.textContent = current + ' (no está en la plantilla actual)';
        orphan.selected = true;
        stepSelect.appendChild(orphan);
      }
    }
    fillSteps();
    if (pipelineSelect) pipelineSelect.addEventListener('change', fillSteps);
    stepSelect.addEventListener('change', function () {
      if (currentEl) currentEl.value = stepSelect.value || '';
    });
  })();

  // ---- Solicitud a proveedor ----
  (function () {
    var enabled = document.getElementById('provider-request-enabled');
    var fields = document.getElementById('provider-request-fields');
    var addBtn = document.getElementById('provider-cell-add');
    var map = document.getElementById('provider-cell-map');
    function refresh() {
      if (!enabled || !fields) return;
      fields.style.opacity = enabled.checked ? '1' : '.55';
      fields.style.pointerEvents = enabled.checked ? 'auto' : 'none';
    }
    if (enabled) enabled.addEventListener('change', refresh);
    refresh();
    if (addBtn && map) {
      addBtn.addEventListener('click', function () {
        var row = map.querySelector('.provider-cell-row');
        if (!row) return;
        var clone = row.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (el) { el.value = ''; });
        clone.querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
        map.appendChild(clone);
      });
      map.addEventListener('click', function (e) {
        var btn = e.target.closest('.provider-cell-remove');
        if (!btn) return;
        var rows = map.querySelectorAll('.provider-cell-row');
        if (rows.length <= 1) {
          rows[0].querySelectorAll('input').forEach(function (el) { el.value = ''; });
          rows[0].querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
          return;
        }
        btn.closest('.provider-cell-row').remove();
      });
    }
  })();

  // ---- Correos por paso ----
  (function () {
    var addBtn = document.getElementById('email-step-add');
    var rows = document.getElementById('email-step-rows');
    if (!addBtn || !rows) return;
    addBtn.addEventListener('click', function () {
      var row = rows.querySelector('.email-step-row');
      if (!row) return;
      var clone = row.cloneNode(true);
      clone.querySelectorAll('input').forEach(function (el) { el.value = ''; });
      clone.querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
      rows.appendChild(clone);
    });
    rows.addEventListener('click', function (e) {
      var btn = e.target.closest('.email-step-remove');
      if (!btn) return;
      var list = rows.querySelectorAll('.email-step-row');
      if (list.length <= 1) {
        list[0].querySelectorAll('input').forEach(function (el) { el.value = ''; });
        list[0].querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
        return;
      }
      btn.closest('.email-step-row').remove();
    });
  })();

</script>

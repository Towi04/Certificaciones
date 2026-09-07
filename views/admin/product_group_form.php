<?php
/** @var array<string,mixed>|null $group */
/** @var list<array<string,mixed>> $suppliers */
/** @var string $defaultConfig */
/** @var array<string,mixed> $extras */
/** @var array<string,string> $usedDocCodes */
$isEdit = $group !== null;
$action = $isEdit ? url('/admin/grupos/' . $group['id']) : url('/admin/grupos/nuevo');
$extras = $extras ?? \App\Services\ProductAdminService::groupFormExtrasFromConfig($defaultConfig ?? null);
$usedDocCodes = $usedDocCodes ?? [];
$preselectSupplier = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
if (!$isEdit && $preselectSupplier > 0 && empty($group['supplier_id'])) {
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
$emailsCfg = is_array($extras['emails'] ?? null)
    ? $extras['emails']
    : \App\Services\GroupEmailAutomation::normalize(null);
$emailReg = is_array($emailsCfg['student_registration'] ?? null) ? $emailsCfg['student_registration'] : ['enabled' => true, 'template_code' => 'student_registration'];
$emailPay = is_array($emailsCfg['student_payment_confirmed'] ?? null) ? $emailsCfg['student_payment_confirmed'] : ['enabled' => true, 'template_code' => 'student_payment_confirmed'];
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
<p class="muted">
    Configura lo compartido por varias certificaciones del mismo proveedor:
    datos del alumno, días/horarios, reglamento, pagos y la tarjeta de
    <strong>Progreso</strong> del caso.
    Las <a href="<?= e(url('/admin/vacaciones')) ?>"><strong>vacaciones globales</strong></a>
    se publican una sola vez (excepto grupos marcados como 365 días).
</p>

<nav class="group-tabs" role="tablist" aria-label="Secciones del grupo">
    <button type="button" class="group-tab active" data-tab="general" role="tab" aria-selected="true">General</button>
    <button type="button" class="group-tab" data-tab="fields" role="tab" aria-selected="false">Datos del alumno</button>
    <button type="button" class="group-tab" data-tab="schedule" role="tab" aria-selected="false">Fechas y horarios</button>
    <button type="button" class="group-tab" data-tab="rules" role="tab" aria-selected="false">Reglamento</button>
    <button type="button" class="group-tab" data-tab="payments" role="tab" aria-selected="false">Pagos</button>
    <button type="button" class="group-tab" data-tab="progress" role="tab" aria-selected="false">Progreso</button>
    <button type="button" class="group-tab" data-tab="provider" role="tab" aria-selected="false">Solicitud proveedor</button>
    <button type="button" class="group-tab" data-tab="emails" role="tab" aria-selected="false">Correos</button>
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
            <label class="muted" style="<?= e($labelStyle) ?>">
                Código *
                <input type="text" name="code" id="group-code" required maxlength="40"
                       <?= $isEdit ? 'readonly' : '' ?>
                       value="<?= e($groupCode) ?>"
                       placeholder="Ej. itep-exams"
                       style="<?= e($inputStyle) ?><?= $isEdit ? ';background:#f4f7fb' : '' ?>">
                <?php if ($isEdit): ?>
                    <span style="font-weight:500;font-size:.78rem">El código no se cambia después de crear el grupo.</span>
                <?php endif; ?>
            </label>
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
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Marca qué información debe capturar el alumno y, para cada campo, si es
            <strong>obligatorio</strong> u <strong>opcional</strong> cuando se pide.
            Los campos personalizados se pueden editar (nombre, tipo y default) y quedan disponibles en todos los grupos.
        </p>
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
                        </span>
                    </label>
                    <?php if ($locked): ?>
                        <span class="muted" style="display:block;font-size:.75rem;font-weight:500;margin:.25rem 0 0 1.55rem">
                            Obligatorio en toda compra
                        </span>
                    <?php else: ?>
                        <label class="field-required-toggle muted">
                            Si se pide:
                            <select name="checkout_field_required[<?= e($code) ?>]" class="field-required-select">
                                <option value="1" <?= $isRequired ? 'selected' : '' ?>>Obligatorio</option>
                                <option value="0" <?= !$isRequired ? 'selected' : '' ?>>Opcional</option>
                            </select>
                        </label>
                        <?php if ($isCustom): ?>
                            <button type="button" class="btn-link field-edit-btn" data-code="<?= e($code) ?>"
                                    data-label="<?= e((string) $meta['label']) ?>"
                                    data-type="<?= e((string) ($meta['type'] ?? 'text')) ?>"
                                    data-required="<?= $defaultRequired ? '1' : '0' ?>">
                                Editar campo
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="add-checkout-field" style="margin-top:1.1rem;padding:1rem;border:1px dashed #9db7e8;border-radius:14px;background:#f7faff">
            <h3 style="margin:0 0 .35rem;font-size:.95rem;color:var(--doceo-blue)">Agregar campo nuevo</h3>
            <p class="muted" style="margin:0 0 .75rem;font-size:.8rem">
                El campo se guarda en el catálogo global y aparecerá en la lista de selección de <strong>todos</strong> los grupos.
                Al guardar este grupo quedará marcado aquí automáticamente.
            </p>
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
            <p class="muted" id="edit-checkout-field-msg" style="margin:.55rem 0 0;font-size:.78rem;display:none"></p>
        </div>
    </div>

    <div class="group-panel" data-panel="schedule" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Fechas y horarios de aplicación</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Casi todas las certificaciones piden fecha y hora en el checkout (soporte y caducidad).
            Marca los días en que se puede presentar el examen.
        </p>

        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.65rem">
            <input type="checkbox" name="exam_choose_at_checkout" value="1"
                <?= !empty($extras['exam_choose_at_checkout']) ? 'checked' : '' ?>>
            Pedir fecha y hora de aplicación en el checkout
        </label>

        <label class="muted" style="display:flex;align-items:flex-start;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:1rem">
            <input type="checkbox" name="schedule_available_365" value="1" style="margin-top:.2rem"
                <?= !empty($extras['schedule_available_365']) ? 'checked' : '' ?>>
            <span>
                Disponible los 365 días del año
                <span class="muted" style="display:block;font-weight:500;font-size:.78rem;margin-top:.15rem">
                    Si se marca, <strong>no</strong> aplican las vacaciones globales DOCEO.
                    Igual se pide fecha/hora si la opción de arriba está activa.
                </span>
            </span>
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
                Anticipo mínimo (días)
                <input type="number" name="schedule_min_advance_days" min="0" step="1"
                       value="<?= (int) ($extras['schedule_min_advance_days'] ?? 2) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Caducidad para presentar (meses)
                <input type="number" name="exam_validity_months" min="1" max="36" step="1"
                       value="<?= (int) ($extras['exam_validity_months'] ?? 6) ?>"
                       style="<?= e($inputStyle) ?>">
                <span style="font-weight:500;font-size:.75rem">Normalmente 6 meses; después pueden comprar prórroga.</span>
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
                <span style="font-weight:500;font-size:.75rem">Usa 24:00 para cubrir hasta medianoche.</span>
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
                <span style="font-weight:500;font-size:.75rem">También acepta 24:00.</span>
            </label>
        </div>
        <p class="muted" style="font-size:.78rem;margin:.75rem 0 0">
            Vacaciones DOCEO: <a href="<?= e(url('/admin/vacaciones')) ?>">Administrar fechas globales</a>
        </p>
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
                <span id="doc-code-hint" style="font-weight:500;font-size:.78rem">
                    Se propone automáticamente según el código del grupo. Puedes editarlo.
                </span>
            </label>
        </div>
    </div>

    <div class="group-panel" data-panel="payments" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Pagos y MSI</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Elige cómo pueden pagar los alumnos. No necesitas editar JSON.
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
    $selectedInitialStep = (string) ($extras['initial_step_code'] ?? '');
    ?>
    <div class="group-panel" data-panel="progress" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Progreso del caso</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Esta es la tarjeta de pasos que ve el alumno después de comprar
            (Registro, Confirmación de pago, etc.). Elige una plantilla y edita
            las etiquetas o el orden. Los productos de este grupo heredan este progreso.
        </p>

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

            <label class="muted" style="<?= e($labelStyle) ?>;max-width:34rem;margin-bottom:1rem">
                Paso inicial al crear el caso
                <select name="initial_step_code" id="pipeline-initial-step" style="<?= e($inputStyle) ?>">
                    <option value="">— Primero de la plantilla —</option>
                </select>
            </label>

            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:.55rem">
                <strong style="color:var(--doceo-blue)">Pasos (tarjeta del alumno)</strong>
                <button type="button" class="btn btn-ghost btn-sm" id="pipeline-add-step">+ Agregar paso</button>
            </div>
            <p class="muted" style="font-size:.78rem;margin:0 0 .65rem">
                Nota: al guardar, estos pasos actualizan la plantilla seleccionada.
                Si otro grupo usa la misma plantilla, verá los mismos cambios.
                No borres ni renombres códigos de pasos ya usados en casos existentes
                (puedes cambiar solo la etiqueta visible).
            </p>
            <div class="table-wrap">
                <table class="data" id="pipeline-steps-table">
                    <thead>
                    <tr>
                        <th style="width:3rem">#</th>
                        <th>Código</th>
                        <th>Etiqueta (visible al alumno)</th>
                        <th>Actor</th>
                        <th>Final</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="pipeline-steps-body"></tbody>
                </table>
            </div>
            <p id="pipeline-steps-empty" class="muted" style="display:none;margin:.5rem 0 0">
                Elige una plantilla para editar sus pasos.
            </p>
        <?php endif; ?>
    </div>

    <div class="group-panel" data-panel="provider" hidden>
<?php
    $pr = is_array($extras['provider_request'] ?? null) ? $extras['provider_request'] : [];
    $prEnabled = !empty($pr['enabled']);
    $prCells = is_array($pr['workbook_cell_map'] ?? null) ? $pr['workbook_cell_map'] : [];
    if ($prCells === []) {
        $prCells = [['cell' => '', 'field' => '']];
    }
    $fieldOptions = \App\Services\ProviderRequestService::FIELD_OPTIONS;
    $selectedMailTpl = (string) ($pr['mail_template_code'] ?? '');
    $selectedStep = (string) ($pr['step_code'] ?? 'solicitud_proveedor');
    $wbNormalize = (string) ($pr['workbook_normalize'] ?? 'none');
    $wbSheet = (string) ($pr['workbook_sheet'] ?? '');
?>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Solicitud al proveedor</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Configura el correo de <strong>solicitud al proveedor</strong>. El contenido del mensaje lo define la
            <strong>plantilla de correo</strong> (variables). El envío se dispara cuando un admin sube el
            <strong>comprobante de pago DOCEO → proveedor</strong> en el seguimiento del caso.
        </p>

        <label class="muted" style="display:flex;gap:.45rem;align-items:center;font-size:.9rem;margin-bottom:.85rem">
            <input type="checkbox" name="provider_request_enabled" value="1" id="provider-request-enabled"
                <?= $prEnabled ? 'checked' : '' ?>>
            Este grupo requiere solicitud al proveedor tras el pago
        </label>

        <div id="provider-request-fields" style="<?= $prEnabled ? '' : 'opacity:.55;pointer-events:none' ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;margin-bottom:.85rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Correo destino (Para)
                    <input type="email" name="provider_request_to" value="<?= e((string) ($pr['to'] ?? '')) ?>"
                           placeholder="proveedor@ejemplo.com" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    CC (opcional)
                    <input type="text" name="provider_request_cc" value="<?= e((string) ($pr['cc'] ?? '')) ?>"
                           placeholder="ops@doceo.mx" style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Plantilla de correo
                    <select name="provider_request_mail_template" style="<?= e($inputStyle) ?>">
                        <option value="">— Genérico (sin plantilla) —</option>
                        <?php foreach ($mailTemplates as $tpl): ?>
                            <?php
                                $code = (string) ($tpl['code'] ?? '');
                                $name = (string) ($tpl['name'] ?? $code);
                                if ($code === '') {
                                    continue;
                                }
                            ?>
                            <option value="<?= e($code) ?>" <?= $selectedMailTpl === $code ? 'selected' : '' ?>>
                                <?= e($name) ?> (<?= e($code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Paso en el progreso
                    <select name="provider_request_step_code" id="provider-request-step-code" style="<?= e($inputStyle) ?>">
                        <option value="">— Elige un paso —</option>
                    </select>
                    <input type="hidden" id="provider-request-step-current" value="<?= e($selectedStep) ?>">
                </label>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;margin-bottom:.85rem">
                <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                    <input type="checkbox" name="provider_request_require_admin_proof" value="1"
                        <?= !isset($pr['require_admin_payment_proof']) || !empty($pr['require_admin_payment_proof']) ? 'checked' : '' ?>>
                    Exigir comprobante de pago (admin) antes de enviar
                </label>
                <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                    <input type="checkbox" name="provider_request_auto_send_admin_proof" value="1"
                        <?= !isset($pr['auto_send_on_admin_proof']) || !empty($pr['auto_send_on_admin_proof']) ? 'checked' : '' ?>>
                    Enviar automáticamente al subir ese comprobante
                </label>
                <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                    <input type="checkbox" name="provider_request_auto_send" value="1"
                        <?= !empty($pr['auto_send_on_payment']) ? 'checked' : '' ?>>
                    (Avanzado) Enviar también al confirmar el pago del alumno
                </label>
            </div>

            <p style="margin:.75rem 0 .35rem;font-weight:700;color:var(--doceo-blue)">Adjuntos / requisitos</p>
            <p class="muted" style="font-size:.78rem;margin:0 0 .5rem">
                Los datos del alumno y del examen los define la plantilla de correo (y el mapeo Excel).
                Aquí solo marcas documentos a adjuntar o exigir.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;margin-bottom:.85rem">
                <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                    <input type="checkbox" name="provider_request_include_reglamento" value="1" <?= !isset($pr['include_reglamento']) || !empty($pr['include_reglamento']) ? 'checked' : '' ?>>
                    Incluir reglamento firmado
                </label>
                <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                    <input type="checkbox" name="provider_request_require_reglamento" value="1" <?= !isset($pr['require_reglamento']) || !empty($pr['require_reglamento']) ? 'checked' : '' ?>>
                    Exigir reglamento para poder enviar
                </label>
                <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem">
                    <input type="checkbox" name="provider_request_include_proof" value="1" <?= !isset($pr['include_payment_proof']) || !empty($pr['include_payment_proof']) ? 'checked' : '' ?>>
                    Incluir comprobante de pago al proveedor
                </label>
            </div>

            <label class="muted" style="<?= e($labelStyle) ?>;max-width:28rem;margin-bottom:1rem">
                Cómo enviar documentos
                <select name="provider_request_delivery" style="<?= e($inputStyle) ?>">
                    <?php $del = (string) ($pr['delivery'] ?? 'links'); ?>
                    <option value="links" <?= $del === 'links' ? 'selected' : '' ?>>Solo enlaces seguros</option>
                    <option value="attachments" <?= $del === 'attachments' ? 'selected' : '' ?>>Solo adjuntos</option>
                    <option value="both" <?= $del === 'both' ? 'selected' : '' ?>>Enlaces y adjuntos</option>
                </select>
            </label>

            <div style="border:1px solid #dbeafe;border-radius:12px;padding:.85rem;background:#f8fbff;margin-bottom:.5rem">
                <label class="muted" style="display:flex;gap:.45rem;align-items:center;font-size:.9rem;margin-bottom:.65rem">
                    <input type="checkbox" name="provider_request_workbook_enabled" value="1" id="provider-workbook-enabled"
                        <?= !empty($pr['workbook_enabled']) ? 'checked' : '' ?>>
                    Adjuntar plantilla Excel rellenada (p. ej. TOEFL)
                </label>
                <div id="provider-workbook-fields">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;margin-bottom:.65rem">
                        <label class="muted" style="<?= e($labelStyle) ?>">
                            Plantilla .xlsx
                            <input type="file" name="provider_request_workbook" accept=".xlsx,.xls" style="<?= e($inputStyle) ?>">
                        </label>
                        <label class="muted" style="<?= e($labelStyle) ?>">
                            Hoja del archivo
                            <input type="text" name="provider_request_workbook_sheet" value="<?= e($wbSheet) ?>"
                                   placeholder="Nombre o número (1, 2…)" style="<?= e($inputStyle) ?>">
                        </label>
                        <label class="muted" style="<?= e($labelStyle) ?>">
                            Transcripción de datos
                            <select name="provider_request_workbook_normalize" style="<?= e($inputStyle) ?>">
                                <option value="none" <?= $wbNormalize === 'none' ? 'selected' : '' ?>>Sin cambios</option>
                                <option value="toefl" <?= $wbNormalize === 'toefl' ? 'selected' : '' ?>>TOEFL: MAYÚSCULAS, sin acentos ni Ñ</option>
                            </select>
                        </label>
                    </div>
                    <?php if (!empty($pr['workbook_template_path'])): ?>
                        <p class="muted" style="font-size:.8rem;margin:.2rem 0 .65rem">
                            Actual: <code><?= e((string) $pr['workbook_template_path']) ?></code>
                            · <label style="display:inline-flex;gap:.3rem;align-items:center"><input type="checkbox" name="provider_request_clear_workbook" value="1"> Quitar</label>
                        </p>
                    <?php endif; ?>
                    <label class="muted" style="display:flex;gap:.4rem;align-items:center;font-size:.88rem;margin-bottom:.65rem">
                        <input type="checkbox" name="provider_request_workbook_attach" value="1"
                            <?= !isset($pr['workbook_attach']) || !empty($pr['workbook_attach']) ? 'checked' : '' ?>>
                        Adjuntar el Excel al correo
                    </label>
                    <p style="margin:.35rem 0;font-weight:700;color:var(--doceo-blue)">Mapeo de celdas</p>
                    <p class="muted" style="font-size:.78rem;margin:0 0 .5rem">Indica en qué celda (ej. B2) se escribe cada dato.</p>
                    <div id="provider-cell-map">
                        <?php foreach ($prCells as $map): ?>
                            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.4rem;align-items:center" class="provider-cell-row">
                                <input type="text" name="provider_request_cells[]" value="<?= e((string) ($map['cell'] ?? '')) ?>"
                                       placeholder="B2" style="width:5rem;<?= e($inputStyle) ?>">
                                <select name="provider_request_fields[]" style="<?= e($inputStyle) ?>">
                                    <option value="">— Dato —</option>
                                    <?php foreach ($fieldOptions as $opt): ?>
                                        <option value="<?= e($opt['value']) ?>" <?= (($map['field'] ?? '') === $opt['value']) ? 'selected' : '' ?>>
                                            <?= e($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-ghost btn-sm provider-cell-remove">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" id="provider-cell-add">+ Celda</button>
                </div>
            </div>
        </div>
    </div>

    <div class="group-panel" data-panel="emails" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Correos automáticos</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .85rem">
            Define qué correos se envían al alumno (y opcionalmente al proveedor en un paso del progreso)
            y con qué plantilla. El correo de solicitud al proveedor tras el pago se configura en
            <strong>Solicitud proveedor</strong>, no aquí.
        </p>

        <h3 style="margin:0 0 .55rem;font-size:.95rem;color:var(--doceo-blue)">Correos al alumno</h3>
        <p class="muted" style="font-size:.8rem;margin:0 0 .75rem">
            Activa o desactiva cada momento del ciclo y elige la plantilla.
            En acceso al examen, <em>Admin</em> significa que un administrador dispara el envío;
            <em>Automático</em> lo envía el sistema cuando corresponda.
        </p>

        <div style="display:flex;flex-direction:column;gap:.85rem;margin-bottom:1.25rem">
            <div style="border:1px solid #dbeafe;border-radius:12px;padding:.85rem;background:#f8fbff">
                <label class="muted" style="display:flex;gap:.45rem;align-items:center;font-size:.9rem;margin-bottom:.55rem">
                    <input type="checkbox" name="email_registration_enabled" value="1"
                        <?= !empty($emailReg['enabled']) ? 'checked' : '' ?>>
                    Al registrarse / crear la cuenta
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>;max-width:28rem">
                    Plantilla
                    <?php $renderMailTemplateField(
                        'email_registration_template',
                        (string) ($emailReg['template_code'] ?? 'student_registration'),
                        $mailTemplates,
                        $inputStyle,
                        'student_registration'
                    ); ?>
                </label>
            </div>

            <div style="border:1px solid #dbeafe;border-radius:12px;padding:.85rem;background:#f8fbff">
                <label class="muted" style="display:flex;gap:.45rem;align-items:center;font-size:.9rem;margin-bottom:.55rem">
                    <input type="checkbox" name="email_payment_enabled" value="1"
                        <?= !empty($emailPay['enabled']) ? 'checked' : '' ?>>
                    Cuando se confirma el pago
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>;max-width:28rem">
                    Plantilla
                    <?php $renderMailTemplateField(
                        'email_payment_template',
                        (string) ($emailPay['template_code'] ?? 'student_payment_confirmed'),
                        $mailTemplates,
                        $inputStyle,
                        'student_payment_confirmed'
                    ); ?>
                </label>
            </div>

            <div style="border:1px solid #dbeafe;border-radius:12px;padding:.85rem;background:#f8fbff">
                <label class="muted" style="display:flex;gap:.45rem;align-items:center;font-size:.9rem;margin-bottom:.55rem">
                    <input type="checkbox" name="email_exam_access_enabled" value="1"
                        <?= !empty($emailExam['enabled']) ? 'checked' : '' ?>>
                    Acceso al examen (enlace / clave)
                </label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Plantilla
                        <?php $renderMailTemplateField(
                            'email_exam_access_template',
                            (string) ($emailExam['template_code'] ?? 'student_elet_exam_access'),
                            $mailTemplates,
                            $inputStyle,
                            'student_elet_exam_access'
                        ); ?>
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Quién lo envía
                        <?php $examMode = (string) ($emailExam['mode'] ?? 'admin'); ?>
                        <select name="email_exam_access_mode" style="<?= e($inputStyle) ?>">
                            <option value="admin" <?= $examMode !== 'auto' ? 'selected' : '' ?>>Admin (manual)</option>
                            <option value="auto" <?= $examMode === 'auto' ? 'selected' : '' ?>>Automático</option>
                        </select>
                    </label>
                </div>
            </div>
        </div>

        <h3 style="margin:0 0 .55rem;font-size:.95rem;color:var(--doceo-blue)">Correos al entrar a un paso del progreso</h3>
        <p class="muted" style="font-size:.8rem;margin:0 0 .65rem">
            Cuando el caso pasa a un paso (código de la pestaña Progreso), se puede enviar un correo.
            <em>Automático</em> se dispara solo; <em>Admin</em> queda para envío manual desde el caso.
            Destinatario: alumno o proveedor.
        </p>
        <div id="email-step-rows">
            <?php foreach ($emailSteps as $stepRow): ?>
                <div class="email-step-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.5rem;margin-bottom:.55rem;align-items:end;padding:.65rem;border:1px solid #e2e8f0;border-radius:10px;background:#fff">
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Código del paso
                        <input type="text" name="email_step_codes[]"
                               value="<?= e((string) ($stepRow['step_code'] ?? '')) ?>"
                               placeholder="ej. resultados" style="<?= e($inputStyle) ?>">
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Plantilla
                        <?php $renderMailTemplateField(
                            'email_step_templates[]',
                            (string) ($stepRow['template_code'] ?? ''),
                            $mailTemplates,
                            $inputStyle,
                            'código_plantilla'
                        ); ?>
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Modo
                        <?php $sm = (string) ($stepRow['mode'] ?? 'admin'); ?>
                        <select name="email_step_modes[]" style="<?= e($inputStyle) ?>">
                            <option value="admin" <?= $sm !== 'auto' ? 'selected' : '' ?>>Admin</option>
                            <option value="auto" <?= $sm === 'auto' ? 'selected' : '' ?>>Automático</option>
                        </select>
                    </label>
                    <label class="muted" style="<?= e($labelStyle) ?>">
                        Destinatario
                        <?php $aud = (string) ($stepRow['audience'] ?? 'student'); ?>
                        <select name="email_step_audiences[]" style="<?= e($inputStyle) ?>">
                            <option value="student" <?= $aud !== 'provider' ? 'selected' : '' ?>>Alumno</option>
                            <option value="provider" <?= $aud === 'provider' ? 'selected' : '' ?>>Proveedor</option>
                        </select>
                    </label>
                    <button type="button" class="btn btn-ghost btn-sm email-step-remove" style="margin-bottom:.15rem">Quitar</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" id="email-step-add">+ Agregar correo por paso</button>
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
  display:flex; gap:.4rem; align-items:center; margin:.35rem 0 0 1.55rem;
  font-size:.75rem; font-weight:600;
}
.field-required-select {
  font:inherit; font-size:.75rem; padding:.2rem .4rem; border:1px solid #cfd8e6; border-radius:8px;
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
  if (hash && document.querySelector('.group-panel[data-panel="' + hash + '"]')) activate(hash);

  var usedDocCodes = <?= json_encode($usedDocCodes, JSON_UNESCAPED_UNICODE) ?> || {};
  var currentGroupCode = <?= json_encode($groupCode, JSON_UNESCAPED_UNICODE) ?>;
  var docInput = document.getElementById('reglamento_doc_code');
  var codeInput = document.getElementById('group-code');
  var hint = document.getElementById('doc-code-hint');
  var docTouched = <?= json_encode(trim((string) ($extras['reglamento_doc_code'] ?? '')) !== '') ?>;

  function slugify(v) {
    return String(v || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'nuevo';
  }
  function autoDoc() {
    var code = codeInput ? codeInput.value : currentGroupCode;
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
        hint.textContent = 'Se propone automáticamente según el código del grupo. Puedes editarlo.';
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
      validity_months: Math.max(1, Math.min(36, intVal('exam_validity_months', 6)))
    });
    base.schedule = Object.assign({}, base.schedule || {}, {
      min_advance_days: Math.max(0, intVal('schedule_min_advance_days', 2)),
      available_365: checked('schedule_available_365'),
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
    var initialStep = val('initial_step_code', '');
    if (initialStep) base.initial_step_code = initialStep;
    else delete base.initial_step_code;

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
        delivery: val('provider_request_delivery', 'links'),
        workbook: {
          enabled: !!(document.querySelector('[name="provider_request_workbook_enabled"]') || {}).checked,
          template_path: (base.provider_request && base.provider_request.workbook && base.provider_request.workbook.template_path) || '',
          attach: !!(document.querySelector('[name="provider_request_workbook_attach"]') || {}).checked,
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
    base.emails = {
      student_registration: {
        enabled: !!(document.querySelector('[name="email_registration_enabled"]') || {}).checked,
        template_code: emailTpl('email_registration_template', 'student_registration')
      },
      student_payment_confirmed: {
        enabled: !!(document.querySelector('[name="email_payment_enabled"]') || {}).checked,
        template_code: emailTpl('email_payment_template', 'student_payment_confirmed')
      },
      student_exam_access: {
        enabled: !!(document.querySelector('[name="email_exam_access_enabled"]') || {}).checked,
        template_code: emailTpl('email_exam_access_template', 'student_elet_exam_access'),
        mode: (function () {
          var m = document.querySelector('[name="email_exam_access_mode"]');
          return m && m.value === 'auto' ? 'auto' : 'admin';
        })()
      },
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
      + '<label class="field-required-toggle muted">Si se pide: '
      + '<select name="checkout_field_required[' + field.code + ']" class="field-required-select">'
      + '<option value="1">Obligatorio</option><option value="0">Opcional</option></select></label>'
      + '<button type="button" class="btn-link field-edit-btn">Editar campo</button>';
    card.querySelector('.field-label-text').textContent = field.label || field.code;
    card.querySelector('.field-required-select').value = field.required ? '1' : '0';
    var editBtn = card.querySelector('.field-edit-btn');
    editBtn.setAttribute('data-code', field.code);
    editBtn.setAttribute('data-label', field.label || field.code);
    editBtn.setAttribute('data-type', field.type || 'text');
    editBtn.setAttribute('data-required', field.required ? '1' : '0');
    fieldGrid.appendChild(card);
  }
  if (addFieldBtn) {
    addFieldBtn.addEventListener('click', function () {
      var labelEl = document.getElementById('new_checkout_field_label');
      var typeEl = document.getElementById('new_checkout_field_type');
      var reqEl = document.getElementById('new_checkout_field_required');
      var label = labelEl ? String(labelEl.value || '').trim() : '';
      if (!label) {
        showAddFieldMsg('Escribe el nombre del campo.', true);
        if (labelEl) labelEl.focus();
        return;
      }
      addFieldBtn.disabled = true;
      var body = new FormData();
      body.append('_csrf', csrfToken());
      body.append('label', label);
      body.append('type', typeEl ? typeEl.value : 'text');
      if (reqEl && String(reqEl.value) === '1') body.append('required', '1');
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
      if (!code || !label) {
        showEditFieldMsg('Indica el nombre del campo.', true);
        return;
      }
      saveEditBtn.disabled = true;
      var body = new FormData();
      body.append('_csrf', csrfToken());
      body.append('code', code);
      body.append('label', label);
      body.append('type', type);
      if (required) body.append('required', '1');
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


  // ---- Editor de progreso (tarjeta del alumno) ----
  var stepsByCode = <?= json_encode($pipelineStepsByCode ?? [], JSON_UNESCAPED_UNICODE) ?> || {};
  var initialSelectedStep = <?= json_encode($selectedInitialStep ?? '', JSON_UNESCAPED_UNICODE) ?>;
  var pipelineSelect = document.getElementById('pipeline-code-select');
  var initialSelect = document.getElementById('pipeline-initial-step');
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

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function currentStepsFromDom() {
    if (!stepsBody) return [];
    var rows = [];
    stepsBody.querySelectorAll('tr').forEach(function (tr) {
      var code = tr.querySelector('[data-field="code"]');
      var label = tr.querySelector('[data-field="label"]');
      var actor = tr.querySelector('[data-field="actor"]');
      var term = tr.querySelector('[data-field="terminal"]');
      rows.push({
        code: code ? code.value : '',
        label: label ? label.value : '',
        actor: actor ? actor.value : 'admin',
        is_terminal: !!(term && term.checked)
      });
    });
    return rows;
  }

  function renderInitialOptions(steps, selected) {
    if (!initialSelect) return;
    var html = '<option value="">— Primero de la plantilla —</option>';
    steps.forEach(function (s) {
      var sel = selected && selected === s.code ? ' selected' : '';
      html += '<option value="' + escapeHtml(s.code) + '"' + sel + '>' + escapeHtml(s.label || s.code) + '</option>';
    });
    initialSelect.innerHTML = html;
  }

  function renderSteps(steps) {
    if (!stepsBody) return;
    stepsBody.innerHTML = '';
    if (!steps || !steps.length) {
      if (stepsEmpty) stepsEmpty.style.display = 'block';
      renderInitialOptions([], '');
      return;
    }
    if (stepsEmpty) stepsEmpty.style.display = 'none';
    steps.forEach(function (s, idx) {
      var tr = document.createElement('tr');
      var actorOpts = actors.map(function (a) {
        return '<option value="' + a.value + '"' + ((s.actor || 'admin') === a.value ? ' selected' : '') + '>' + a.label + '</option>';
      }).join('');
      tr.innerHTML =
        '<td class="muted">' + (idx + 1) + '</td>' +
        '<td><input data-field="code" name="pipeline_steps[' + idx + '][code]" value="' + escapeHtml(s.code || '') + '" ' +
          'style="width:100%;min-width:7rem;padding:.35rem .45rem;border:1px solid #cfd8e6;border-radius:8px;font:inherit" required></td>' +
        '<td><input data-field="label" name="pipeline_steps[' + idx + '][label]" value="' + escapeHtml(s.label || '') + '" ' +
          'style="width:100%;min-width:12rem;padding:.35rem .45rem;border:1px solid #cfd8e6;border-radius:8px;font:inherit" required></td>' +
        '<td><select data-field="actor" name="pipeline_steps[' + idx + '][actor]" ' +
          'style="padding:.35rem .45rem;border:1px solid #cfd8e6;border-radius:8px;font:inherit">' + actorOpts + '</select></td>' +
        '<td style="text-align:center"><input data-field="terminal" type="checkbox" name="pipeline_steps[' + idx + '][is_terminal]" value="1"' +
          (s.is_terminal == 1 || s.is_terminal === true ? ' checked' : '') + '></td>' +
        '<td><button type="button" class="btn btn-ghost btn-sm pipeline-remove-step" title="Quitar">✕</button></td>';
      stepsBody.appendChild(tr);
    });
    renderInitialOptions(steps, initialSelectedStep || (initialSelect && initialSelect.value) || '');
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
      initialSelectedStep = '';
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
      var tr = btn.closest('tr');
      if (tr) tr.remove();
      var steps = currentStepsFromDom();
      renderSteps(steps);
    });
  }

})();


  // ---- Paso de solicitud proveedor según plantilla de progreso ----
  (function () {
    var pipelineSelect = document.getElementById('pipeline-code-select');
    var stepSelect = document.getElementById('provider-request-step-code');
    var currentEl = document.getElementById('provider-request-step-current');
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

<?php
/**
 * Formulario compartido para editar datos de registro (antes del examen).
 *
 * @var array<string,mixed> $tracking
 * @var string $formAction
 * @var bool $canEdit
 * @var bool|null $canEditRegistration
 * @var string|null $stepCode
 * @var array<string,string>|null $hidden
 */
$canEdit = (bool) ($canEdit ?? $canEditRegistration ?? true);
$formAction = (string) ($formAction ?? '');
$stepCode = $stepCode ?? null;
$hidden = is_array($hidden ?? null) ? $hidden : [];
$sex = \App\Services\CheckoutRequirements::normalizeSexValue((string) ($tracking['sex'] ?? ''));
$nationality = trim((string) ($tracking['nationality'] ?? ''));
$nationalityOptions = \App\Services\CheckoutRequirements::NATIONALITY_OPTIONS;
$sexOptions = \App\Services\CheckoutRequirements::SEX_OPTIONS;
$inputStyle = 'padding:.45rem .55rem;border:1px solid #cfd8e6;border-radius:8px;width:100%';
?>
<?php if ($formAction === ''): ?>
    <p class="muted" style="margin:0;font-size:.88rem">No se configuró la URL del formulario.</p>
<?php elseif (!$canEdit): ?>
    <p class="muted" style="margin:0;font-size:.88rem">
        Los datos de registro ya no se pueden modificar porque el alumno ya presentó el examen
        (o el caso está cerrado).
    </p>
<?php else: ?>
<form method="post" action="<?= e($formAction) ?>" class="registration-edit-form">
    <?= csrf_field() ?>
    <?php foreach ($hidden as $name => $value): ?>
        <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
    <?php endforeach; ?>
    <?php if ($stepCode !== null && $stepCode !== ''): ?>
        <input type="hidden" name="step_code" value="<?= e($stepCode) ?>">
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.55rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            Nombre(s) *
            <input style="<?= e($inputStyle) ?>" type="text" name="first_name" required
                   value="<?= e((string) ($tracking['first_name'] ?? '')) ?>">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            Apellido paterno *
            <input style="<?= e($inputStyle) ?>" type="text" name="last_name_p" required
                   value="<?= e((string) ($tracking['last_name_p'] ?? '')) ?>">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            Apellido materno
            <input style="<?= e($inputStyle) ?>" type="text" name="last_name_m"
                   value="<?= e((string) ($tracking['last_name_m'] ?? '')) ?>">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            Correo
            <input style="<?= e($inputStyle) ?>" type="email" name="email"
                   value="<?= e((string) ($tracking['student_email'] ?? $tracking['email'] ?? '')) ?>">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            Teléfono
            <input style="<?= e($inputStyle) ?>" type="text" name="phone"
                   value="<?= e((string) ($tracking['student_phone'] ?? $tracking['phone'] ?? '')) ?>">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            CURP
            <input style="<?= e($inputStyle) ?>" type="text" name="curp"
                   value="<?= e((string) ($tracking['curp'] ?? '')) ?>" maxlength="18">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            Fecha de nacimiento
            <input style="<?= e($inputStyle) ?>" type="date" name="birth_date"
                   value="<?= e((string) ($tracking['birth_date'] ?? '')) ?>">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            Sexo
            <select style="<?= e($inputStyle) ?>" name="sex">
                <option value="">— Selecciona —</option>
                <?php foreach ($sexOptions as $opt): ?>
                    <option value="<?= e($opt['value']) ?>" <?= $sex === $opt['value'] ? 'selected' : '' ?>>
                        <?= e($opt['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
            Nacionalidad
            <select style="<?= e($inputStyle) ?>" name="nationality">
                <option value="">— Selecciona —</option>
                <?php foreach ($nationalityOptions as $opt): ?>
                    <option value="<?= e($opt['value']) ?>" <?= $nationality === $opt['value'] ? 'selected' : '' ?>>
                        <?= e($opt['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <p class="muted" style="font-size:.78rem;margin:.55rem 0 0">
        Puedes corregir estos datos hasta que el alumno presente el examen. Después quedan bloqueados.
    </p>
    <button class="btn btn-accent btn-sm" type="submit" style="margin-top:.65rem">Guardar datos</button>
</form>
<?php endif; ?>

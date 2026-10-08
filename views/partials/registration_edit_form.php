<?php
/**
 * Formulario compartido para editar datos de registro (antes del examen).
 * Si se pasa $registrationFields (desde checkout_fields del grupo), solo se muestran esos.
 * Admin puede omitir la lista para ver/editar el set completo.
 *
 * @var array<string,mixed> $tracking
 * @var string $formAction
 * @var bool $canEdit
 * @var bool|null $canEditRegistration
 * @var string|null $stepCode
 * @var array<string,string>|null $hidden
 * @var list<array{code:string,label:string,required:bool,type:string,options?:list<array{value:string,label:string}>}>|null $registrationFields
 */
$canEdit = (bool) ($canEdit ?? $canEditRegistration ?? true);
$formAction = (string) ($formAction ?? '');
$stepCode = $stepCode ?? null;
$hidden = is_array($hidden ?? null) ? $hidden : [];
$sex = \App\Services\CheckoutRequirements::normalizeSexValue((string) ($tracking['sex'] ?? ''));
$nationality = trim((string) ($tracking['nationality'] ?? ''));
$inputStyle = 'padding:.45rem .55rem;border:1px solid #cfd8e6;border-radius:8px;width:100%';

$allMeta = \App\Services\CheckoutRequirements::allFieldMeta();
/** @var list<array{code:string,label:string,required:bool,type:string,options?:list<array{value:string,label:string}>}> $fields */
$fields = is_array($registrationFields ?? null) ? $registrationFields : [];
if ($fields === []) {
    // Fallback admin / legado: set amplio de datos de persona.
    foreach (['first_name', 'last_name_p', 'last_name_m', 'email', 'phone', 'curp', 'birth_date', 'sex', 'nationality'] as $code) {
        if (!isset($allMeta[$code])) {
            continue;
        }
        $meta = $allMeta[$code];
        $row = [
            'code' => $code,
            'label' => (string) $meta['label'],
            'required' => in_array($code, \App\Services\CheckoutRequirements::LOCKED_FIELDS, true)
                || in_array($code, ['first_name', 'last_name_p'], true),
            'type' => (string) ($meta['type'] ?? 'text'),
        ];
        if ($code === 'sex') {
            $row['options'] = \App\Services\CheckoutRequirements::SEX_OPTIONS;
        }
        if ($code === 'nationality') {
            $row['options'] = \App\Services\CheckoutRequirements::NATIONALITY_OPTIONS;
        }
        $fields[] = $row;
    }
}

$valueFor = static function (string $code) use ($tracking, $sex, $nationality): string {
    return match ($code) {
        'email' => (string) ($tracking['student_email'] ?? $tracking['email'] ?? ''),
        'phone' => (string) ($tracking['student_phone'] ?? $tracking['phone'] ?? ''),
        'sex' => $sex,
        'nationality' => $nationality,
        default => (string) ($tracking[$code] ?? ''),
    };
};
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
        <?php foreach ($fields as $field): ?>
            <?php
            $code = (string) ($field['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $label = (string) ($field['label'] ?? $code);
            $required = !empty($field['required']);
            $type = (string) ($field['type'] ?? 'text');
            $value = $valueFor($code);
            ?>
            <label class="muted" style="display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;font-weight:600">
                <?= e($label) ?><?= $required ? ' *' : '' ?>
                <?php if ($type === 'select'): ?>
                    <?php $options = is_array($field['options'] ?? null) ? $field['options'] : []; ?>
                    <select style="<?= e($inputStyle) ?>" name="<?= e($code) ?>" <?= $required ? 'required' : '' ?>>
                        <option value="">— Selecciona —</option>
                        <?php foreach ($options as $opt): ?>
                            <?php
                            $optValue = (string) ($opt['value'] ?? '');
                            $optLabel = (string) ($opt['label'] ?? $optValue);
                            ?>
                            <option value="<?= e($optValue) ?>" <?= $value === $optValue ? 'selected' : '' ?>>
                                <?= e($optLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($type === 'date'): ?>
                    <input style="<?= e($inputStyle) ?>" type="date" name="<?= e($code) ?>"
                           value="<?= e($value) ?>" <?= $required ? 'required' : '' ?>>
                <?php elseif ($type === 'email'): ?>
                    <input style="<?= e($inputStyle) ?>" type="email" name="<?= e($code) ?>"
                           value="<?= e($value) ?>" <?= $required ? 'required' : '' ?>>
                <?php elseif ($type === 'tel'): ?>
                    <input style="<?= e($inputStyle) ?>" type="tel" name="<?= e($code) ?>"
                           value="<?= e($value) ?>" <?= $required ? 'required' : '' ?>>
                <?php else: ?>
                    <input style="<?= e($inputStyle) ?>" type="text" name="<?= e($code) ?>"
                           value="<?= e($value) ?>"
                           <?= $code === 'curp' ? 'maxlength="18"' : '' ?>
                           <?= $required ? 'required' : '' ?>>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:.78rem;margin:.55rem 0 0">
        Puedes corregir estos datos hasta que el alumno presente el examen. Después quedan bloqueados.
    </p>
    <button class="btn btn-accent btn-sm" type="submit" style="margin-top:.65rem">Guardar datos</button>
</form>
<?php endif; ?>

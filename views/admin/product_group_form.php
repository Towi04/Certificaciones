<?php
/** @var array<string,mixed>|null $group */
/** @var list<array<string,mixed>> $suppliers */
/** @var string $defaultConfig */
/** @var array<string,mixed> $extras */
$isEdit = $group !== null;
$action = $isEdit ? url('/admin/grupos/' . $group['id']) : url('/admin/grupos/nuevo');
$extras = $extras ?? \App\Services\ProductAdminService::groupFormExtrasFromConfig($defaultConfig ?? null);
$preselectSupplier = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
if (!$isEdit && $preselectSupplier > 0 && empty($group['supplier_id'])) {
    $group = ['supplier_id' => $preselectSupplier];
}
?>
<p class="meta"><a href="<?= e(url('/admin/grupos')) ?>">← Grupos de proceso</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar grupo' : 'Nuevo grupo de producto' ?>
</h1>
<p class="muted">
    Define aquí el proceso compartido: pagos, horarios de aplicación, fechas bloqueadas y reglamento.
    Los productos del grupo heredan estos cambios sin tocar código.
</p>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:1rem;max-width:900px">
    <?= csrf_field() ?>
    <input type="hidden" name="apply_structured_config" value="1">

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

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin:1.4rem 0 .5rem">Horarios de aplicación</h2>
    <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
        Solo aplica si el alumno elige fecha/hora en el checkout. Los grupos “siempre disponibles”
        pueden dejar desmarcada la opción y no pedirán agenda.
    </p>
    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.75rem">
        <input type="checkbox" name="exam_choose_at_checkout" value="1"
            <?= !empty($extras['exam_choose_at_checkout']) ? 'checked' : '' ?>>
        Pedir fecha y hora de aplicación en el checkout
    </label>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Minutos por bloque
            <input type="number" name="exam_slot_minutes" min="15" step="5"
                   value="<?= (int) ($extras['exam_slot_minutes'] ?? 30) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Anticipo mínimo (días)
            <input type="number" name="schedule_min_advance_days" min="0" step="1"
                   value="<?= (int) ($extras['schedule_min_advance_days'] ?? 2) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Lun–Vie desde
            <input type="text" name="schedule_weekdays_start" placeholder="10:00"
                   value="<?= e((string) ($extras['schedule_weekdays_start'] ?? '10:00')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Lun–Vie hasta
            <input type="text" name="schedule_weekdays_end" placeholder="17:30"
                   value="<?= e((string) ($extras['schedule_weekdays_end'] ?? '17:30')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Sábado desde
            <input type="text" name="schedule_saturday_start" placeholder="08:00"
                   value="<?= e((string) ($extras['schedule_saturday_start'] ?? '08:00')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Sábado hasta
            <input type="text" name="schedule_saturday_end" placeholder="12:00"
                   value="<?= e((string) ($extras['schedule_saturday_end'] ?? '12:00')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>

    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-top:1rem">
        Fechas bloqueadas (vacaciones / cierres)
        <textarea name="schedule_blocked_dates" rows="4" placeholder="2026-12-24&#10;2026-12-25&#10;2027-01-01"
                  style="padding:.65rem .75rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem"><?= e((string) ($extras['schedule_blocked_dates'] ?? '')) ?></textarea>
        <span style="font-weight:500;font-size:.78rem">
            Una fecha por línea (YYYY-MM-DD). Solo afecta a productos de este grupo que piden agenda.
            Los grupos sin agenda en checkout no se ven afectados.
        </span>
    </label>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin:1.4rem 0 .5rem">Reglamento</h2>
    <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.75rem">
        <input type="checkbox" name="reglamento_enabled" value="1"
            <?= !empty($extras['reglamento_enabled']) ? 'checked' : '' ?>>
        Este grupo requiere reglamento firmado
    </label>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Ruta / URL de la plantilla PDF
            <input type="text" name="reglamento_template_path"
                   value="<?= e((string) ($extras['reglamento_template_path'] ?? '')) ?>"
                   placeholder="/assets/reglamentos/elet-reglamento.pdf"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Link externo (referencia / respaldo)
            <input type="url" name="reglamento_source_url"
                   value="<?= e((string) ($extras['reglamento_source_url'] ?? '')) ?>"
                   placeholder="https://drive.google.com/..."
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
            Código del documento
            <input type="text" name="reglamento_doc_code"
                   value="<?= e((string) ($extras['reglamento_doc_code'] ?? 'reglamento_firmado')) ?>"
                   style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.88rem;font-weight:600;margin-top:1.5rem">
            <input type="checkbox" name="reglamento_required_before_checkout" value="1"
                <?= !empty($extras['reglamento_required_before_checkout']) ? 'checked' : '' ?>>
            Obligatorio antes de pagar
        </label>
    </div>

    <details style="margin-top:1.25rem">
        <summary style="cursor:pointer;font-weight:700;color:var(--doceo-blue)">Configuración JSON avanzada</summary>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;margin-top:.75rem">
            config_json
            <textarea name="config_json" rows="14"
                      style="padding:.65rem .75rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem"><?= e($defaultConfig) ?></textarea>
            <span style="font-weight:500;font-size:.78rem">
                Los campos de horario/reglamento de arriba se aplican encima de este JSON al guardar.
                Usa esto para pagos, MSI, pipeline y otras claves avanzadas.
            </span>
        </label>
    </details>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Guardar grupo' : 'Crear grupo' ?></button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/grupos')) ?>">Cancelar</a>
    </div>
</form>

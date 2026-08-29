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
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$blockedCount = 0;
foreach (preg_split('/\R+/', (string) ($extras['schedule_blocked_dates'] ?? '')) ?: [] as $line) {
    if (trim((string) $line) !== '') {
        $blockedCount++;
    }
}
?>
<p class="meta"><a href="<?= e(url('/admin/grupos')) ?>">← Grupos de proceso</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">
    <?= $isEdit ? 'Editar grupo' : 'Nuevo grupo de producto' ?>
</h1>
<p class="muted">
    Configura lo compartido por varias certificaciones del mismo proveedor:
    horarios, <strong>vacaciones / fechas bloqueadas</strong>, reglamento y pagos.
</p>

<nav class="group-tabs" role="tablist" aria-label="Secciones del grupo">
    <button type="button" class="group-tab active" data-tab="general" role="tab" aria-selected="true">General</button>
    <button type="button" class="group-tab" data-tab="schedule" role="tab" aria-selected="false">Horarios</button>
    <button type="button" class="group-tab" data-tab="vacations" role="tab" aria-selected="false">
        Vacaciones<?php if ($blockedCount > 0): ?> <span class="group-tab-badge"><?= (int) $blockedCount ?></span><?php endif; ?>
    </button>
    <button type="button" class="group-tab" data-tab="rules" role="tab" aria-selected="false">Reglamento</button>
    <button type="button" class="group-tab" data-tab="advanced" role="tab" aria-selected="false">Avanzado</button>
</nav>

<form method="post" action="<?= e($action) ?>" class="panel" style="margin-top:.75rem;max-width:920px">
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
                <input type="text" name="code" required maxlength="40"
                       <?= $isEdit ? 'readonly' : '' ?>
                       value="<?= e((string) ($group['code'] ?? '')) ?>"
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
        <p class="muted" style="font-size:.82rem;margin:1rem 0 0">
            Para bloquear fechas de vacaciones abre la pestaña <strong>Vacaciones</strong>.
        </p>
    </div>

    <div class="group-panel" data-panel="schedule" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Horarios de aplicación</h2>
        <p class="muted" style="font-size:.82rem;margin:0 0 .75rem">
            Solo aplica si el alumno elige fecha/hora en el checkout. Los grupos “siempre disponibles”
            pueden dejar desmarcada la opción.
        </p>
        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.75rem">
            <input type="checkbox" name="exam_choose_at_checkout" value="1"
                <?= !empty($extras['exam_choose_at_checkout']) ? 'checked' : '' ?>>
            Pedir fecha y hora de aplicación en el checkout
        </label>
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
                Sábado desde
                <input type="text" name="schedule_saturday_start" placeholder="08:00"
                       value="<?= e((string) ($extras['schedule_saturday_start'] ?? '08:00')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Sábado hasta
                <input type="text" name="schedule_saturday_end" placeholder="12:00"
                       value="<?= e((string) ($extras['schedule_saturday_end'] ?? '12:00')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
        </div>
    </div>

    <div class="group-panel" data-panel="vacations" hidden>
        <div class="vacations-callout">
            <h2 style="margin:0 0 .35rem;font-size:1.05rem;color:var(--doceo-blue)">Vacaciones y fechas bloqueadas</h2>
            <p class="muted" style="margin:0 0 .85rem;font-size:.88rem">
                Cuando DOCEO cierre o esté de vacaciones, agrega aquí las fechas.
                No se podrán elegir en el checkout de productos de este grupo que pidan agenda.
                Los grupos sin agenda en checkout no se afectan.
            </p>
            <label class="muted" style="<?= e($labelStyle) ?>">
                Fechas bloqueadas (una por línea, YYYY-MM-DD)
                <textarea name="schedule_blocked_dates" rows="8" placeholder="2026-12-24&#10;2026-12-25&#10;2027-01-01"
                          style="padding:.65rem .75rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.9rem"><?= e((string) ($extras['schedule_blocked_dates'] ?? '')) ?></textarea>
            </label>
            <p class="muted" style="font-size:.78rem;margin:.55rem 0 0">
                Ejemplo: <code>2026-12-24</code>, <code>2026-12-25</code>, <code>2027-01-01</code>.
            </p>
        </div>
    </div>

    <div class="group-panel" data-panel="rules" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Reglamento</h2>
        <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;margin-bottom:.75rem">
            <input type="checkbox" name="reglamento_enabled" value="1"
                <?= !empty($extras['reglamento_enabled']) ? 'checked' : '' ?>>
            Este grupo requiere reglamento firmado
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
                <input type="text" name="reglamento_doc_code"
                       value="<?= e((string) ($extras['reglamento_doc_code'] ?? 'reglamento_firmado')) ?>"
                       style="<?= e($inputStyle) ?>">
            </label>
            <label class="muted" style="display:flex;align-items:center;gap:.5rem;font-size:.88rem;font-weight:600;margin-top:1.5rem">
                <input type="checkbox" name="reglamento_required_before_checkout" value="1"
                    <?= !empty($extras['reglamento_required_before_checkout']) ? 'checked' : '' ?>>
                Obligatorio antes de pagar
            </label>
        </div>
    </div>

    <div class="group-panel" data-panel="advanced" hidden>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Configuración JSON avanzada</h2>
        <label class="muted" style="<?= e($labelStyle) ?>">
            config_json
            <textarea name="config_json" rows="16"
                      style="padding:.65rem .75rem;border:1px solid #cfd8e6;border-radius:10px;font-family:ui-monospace,monospace;font-size:.82rem"><?= e($defaultConfig) ?></textarea>
            <span style="font-weight:500;font-size:.78rem">
                Las otras pestañas se aplican encima de este JSON al guardar.
            </span>
        </label>
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
.group-tab-badge {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:1.2rem; height:1.2rem; margin-left:.25rem; border-radius:999px;
    background:#f5df25; color:#243d66; font-size:.72rem;
}
.group-tab.active .group-tab-badge { background:#fff; }
.vacations-callout {
    border:1px solid #cfe0ff;
    background:linear-gradient(180deg,#f5f9ff 0%,#fff 70%);
    border-radius:14px; padding:1rem 1.1rem;
}
</style>
<script>
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.group-tab[data-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.group-panel[data-panel]'));
  if (!tabs.length) return;
  function activate(name) {
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
})();
</script>

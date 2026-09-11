<?php
/** @var list<array{product:array<string,mixed>,status:string,prep_code:string,existing_course:?array,existing_combo:?array}> $candidates */
/** @var array{pending:int,course_only:int,has_package:int} $counts */
/** @var list<array<string,mixed>> $groups */
/** @var list<array<string,mixed>> $suppliers */
/** @var string $q */
/** @var int $supplierId */
$candidates = $candidates ?? [];
$counts = $counts ?? ['pending' => 0, 'course_only' => 0, 'has_package' => 0];
$groups = $groups ?? [];
$suppliers = $suppliers ?? [];
$q = is_string($q ?? null) ? $q : '';
$supplierId = (int) ($supplierId ?? 0);
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;box-sizing:border-box';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$statusLabel = [
    'pending' => 'Pendiente',
    'course_only' => 'Curso sin combo',
    'has_package' => 'Ya tiene paquete',
];
$statusColor = [
    'pending' => '#1d6f42',
    'course_only' => '#9a6b00',
    'has_package' => '#5b6b7c',
];
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Generar cursos de preparación</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Crea en lote un curso por cada certificación (mismo proveedor, certificadora, categoría y logo)
            y opcionalmente el <strong>combo/paquete</strong> certificación + curso.
            Idempotente: si ya existe el código <code>PREP-{código}</code> o el combo, no lo duplica.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= e(url('/admin/productos')) ?>">← Productos</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/combos')) ?>">Combos</a>
    </div>
</div>

<div class="panel" style="margin-top:1rem;display:flex;gap:1rem;flex-wrap:wrap">
    <div><strong style="color:#1d6f42"><?= (int) $counts['pending'] ?></strong> <span class="muted">pendientes</span></div>
    <div><strong style="color:#9a6b00"><?= (int) $counts['course_only'] ?></strong> <span class="muted">curso sin combo</span></div>
    <div><strong style="color:#5b6b7c"><?= (int) $counts['has_package'] ?></strong> <span class="muted">ya con paquete</span></div>
</div>

<form method="get" action="<?= e(url('/admin/productos/generar-cursos-prep')) ?>" class="panel"
      style="margin-top:.75rem;display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));align-items:end">
    <label class="muted" style="<?= e($labelStyle) ?>">
        Proveedor
        <select name="supplier_id" id="prep-supplier-filter" style="<?= e($inputStyle) ?>">
            <option value="">— Todos —</option>
            <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $supplierId === (int) $s['id'] ? 'selected' : '' ?>>
                    <?= e((string) $s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <div>
        <button class="btn btn-primary" type="submit">Aplicar proveedor</button>
    </div>
</form>

<form method="post" action="<?= e(url('/admin/productos/generar-cursos-prep')) ?>" id="prep-bulk-form" class="panel" style="margin-top:.75rem">
    <?= csrf_field() ?>
    <input type="hidden" name="supplier_id" value="<?= $supplierId > 0 ? (int) $supplierId : '' ?>">

    <h2 style="margin:0 0 .75rem;font-size:1.05rem;color:var(--doceo-blue)">Opciones</h2>
    <div style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:1rem">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Prefijo del nombre del curso
            <input type="text" name="name_prefix" value="Curso de preparación:" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Precio público del curso (MXN)
            <input type="number" name="course_public_price" value="1000" min="0" step="0.01" style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Descuento del combo (%)
            <input type="number" name="combo_discount_percent" value="0" min="0" max="90" step="1" style="<?= e($inputStyle) ?>"
                   title="Sobre la suma certificación + curso. 0 = suma sin descuento.">
        </label>
        <label class="muted" style="<?= e($labelStyle) ?>">
            Grupo del curso (opcional)
            <select name="product_group_id" style="<?= e($inputStyle) ?>">
                <option value="">— Heredar de la certificación —</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>"><?= e((string) $g['name']) ?> (<?= e((string) $g['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-bottom:1rem;font-size:.9rem">
        <label style="display:flex;gap:.4rem;align-items:center">
            <input type="checkbox" name="copy_logo" value="1" checked> Copiar logo
        </label>
        <label style="display:flex;gap:.4rem;align-items:center">
            <input type="checkbox" name="create_combo" value="1" checked> Crear combo (paquete)
        </label>
        <label style="display:flex;gap:.4rem;align-items:center">
            <input type="checkbox" name="course_is_public" value="1"> Publicar cursos en catálogo
        </label>
    </div>
    <p class="muted" style="margin:0 0 1rem;font-size:.85rem">
        Por defecto los cursos quedan <strong>activos pero no públicos</strong> para que revises precios / Moodle antes de mostrarlos.
        El código del curso será <code>PREP-{código de la certificación}</code>.
    </p>

    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center;margin-bottom:.75rem">
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <button type="button" class="btn btn-ghost btn-sm" id="prep-select-pending">Seleccionar pendientes</button>
            <button type="button" class="btn btn-ghost btn-sm" id="prep-select-actionable">Pendientes + sin combo</button>
            <button type="button" class="btn btn-ghost btn-sm" id="prep-select-none">Ninguna</button>
            <span class="muted" id="prep-selected-count" style="font-size:.85rem;margin-left:.25rem"></span>
        </div>
        <button class="btn btn-accent" type="submit"
                onclick="return confirm('¿Generar cursos/combos para las certificaciones seleccionadas?');">
            Generar seleccionadas
        </button>
    </div>

    <div class="panel" style="margin:0 0 .75rem;padding:.75rem 1rem;background:#f8fafc;display:grid;gap:.55rem;grid-template-columns:minmax(220px,1fr) auto;align-items:end">
        <label class="muted" style="<?= e($labelStyle) ?>">
            Buscar en la lista
            <input type="search" id="prep-live-search" value="<?= e($q) ?>"
                   placeholder="Escribe código o nombre…" autocomplete="off"
                   style="<?= e($inputStyle) ?>">
        </label>
        <p class="muted" style="margin:0;font-size:.8rem;max-width:22rem">
            El filtro solo oculta filas: <strong>tus checks se conservan</strong> mientras marcas la lista.
            El proveedor sí recarga la página.
        </p>
    </div>

    <div class="table-wrap">
        <table class="data" id="prep-certs-table">
            <thead>
            <tr>
                <th style="width:2.5rem">
                    <input type="checkbox" id="prep-master-check"
                           title="Marcar / desmarcar todas (o solo las visibles si hay búsqueda)"
                           aria-label="Marcar o desmarcar todas las certificaciones">
                </th>
                <th>Estado</th>
                <th>Certificación</th>
                <th>Proveedor</th>
                <th>Código curso</th>
                <th>Combo</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($candidates as $row):
                $p = $row['product'];
                $status = (string) $row['status'];
                // Empieza sin selección: marca solo las certificaciones que sí quieres generar.
                $checked = false;
                $searchHaystack = mb_strtolower(
                    trim((string) ($p['code'] ?? '') . ' ' . (string) ($p['name'] ?? '') . ' ' . (string) ($p['supplier_name'] ?? '')),
                    'UTF-8'
                );
                ?>
                <tr class="prep-cert-row" data-search="<?= e($searchHaystack) ?>">
                    <td>
                        <input type="checkbox"
                               class="prep-cert-check"
                               name="certification_ids[]"
                               value="<?= (int) $p['id'] ?>"
                               data-status="<?= e($status) ?>"
                            <?= $checked ? 'checked' : '' ?>>
                    </td>
                    <td>
                        <span style="font-weight:700;color:<?= e($statusColor[$status] ?? '#333') ?>">
                            <?= e($statusLabel[$status] ?? $status) ?>
                        </span>
                    </td>
                    <td>
                        <code><?= e((string) $p['code']) ?></code><br>
                        <a href="<?= e(url('/admin/productos/' . (int) $p['id'])) ?>"><?= e((string) $p['name']) ?></a>
                    </td>
                    <td><?= e((string) ($p['supplier_name'] ?? '—')) ?></td>
                    <td>
                        <code><?= e((string) $row['prep_code']) ?></code>
                        <?php if (!empty($row['existing_course'])): ?>
                            <div class="muted" style="font-size:.8rem">ya existe</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['existing_combo'])): ?>
                            <a href="<?= e(url('/admin/combos/' . (int) $row['existing_combo']['id'])) ?>">
                                <?= e((string) $row['existing_combo']['code']) ?>
                            </a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($candidates === []): ?>
                <tr id="prep-empty-server"><td colspan="6" class="muted">No hay certificaciones con esos filtros.</td></tr>
            <?php endif; ?>
                <tr id="prep-empty-filter" hidden><td colspan="6" class="muted">Ninguna certificación coincide con la búsqueda.</td></tr>
            </tbody>
        </table>
    </div>
</form>

<script>
(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.prep-cert-row'));
  var checks = Array.prototype.slice.call(document.querySelectorAll('.prep-cert-check'));
  var searchInput = document.getElementById('prep-live-search');
  var master = document.getElementById('prep-master-check');
  var emptyFilter = document.getElementById('prep-empty-filter');
  var countEl = document.getElementById('prep-selected-count');
  var syncingMaster = false;

  function visibleRows() {
    return rows.filter(function (row) { return row.style.display !== 'none'; });
  }

  function updateSelectedCount() {
    if (!countEl) return;
    var selected = checks.filter(function (el) { return el.checked; }).length;
    var visible = visibleRows().length;
    countEl.textContent = selected + ' seleccionada' + (selected === 1 ? '' : 's')
      + (searchInput && searchInput.value.trim() !== ''
        ? ' · ' + visible + ' visible' + (visible === 1 ? '' : 's')
        : '');
  }

  function syncMasterFromChecks() {
    if (!master || syncingMaster) return;
    var vis = visibleRows();
    var visChecks = vis.map(function (row) {
      return row.querySelector('.prep-cert-check');
    }).filter(Boolean);
    if (visChecks.length === 0) {
      master.checked = false;
      master.indeterminate = false;
      return;
    }
    var checkedCount = visChecks.filter(function (el) { return el.checked; }).length;
    master.checked = checkedCount === visChecks.length;
    master.indeterminate = checkedCount > 0 && checkedCount < visChecks.length;
  }

  function applyFilter() {
    var q = (searchInput ? searchInput.value : '').trim().toLowerCase();
    var visible = 0;
    rows.forEach(function (row) {
      var hay = row.getAttribute('data-search') || '';
      var show = q === '' || hay.indexOf(q) !== -1;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (emptyFilter) {
      emptyFilter.hidden = !(rows.length > 0 && visible === 0);
    }
    syncMasterFromChecks();
    updateSelectedCount();
  }

  function selectBy(fn, onlyVisible) {
    var list = onlyVisible ? visibleRows() : rows;
    list.forEach(function (row) {
      var el = row.querySelector('.prep-cert-check');
      if (!el) return;
      el.checked = !!fn(el.getAttribute('data-status'));
    });
    syncMasterFromChecks();
    updateSelectedCount();
  }

  var pendingBtn = document.getElementById('prep-select-pending');
  var actionableBtn = document.getElementById('prep-select-actionable');
  var noneBtn = document.getElementById('prep-select-none');
  if (pendingBtn) pendingBtn.addEventListener('click', function () {
    selectBy(function (s) { return s === 'pending'; }, false);
  });
  if (actionableBtn) actionableBtn.addEventListener('click', function () {
    selectBy(function (s) { return s === 'pending' || s === 'course_only'; }, false);
  });
  if (noneBtn) noneBtn.addEventListener('click', function () {
    selectBy(function () { return false; }, false);
  });

  if (master) {
    master.addEventListener('change', function () {
      syncingMaster = true;
      var on = master.checked;
      // Sin búsqueda: marca/desmarca TODAS. Con filtro: solo las visibles.
      var filtering = !!(searchInput && searchInput.value.trim() !== '');
      var targets = filtering ? visibleRows() : rows;
      targets.forEach(function (row) {
        var el = row.querySelector('.prep-cert-check');
        if (el) el.checked = on;
      });
      master.indeterminate = false;
      syncingMaster = false;
      updateSelectedCount();
    });
  }

  checks.forEach(function (el) {
    el.addEventListener('change', function () {
      syncMasterFromChecks();
      updateSelectedCount();
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', applyFilter);
    searchInput.addEventListener('search', applyFilter);
  }

  applyFilter();
})();
</script>

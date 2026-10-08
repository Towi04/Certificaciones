<?php
/** @var array<string,mixed> $partner */
/** @var list<array<string,mixed>> $products */
/** @var array<string,mixed>|null $selected */
/** @var list<array<string,mixed>> $batches */
/** @var array<string,mixed>|null $preview */
$inputStyle = 'padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;box-sizing:border-box';
$labelStyle = 'display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600';
$stepProof = is_array($preview ?? null);
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center" data-tour="bulk-header">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Registrar grupo</h1>
        <p class="muted" style="margin:.25rem 0 0">
            Un producto · misma fecha de examen · CSV de alumnos · un solo comprobante
            (monto = precio partner × N).
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/partner/registrar-grupo/plantilla.csv')) ?>">Descargar plantilla CSV</a>
</div>

<?php if ($stepProof): ?>
    <div class="panel" style="margin-top:1rem;max-width:720px" data-tour="bulk-proof">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Paso 2 · Confirmar y subir comprobante</h2>
        <p style="margin-top:0">
            Producto #<?= (int) ($preview['product_id'] ?? 0) ?>
            · <?= (int) ($preview['valid_count'] ?? 0) ?> alumno(s) válidos
            · fecha <?= e((string) ($preview['exam_date'] ?? '—')) ?>
            <?= !empty($preview['exam_time']) ? e(substr((string) $preview['exam_time'], 0, 5)) : '' ?>
        </p>
        <p>
            Precio unitario partner: <strong><?= money($preview['unit_price'] ?? 0) ?></strong><br>
            Monto esperado del comprobante:
            <strong style="font-size:1.15rem;color:var(--doceo-blue)"><?= money($preview['expected_amount'] ?? 0) ?></strong>
        </p>
        <?php if (!empty($preview['errors']) && is_array($preview['errors'])): ?>
            <div class="flash flash-warning" style="margin:.75rem 0">
                Se omitieron filas con error:
                <ul style="margin:.35rem 0 0;padding-left:1.1rem">
                    <?php foreach (array_slice($preview['errors'], 0, 12) as $err): ?>
                        <li><?= e((string) $err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($preview['sample_rows']) && is_array($preview['sample_rows'])): ?>
            <div class="table-wrap" style="margin:.75rem 0">
                <table class="data">
                    <thead><tr><th>Correo</th><th>Nombre</th><th>Teléfono</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['sample_rows'] as $r): ?>
                        <tr>
                            <td><?= e((string) ($r['email'] ?? '')) ?></td>
                            <td><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name_p'] ?? ''))) ?></td>
                            <td><?= e((string) ($r['phone'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/partner/registrar-grupo')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="preview_token" value="<?= e((string) ($preview['token'] ?? '')) ?>">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Comprobante de pago (PDF o imagen) *
                <input type="file" name="payment_proof" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*"
                       style="<?= e($inputStyle) ?>">
            </label>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
                <button class="btn btn-accent" type="submit">Crear lote en revisión</button>
                <a class="btn btn-ghost" href="<?= e(url('/partner/registrar-grupo?producto=' . (int) ($preview['product_id'] ?? 0))) ?>">Cancelar</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <form method="post" action="<?= e(url('/partner/registrar-grupo/preview')) ?>" enctype="multipart/form-data"
          class="panel" style="margin-top:1rem;max-width:720px" data-tour="bulk-form" id="bulk-form">
        <?= csrf_field() ?>
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Paso 1 · Producto, fecha y CSV</h2>
        <div style="display:grid;gap:.85rem">
            <label class="muted" style="<?= e($labelStyle) ?>">
                Producto *
                <select name="product_id" id="bulk-product" required style="<?= e($inputStyle) ?>">
                    <option value="">— elige certificación —</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"
                                data-slug="<?= e((string) ($p['slug'] ?? '')) ?>"
                                data-price="<?= e((string) ($p['partner_price'] ?? '0')) ?>"
                            <?= $selected && (int) $selected['id'] === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e((string) $p['name']) ?> · <?= money($p['partner_price'] ?? 0) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p id="bulk-unit-price" class="muted" style="margin:0;font-size:.85rem"></p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Fecha de examen *
                    <input type="date" name="exam_date" id="bulk-exam-date" required style="<?= e($inputStyle) ?>">
                </label>
                <label class="muted" style="<?= e($labelStyle) ?>">
                    Hora *
                    <select name="exam_time" id="bulk-exam-time" required style="<?= e($inputStyle) ?>">
                        <option value="">— elige fecha primero —</option>
                    </select>
                </label>
            </div>
            <p id="bulk-slots-hint" class="muted" style="margin:0;font-size:.8rem">
                Los horarios usan las mismas reglas del checkout (anticipo, vacaciones, slots del grupo).
            </p>
            <label class="muted" style="<?= e($labelStyle) ?>">
                CSV de alumnos *
                <input type="file" name="students_csv" required accept=".csv,text/csv" style="<?= e($inputStyle) ?>">
            </label>
        </div>
        <button class="btn btn-accent" type="submit" style="margin-top:1rem">Revisar CSV</button>
    </form>
<?php endif; ?>

<?php if ($batches !== []): ?>
<div class="panel" style="margin-top:1.25rem" data-tour="bulk-history">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Tus lotes recientes</h2>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>#</th>
                <th>Producto</th>
                <th>Examen</th>
                <th>Alumnos</th>
                <th>Monto</th>
                <th>Estatus</th>
                <th>Creado</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td><?= (int) $b['id'] ?></td>
                    <td><?= e((string) ($b['product_name'] ?? '')) ?></td>
                    <td>
                        <?= e((string) ($b['exam_date'] ?? '—')) ?>
                        <?php if (!empty($b['exam_time'])): ?>
                            <?= e(substr((string) $b['exam_time'], 0, 5)) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($b['student_count'] ?? 0) ?></td>
                    <td><?= money($b['expected_amount'] ?? 0) ?></td>
                    <td><span class="pill"><?= e((string) ($b['status'] ?? '')) ?></span></td>
                    <td style="font-size:.82rem"><?= e((string) ($b['created_at'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!$stepProof): ?>
<script>
(function () {
  var productSel = document.getElementById('bulk-product');
  var dateEl = document.getElementById('bulk-exam-date');
  var timeEl = document.getElementById('bulk-exam-time');
  var priceEl = document.getElementById('bulk-unit-price');
  var hint = document.getElementById('bulk-slots-hint');
  if (!productSel || !dateEl || !timeEl) return;

  function selectedOption() {
    return productSel.options[productSel.selectedIndex] || null;
  }
  function updatePrice() {
    var opt = selectedOption();
    if (!opt || !opt.value) { priceEl.textContent = ''; return; }
    var p = opt.getAttribute('data-price') || '0';
    priceEl.textContent = 'Precio partner unitario: $' + Number(p).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }
  function loadSlots() {
    var opt = selectedOption();
    var slug = opt ? (opt.getAttribute('data-slug') || '') : '';
    var date = dateEl.value;
    timeEl.innerHTML = '<option value="">Cargando…</option>';
    if (!slug || !date) {
      timeEl.innerHTML = '<option value="">— elige producto y fecha —</option>';
      return;
    }
    fetch(<?= json_encode(url('/api/examen-slots/')) ?> + encodeURIComponent(slug) + '?date=' + encodeURIComponent(date))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        timeEl.innerHTML = '';
        if (!data.ok || !data.slots || !data.slots.length) {
          timeEl.innerHTML = '<option value="">Sin horarios ese día</option>';
          if (hint) hint.textContent = data.error || 'No hay horarios disponibles para esa fecha.';
          return;
        }
        timeEl.appendChild(new Option('— elige hora —', ''));
        data.slots.forEach(function (s) {
          var val = s.value || s.exam_time || '';
          var label = s.label || val;
          timeEl.appendChild(new Option(label, val));
        });
        if (hint) hint.textContent = 'Horarios según reglas del producto.';
      })
      .catch(function () {
        timeEl.innerHTML = '<option value="">Error al cargar horarios</option>';
      });
  }
  productSel.addEventListener('change', function () { updatePrice(); loadSlots(); });
  dateEl.addEventListener('change', loadSlots);
  updatePrice();
})();
</script>
<?php endif; ?>

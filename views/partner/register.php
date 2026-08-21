<?php
/** @var array<string,mixed> $partner */
/** @var list<array<string,mixed>> $products */
?>
<p class="meta"><a href="<?= e(url('/partner')) ?>">← Mis alumnos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)">Registrar alumno</h1>
<p class="muted">Se cobra a tu precio de nivel <strong><?= e(strtoupper((string) $partner['tier'])) ?></strong> (cuenta partner). Para certificaciones la fecha de examen es obligatoria.</p>

<form method="post" action="<?= e(url('/partner/registrar')) ?>" class="panel" style="margin-top:1rem">
    <?= csrf_field() ?>

    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">1. Producto</h2>
    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;max-width:520px">
        Certificación / curso *
        <select name="product_id" id="product_id" required style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            <option value="">— elige —</option>
            <?php foreach ($products as $p): ?>
                <option
                    value="<?= (int) $p['id'] ?>"
                    data-type="<?= e($p['type']) ?>"
                    data-price="<?= e((string) $p['partner_price']) ?>"
                >
                    <?= e($p['name']) ?> · <?= e($p['type']) ?> · <?= money($p['partner_price']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <p class="muted" id="price-hint" style="font-size:.85rem"></p>

    <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin-top:1.25rem">2. Datos del alumno</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem">
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Correo *
            <input type="email" name="email" required style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Teléfono *
            <input type="tel" name="phone" required style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Nombre(s) *
            <input type="text" name="first_name" required style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Apellido paterno *
            <input type="text" name="last_name_p" required style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Apellido materno
            <input type="text" name="last_name_m" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
        </label>
    </div>

    <div id="exam-block">
        <h2 style="font-size:1.05rem;color:var(--doceo-blue);margin-top:1.25rem">3. Fecha de examen</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Fecha *
                <input type="date" name="exam_date" id="exam_date" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">Hora *
                <input type="time" name="exam_time" id="exam_time" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">2ª fecha (opcional)
                <input type="date" name="exam_date_2" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">2ª hora
                <input type="time" name="exam_time_2" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
            <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600;grid-column:1/-1">Zoom / enlace (si ya lo tienes)
                <input type="url" name="zoom_url" placeholder="https://…" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            </label>
        </div>
    </div>

    <div style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap">
        <button class="btn btn-accent" type="submit">Registrar alumno</button>
        <a class="btn btn-ghost" href="<?= e(url('/partner')) ?>">Cancelar</a>
    </div>
</form>
<script>
(function () {
  const sel = document.getElementById('product_id');
  const hint = document.getElementById('price-hint');
  const examBlock = document.getElementById('exam-block');
  const examDate = document.getElementById('exam_date');
  const examTime = document.getElementById('exam_time');
  function sync() {
    const opt = sel.options[sel.selectedIndex];
    const type = opt ? opt.getAttribute('data-type') : '';
    const price = opt ? opt.getAttribute('data-price') : '';
    hint.textContent = price ? ('Tu precio: $' + Number(price).toLocaleString('es-MX', {minimumFractionDigits: 2})) : '';
    const needsExam = type === 'certification' || type === 'procedure';
    examBlock.style.display = needsExam || type === '' ? 'block' : 'none';
    examDate.required = !!needsExam;
    examTime.required = !!needsExam;
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>

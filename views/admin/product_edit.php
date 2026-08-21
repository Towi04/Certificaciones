<?php /** @var array<string,mixed> $product */ ?>
<p class="meta"><a href="<?= e(url('/admin/productos')) ?>">← Productos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($product['name']) ?></h1>
<p class="muted"><?= e($product['code']) ?> · <?= e($product['type']) ?></p>

<form method="post" action="<?= e(url('/admin/productos/' . $product['id'])) ?>" class="panel" style="margin-top:1rem;max-width:560px">
    <?= csrf_field() ?>

    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Campus Moodle</h2>
    <p class="muted" style="font-size:.88rem">
        El ID es el número del curso en Moodle (no el shortname).<br>
        En campus: entra al curso → mira la URL<br>
        <code>…/course/view.php?id=<strong>123</strong></code> → ese <strong>123</strong> es el valor.
    </p>

    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:.85rem;font-size:.88rem;font-weight:600">
        Plataforma
        <select name="platform_type" style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
            <?php foreach (['none' => 'Ninguna', 'moodle' => 'Moodle (campus DOCEO)', 'provider' => 'Proveedor externo'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= ($product['platform_type'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:.85rem;font-size:.88rem;font-weight:600">
        moodle_course_id
        <input
            type="number"
            name="moodle_course_id"
            min="1"
            step="1"
            placeholder="Ej. 12"
            value="<?= e($product['moodle_course_id'] !== null && $product['moodle_course_id'] !== '' ? (string) $product['moodle_course_id'] : '') ?>"
            style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;max-width:200px"
        >
    </label>

    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;font-size:.88rem;font-weight:600">
        Meses de acceso
        <input
            type="number"
            name="access_months"
            min="1"
            max="60"
            value="<?= (int) ($product['access_months'] ?? 6) ?>"
            style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;max-width:120px"
        >
    </label>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <button class="btn btn-accent" type="submit">Guardar</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/productos')) ?>">Cancelar</a>
    </div>
</form>

<div class="panel" style="margin-top:1rem;max-width:560px">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Después de guardar</h2>
    <ol style="margin:0;padding-left:1.2rem" class="muted">
        <li>Confirma que /admin/salud → Moodle está OK.</li>
        <li>En un caso de ese curso: <strong>Sincronizar Moodle</strong>, o confirma un pago nuevo.</li>
    </ol>
</div>

<?php
/** @var list<array<string,mixed>> $combos */
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Combos</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Define paquetes con precio propio (público y partners): certificación + curso,
            certificación + trámite CENNI/CONOCER, o los tres. En el checkout el alumno verá
            “Convertir en combo” al adquirir cualquiera de los productos del paquete.
        </p>
    </div>
    <a class="btn btn-accent" href="<?= e(url('/admin/combos/nuevo')) ?>">Nuevo combo</a>
</div>

<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Ítems</th>
                <th>Público</th>
                <th>Lista</th>
                <th>Activo</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($combos as $c): ?>
                <tr>
                    <td><code><?= e((string) $c['code']) ?></code><?= !empty($c['is_star']) ? ' ⭐' : '' ?></td>
                    <td><?= e((string) $c['name']) ?></td>
                    <td><?= (int) ($c['items_count'] ?? 0) ?></td>
                    <td><?= money($c['public_price']) ?></td>
                    <td><?= money($c['catalog_price']) ?></td>
                    <td><?= !empty($c['is_active']) ? 'Sí' : 'No' ?></td>
                    <td><a href="<?= e(url('/admin/combos/' . (int) $c['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($combos === []): ?>
                <tr><td colspan="7" class="muted">Aún no hay combos. Crea uno con al menos 2 productos.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

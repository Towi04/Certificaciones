<?php
/** @var list<array<string,mixed>> $groups */
/** @var array<int,int> $counts */
?>
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Grupos de producto</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:46rem">
            Aquí se define el proceso de compra compartido (pagos, MSI, horarios, reglamento, pipeline).
            Las <a href="<?= e(url('/admin/vacaciones')) ?>"><strong>vacaciones globales</strong></a> aplican a todos, salvo grupos marcados 365 días.
            Los horarios de examen se editan dentro de cada grupo (pestaña Fechas y horarios).
            Luego cada producto personaliza nombre, descripción, precios, nivel e imágenes.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <form method="post" action="<?= e(url('/admin/grupos/sugeridos')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-ghost" type="submit">Cargar grupos sugeridos</button>
        </form>
        <a class="btn btn-accent" href="<?= e(url('/admin/grupos/nuevo')) ?>">Nuevo grupo</a>
    </div>
</div>

<?php require BASE_PATH . '/views/shared/pagination.php'; ?>

<div class="panel" style="margin-top:1rem">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Proveedor</th>
                <th>Productos</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td><code><?= e($g['code']) ?></code></td>
                    <td><?= e($g['name']) ?></td>
                    <td><?= e($g['supplier_name'] ?? '—') ?></td>
                    <td><?= (int) ($counts[(int) $g['id']] ?? 0) ?></td>
                    <td>
                        <span class="row-actions">
                            <a class="icon-btn" href="<?= e(url('/admin/grupos/' . $g['id'])) ?>" title="Editar" aria-label="Editar"><?= icon('edit') ?></a>
                            <form class="icon-btn-form" method="post" action="<?= e(url('/admin/grupos/' . $g['id'] . '/eliminar')) ?>"
                                  onsubmit="return confirm('¿Eliminar este grupo? Solo si no tiene productos.');">
                                <?= csrf_field() ?>
                                <button class="icon-btn" type="submit" title="Eliminar" aria-label="Eliminar"><?= icon('trash') ?></button>
                            </form>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($groups === []): ?>
                <tr>
                    <td colspan="5" class="muted">
                        Aún no hay grupos. Usa <strong>Cargar grupos sugeridos</strong> (ELeT, iTEP, TOEFL…)
                        o crea uno nuevo.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

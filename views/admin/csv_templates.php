<?php
/** @var list<array<string,mixed>> $templates */
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Plantillas proveedor</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:48rem">
            Catálogo de CSV que Operación descarga para registrar alumnos ante el proveedor
            (UKS, Cambridge, etc.). Asigna la plantilla en <strong>Grupos → Progreso</strong>
            (acción «Descargar CSV»). El seed trae UKS y un ejemplo Cambridge; el Excel de correo
            (TOEFL) se migra en una fase posterior.
        </p>
    </div>
    <a class="btn btn-accent" href="<?= e(url('/admin/plantillas-csv/nueva')) ?>">Nueva plantilla CSV</a>
</div>

<div class="panel" style="margin-top:1rem">
    <?php if ($templates === []): ?>
        <p class="muted" style="margin:0">Aún no hay plantillas. Crea una o ejecuta el seed (UKS + Cambridge).</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Proveedor</th>
                    <th>Código</th>
                    <th>Lote</th>
                    <th>Normalizar</th>
                    <th>Activa</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $tpl): ?>
                    <?php
                    $mapping = (new \App\Services\ExportService())->mapping($tpl);
                    $normalize = (string) ($mapping['normalize'] ?? 'none');
                    $supplierLabel = trim((string) ($tpl['supplier_name'] ?? ''));
                    ?>
                    <tr>
                        <td><strong><?= e((string) ($tpl['name'] ?? '')) ?></strong></td>
                        <td><?= $supplierLabel !== '' ? e($supplierLabel) : '<span class="muted">—</span>' ?></td>
                        <td><code><?= e((string) ($tpl['code'] ?? '')) ?></code></td>
                        <td><?= ($tpl['batch_by'] ?? '') === 'exam_date' ? 'Por fecha' : 'Manual / alumno' ?></td>
                        <td><?= $normalize === 'toefl' ? 'TOEFL (sin acentos/Ñ, MAYÚS)' : 'Sin cambios' ?></td>
                        <td><?= !empty($tpl['is_active']) ? 'Sí' : 'No' ?></td>
                        <td>
                            <span class="row-actions">
                                <a class="icon-btn" href="<?= e(url('/admin/plantillas-csv/' . rawurlencode((string) $tpl['code']))) ?>"
                                   title="Editar" aria-label="Editar"><?= icon('edit') ?></a>
                                <a class="icon-btn" href="<?= e(url('/admin/exportaciones/' . rawurlencode((string) $tpl['code']))) ?>"
                                   title="Descargar" aria-label="Descargar"><?= icon('export') ?></a>
                                <form class="icon-btn-form" method="post"
                                      action="<?= e(url('/admin/plantillas-csv/' . rawurlencode((string) $tpl['code']) . '/eliminar')) ?>"
                                      onsubmit="return confirm('¿Eliminar esta plantilla CSV?');">
                                    <?= csrf_field() ?>
                                    <button class="icon-btn" type="submit" title="Eliminar" aria-label="Eliminar"><?= icon('trash') ?></button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

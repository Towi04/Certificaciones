<?php
/** @var list<array<string,mixed>> $templates */
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Plantillas CSV</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:48rem">
            Define columnas y datos para descargar registros al proveedor
            (por alumno o por fecha de examen).
        </p>
    </div>
    <a class="btn btn-accent" href="<?= e(url('/admin/plantillas-csv/nueva')) ?>">Nueva plantilla</a>
</div>

<div class="panel" style="margin-top:1rem">
    <?php if ($templates === []): ?>
        <p class="muted" style="margin:0">Aún no hay plantillas CSV. Crea una o ejecuta el seed (incluye UKS).</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Nombre</th>
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
                    ?>
                    <tr>
                        <td><strong><?= e((string) ($tpl['name'] ?? '')) ?></strong></td>
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

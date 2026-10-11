<?php
/** @var list<array<string,mixed>> $templates */
/** @var string $kind */
$kind = in_array(($kind ?? 'csv'), ['csv', 'xlsx', 'all'], true) ? $kind : 'csv';
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Plantillas proveedor</h1>
        <p class="muted" style="margin:.35rem 0 0;max-width:48rem">
            <strong>CSV</strong> = descarga en Operación.
            <strong>Excel</strong> = archivo para correo (<code>{{workbook_url}}</code>).
            Migración legacy: <code>php bin/migrate-provider-workbooks.php</code> (no borra Settings).
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn btn-accent" href="<?= e(url('/admin/plantillas-csv/nueva')) ?>">Nueva CSV</a>
        <a class="btn btn-ghost" href="<?= e(url('/admin/plantillas-csv/nueva?tipo=xlsx')) ?>">Nueva Excel</a>
    </div>
</div>

<nav style="display:flex;gap:.5rem;margin:1rem 0 .5rem;flex-wrap:wrap">
    <?php
    $tabs = [
        'csv' => 'CSV (descarga)',
        'xlsx' => 'Excel (correo)',
        'all' => 'Todas',
    ];
    foreach ($tabs as $val => $label):
        $active = $kind === $val;
        ?>
        <a class="btn <?= $active ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
           href="<?= e(url('/admin/plantillas-csv?tipo=' . $val)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<div class="panel" style="margin-top:.5rem">
    <?php if ($templates === []): ?>
        <p class="muted" style="margin:0">
            No hay plantillas<?= $kind === 'xlsx' ? ' Excel' : ($kind === 'csv' ? ' CSV' : '') ?>.
            <?php if ($kind === 'xlsx'): ?>
                Ejecuta la migración o crea una nueva.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th>Proveedor</th>
                    <th>Código</th>
                    <th>Detalle</th>
                    <th>Activa</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $tpl): ?>
                    <?php
                    $ft = strtolower((string) ($tpl['file_type'] ?? 'csv'));
                    $supplierLabel = trim((string) ($tpl['supplier_name'] ?? ''));
                    $detail = '—';
                    if ($ft === 'xlsx') {
                        $map = (new \App\Services\ProviderWorkbookCatalogService())->mapping($tpl);
                        $cells = is_array($map['cell_map'] ?? null) ? count($map['cell_map']) : 0;
                        $detail = $cells . ' celdas'
                            . (trim((string) ($tpl['storage_path'] ?? '')) !== '' ? '' : ' · sin archivo');
                    } else {
                        $mapping = (new \App\Services\ExportService())->mapping($tpl);
                        $normalize = (string) ($mapping['normalize'] ?? 'none');
                        $detail = (($tpl['batch_by'] ?? '') === 'exam_date' ? 'Lote por fecha' : 'Manual')
                            . ($normalize === 'toefl' ? ' · TOEFL norm.' : '');
                    }
                    ?>
                    <tr>
                        <td><strong><?= e((string) ($tpl['name'] ?? '')) ?></strong></td>
                        <td><code><?= e(strtoupper($ft)) ?></code></td>
                        <td><?= $supplierLabel !== '' ? e($supplierLabel) : '<span class="muted">—</span>' ?></td>
                        <td><code><?= e((string) ($tpl['code'] ?? '')) ?></code></td>
                        <td class="muted" style="font-size:.84rem"><?= e($detail) ?></td>
                        <td><?= !empty($tpl['is_active']) ? 'Sí' : 'No' ?></td>
                        <td>
                            <span class="row-actions">
                                <a class="icon-btn" href="<?= e(url('/admin/plantillas-csv/' . rawurlencode((string) $tpl['code']))) ?>"
                                   title="Editar" aria-label="Editar"><?= icon('edit') ?></a>
                                <?php if ($ft === 'csv'): ?>
                                    <a class="icon-btn" href="<?= e(url('/admin/exportaciones/' . rawurlencode((string) $tpl['code']))) ?>"
                                       title="Descargar" aria-label="Descargar"><?= icon('export') ?></a>
                                <?php endif; ?>
                                <form class="icon-btn-form" method="post"
                                      action="<?= e(url('/admin/plantillas-csv/' . rawurlencode((string) $tpl['code']) . '/eliminar')) ?>"
                                      onsubmit="return confirm('¿Eliminar esta plantilla del catálogo?');">
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

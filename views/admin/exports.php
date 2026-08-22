<?php /** @var list<array<string,mixed>> $templates */ ?>
<?php /** @var list<array<string,mixed>> $importTemplates */ ?>
<h1 style="margin:.2rem 0 1rem;color:var(--doceo-blue)">Integración UKS</h1>
<p class="muted">Exporta alumnos para registrar en UKS e importa reportes con resultados y estatus CENNI.</p>

<?php if ($templates === []): ?>
    <div class="panel">
        <p class="muted">No hay plantillas activas. Ejecuta el seed de catálogo desde <a href="<?= e(url('/setup')) ?>">/setup</a>.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Plantilla</th>
                    <th>Proveedor</th>
                    <th>Formato</th>
                    <th>Lote</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($templates as $tpl): ?>
                <tr>
                    <td>
                        <strong><?= e($tpl['name']) ?></strong><br>
                        <code class="muted" style="font-size:.82rem"><?= e($tpl['code']) ?></code>
                    </td>
                    <td><?= e($tpl['supplier_name'] ?? '—') ?></td>
                    <td><?= e(strtoupper((string) $tpl['file_type'])) ?></td>
                    <td><?= ($tpl['batch_by'] ?? 'none') === 'exam_date' ? 'Por fecha de examen' : 'Manual' ?></td>
                    <td style="white-space:nowrap">
                        <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/exportaciones/' . $tpl['code'])) ?>">Descargar pendientes</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($templates as $tpl): ?>
        <?php if (($tpl['batch_by'] ?? '') === 'exam_date'): ?>
            <div class="panel" style="margin-top:1rem">
                <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)"><?= e($tpl['name']) ?> · por fecha</h2>
                <form method="get" action="<?= e(url('/admin/exportaciones/' . $tpl['code'])) ?>" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
                    <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                        Fecha de examen
                        <input type="date" name="exam_date" required style="padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px">
                    </label>
                    <button class="btn btn-accent" type="submit">Descargar lote</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<h2 style="margin:2rem 0 .75rem;color:var(--doceo-blue);font-size:1.15rem">Importar reporte UKS</h2>
<p class="muted">Sube el CSV <em>Reporte Instituto DOCEO ELET</em> que descargas de UKS. Actualiza resultados del examen, estatus de documentos CENNI y folio CENNI; el alumno lo ve en su panel y recibe correo si hay cambios relevantes.</p>

<?php if (($importTemplates ?? []) === []): ?>
    <div class="panel"><p class="muted">Sin plantillas de importación. Ejecuta el seed de catálogo.</p></div>
<?php else: ?>
    <?php foreach ($importTemplates as $tpl): ?>
        <div class="panel" style="margin-top:1rem">
            <h3 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)"><?= e($tpl['name']) ?></h3>
            <p class="muted" style="margin-top:0"><code><?= e($tpl['code']) ?></code> · coincide por matrícula</p>
            <form method="post" action="<?= e(url('/admin/importaciones/' . $tpl['code'])) ?>" enctype="multipart/form-data" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
                <?= csrf_field() ?>
                <label class="muted" style="display:flex;flex-direction:column;gap:.35rem;font-size:.88rem;font-weight:600">
                    Archivo CSV
                    <input type="file" name="csv_file" accept=".csv,text/csv" required style="padding:.45rem;border:1px solid #cfd8e6;border-radius:10px">
                </label>
                <button class="btn btn-accent" type="submit">Importar y notificar alumnos</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

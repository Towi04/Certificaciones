<?php
/** @var array<string,mixed> $tracking */
/** @var list<array<string,mixed>> $steps */
/** @var list<array<string,mixed>> $documents */
/** @var list<array<string,mixed>> $logs */
$current = (string) ($tracking['current_step_code'] ?? '');
$statusLabels = [
    'open' => 'En proceso',
    'waiting_admin' => 'En revisión DOCEO',
    'waiting_student' => 'Acción pendiente tuya',
    'waiting_partner' => 'Espera partner',
    'waiting_provider' => 'En proceso con proveedor',
    'completed' => 'Completado',
    'cancelled' => 'Cancelado',
];
$payLabels = [
    'awaiting_payment' => 'Esperando pago',
    'payment_review' => 'Comprobante en revisión',
    'paid' => 'Pagado',
];
$portalLabels = (new \App\Services\UksEletService())->studentPortalLabels($tracking, [
    'registro' => 'Registro',
    'confirm_pago' => 'Confirmación de pago',
    'solicitud_uks' => 'Solicitud a UKS',
    'codigos' => 'Accesos al examen',
    'resultados' => 'Resultados',
    'fin' => 'Completado',
], $statusLabels);
?>
<p class="meta"><a href="<?= e(url('/alumno')) ?>">← Mi panel</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)"><?= e($tracking['product_name']) ?></h1>
<p>
    Matrícula <strong><?= e($tracking['matricula']) ?></strong>
    · <span class="pill"><?= e($portalLabels['status']) ?></span>
</p>
<p class="muted">
    Pago: <?= e($payLabels[$tracking['purchase_status']] ?? $tracking['purchase_status']) ?>
    · <?= money($tracking['charged_amount']) ?>
    · <a href="<?= e(url('/compra/' . $tracking['matricula'])) ?>">Ver ficha de pago</a>
</p>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Progreso</h2>
    <?php if ($steps === []): ?>
        <p class="muted">Paso actual: <?= e($current !== '' ? $current : '—') ?></p>
    <?php else: ?>
        <ol style="margin:0;padding-left:1.2rem">
            <?php foreach ($steps as $s): ?>
                <?php
                $codes = array_column($steps, 'code');
                $curIdx = array_search($current, $codes, true);
                $thisIdx = array_search((string) $s['code'], $codes, true);
                $done = is_int($curIdx) && is_int($thisIdx) && $thisIdx < $curIdx;
                $active = (string) $s['code'] === $current;
                ?>
                <li style="<?= $active ? 'font-weight:700;color:var(--doceo-blue)' : ($done ? 'opacity:.65' : '') ?>">
                    <?= e($s['label']) ?><?php if ($active): ?> ← aquí vas<?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>

<?php if (!empty($tracking['exam_date'])): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Fecha de examen</h2>
    <p style="margin-top:0">
        <strong><?= e((string) $tracking['exam_date']) ?></strong>
        <?php if (!empty($tracking['exam_time'])): ?>
            a las <?= e(substr((string) $tracking['exam_time'], 0, 5)) ?>
        <?php endif; ?>
    </p>
    <?php if (!empty($tracking['exam_date_2'])): ?>
        <p class="muted">
            Reagenda:
            <?= e((string) $tracking['exam_date_2']) ?>
            <?php if (!empty($tracking['exam_time_2'])): ?>
                <?= e(substr((string) $tracking['exam_time_2'], 0, 5)) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if (!empty($tracking['zoom_url'])): ?>
        <p><a class="btn btn-accent" href="<?= e((string) $tracking['zoom_url']) ?>" target="_blank" rel="noopener">Abrir acceso Zoom</a></p>
    <?php elseif (empty($tracking['folio']) && empty($tracking['access_key'])): ?>
        <p class="muted">Los accesos al examen (folio y clave del día) se publicarán aquí cuando UKS confirme tu registro.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($tracking['folio']) && !empty($tracking['access_key'])): ?>
<div class="panel" style="margin-top:1rem;border:2px solid var(--doceo-yellow)">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Acceso al examen ELeT</h2>
    <p style="margin-top:0">
        Entra en:
        <a class="btn btn-accent btn-sm" href="<?= e($eletExamUrl ?? 'https://exam.elet.com.mx/') ?>" target="_blank" rel="noopener">exam.elet.com.mx</a>
    </p>
    <ul style="margin:.5rem 0;padding-left:1.1rem">
        <li>Folio (único): <strong style="font-family:ui-monospace,monospace"><?= e((string) $tracking['folio']) ?></strong></li>
        <li>Clave del día: <strong style="font-family:ui-monospace,monospace"><?= e((string) $tracking['access_key']) ?></strong></li>
    </ul>
    <p class="muted" style="font-size:.85rem;margin:0">Presenta el examen en la fecha y hora programadas arriba.</p>
</div>
<?php elseif ((string) ($tracking['purchase_status'] ?? '') === 'paid' && ($tracking['pipeline_code'] ?? '') === 'elet_uks'): ?>
<div class="panel" style="margin-top:1rem;background:#f4f7fb">
    <p class="muted" style="margin:0">Tu pago está confirmado. Estamos coordinando con UKS tu registro; recibirás folio y clave por correo y aquí.</p>
</div>
<?php endif; ?>

<?php if (!empty($tracking['moodle_username'])): ?>
<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Acceso Campus DOCEO</h2>
    <p>
        Entra a <a href="https://campus.institutodoceo.com" target="_blank" rel="noopener">campus.institutodoceo.com</a>
    </p>
    <p>
        Usuario: <strong><?= e($tracking['moodle_username']) ?></strong>
        <?php if (!empty($tracking['moodle_password'])): ?>
            <br>Contraseña temporal: <strong><?= e($tracking['moodle_password']) ?></strong>
        <?php endif; ?>
    </p>
    <?php if (!empty($tracking['moodle_access_ends_at'])): ?>
        <p class="muted" style="font-size:.85rem">Vigencia hasta <?= e($tracking['moodle_access_ends_at']) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require BASE_PATH . '/views/shared/uks_report.php'; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Documentos del registro</h2>
    <?php
    /** @var list<array<string,mixed>> $registrationDocs */
    $registrationDocs = $registrationDocs ?? [];
    $statusDoc = [
        'pending' => 'En revisión',
        'approved' => 'Aprobado',
        'rejected' => 'Rechazado',
    ];
    ?>
    <?php if ($registrationDocs === []): ?>
        <p class="muted">Este producto no pide reglamento ni firma en el expediente.</p>
    <?php else: ?>
        <p class="muted" style="margin-top:0">Sube el reglamento firmado y tu firma. Administración los revisará.</p>
        <?php foreach ($registrationDocs as $req): ?>
            <?php
            $st = $req['status'] ?? null;
            $canUpload = $st === null || $st === 'rejected' || $st === 'pending';
            ?>
            <div style="padding:.75rem 0;border-bottom:1px solid #e6ebf2">
                <strong><?= e($req['label']) ?></strong>
                <?php if ($req['required']): ?> <span class="muted">*</span><?php endif; ?>
                <?php if ($st): ?>
                    · <span class="pill"><?= e($statusDoc[$st] ?? $st) ?></span>
                    <?php if (!empty($req['document_id']) && !empty($req['original_name'])): ?>
                        · <a href="<?= e(url('/alumno/documentos/' . $req['document_id'] . '/ver')) ?>" target="_blank" rel="noopener"><?= e($req['original_name']) ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    · <span class="pill">Pendiente de subir</span>
                <?php endif; ?>

                <?php if ($st === 'rejected'): ?>
                    <p class="muted" style="margin:.4rem 0">Motivo: <?= e((string) ($req['rejection_reason'] ?? '')) ?></p>
                <?php endif; ?>

                <?php if ($canUpload && $st !== 'approved'): ?>
                    <form method="post" action="<?= e(url('/alumno/caso/' . $tracking['id'] . '/documentos')) ?>" enctype="multipart/form-data" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.5rem">
                        <?= csrf_field() ?>
                        <input type="hidden" name="doc_type" value="<?= e($req['code']) ?>">
                        <input type="file" name="document" required accept="<?= e($req['accept']) ?>">
                        <button class="btn btn-accent btn-sm" type="submit">
                            <?= $st === null ? 'Subir' : ($st === 'rejected' ? 'Subir de nuevo' : 'Reemplazar') ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($documents !== []): ?>
        <h3 style="margin:1.25rem 0 .5rem;font-size:.95rem;color:var(--doceo-blue)">Otros archivos del caso</h3>
        <?php foreach ($documents as $d): ?>
            <?php
            $codes = array_column($registrationDocs, 'code');
            if (in_array((string) $d['doc_type'], $codes, true)) {
                continue;
            }
            ?>
            <div style="padding:.75rem 0;border-bottom:1px solid #e6ebf2">
                <strong><?= e($d['doc_type']) ?></strong>
                · <span class="pill"><?= e($statusDoc[$d['status']] ?? $d['status']) ?></span>
                · <a href="<?= e(url('/alumno/documentos/' . $d['id'] . '/ver')) ?>" target="_blank" rel="noopener"><?= e($d['original_name']) ?></a>
                <?php if ($d['status'] === 'rejected'): ?>
                    <p class="muted" style="margin:.4rem 0">Motivo: <?= e($d['rejection_reason'] ?? '') ?></p>
                    <form method="post" action="<?= e(url('/alumno/documentos/' . $d['id'] . '/reenviar')) ?>" enctype="multipart/form-data" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                        <?= csrf_field() ?>
                        <input type="file" name="document" required accept="<?= e($d['doc_type'] === 'ine' ? '.pdf' : '.pdf,.jpg,.jpeg,.png') ?>">
                        <button class="btn btn-accent btn-sm" type="submit">Subir de nuevo</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($logs !== []): ?>
    <div class="panel" style="margin-top:1rem">
        <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Actividad reciente</h2>
        <ul style="margin:0;padding-left:1.1rem">
            <?php foreach (array_slice($logs, 0, 8) as $l): ?>
                <li>
                    <?= e($l['note'] ?: $l['step_code']) ?>
                    <span class="muted" style="font-size:.82rem">· <?= e($l['created_at']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

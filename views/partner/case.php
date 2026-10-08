<?php
/** @var array<string,mixed> $tracking */
/** @var list<array<string,mixed>> $steps */
/** @var list<array<string,mixed>> $mailLogs */
/** @var array<string,mixed> $resultsState */
$current = (string) ($tracking['current_step_code'] ?? '');
$mailLogs = is_array($mailLogs ?? null) ? $mailLogs : [];
$resultsState = is_array($resultsState ?? null) ? $resultsState : [];
$resultsValues = is_array($resultsState['values'] ?? null) ? $resultsState['values'] : [];
$folio = trim((string) ($tracking['folio'] ?? ''));
$accessKey = trim((string) ($tracking['access_key'] ?? ''));
$zoom = trim((string) ($tracking['zoom_url'] ?? ''));
$hasCodes = $folio !== '' || $accessKey !== '' || $zoom !== '';
$level = trim((string) ($tracking['results_level'] ?? ($resultsValues['results_level'] ?? '')));
$score = $tracking['results_score'] ?? ($resultsValues['results_score'] ?? null);
$resultsUrl = trim((string) ($tracking['results_url'] ?? ($resultsValues['results_url'] ?? '')));
$pdfLinks = [];
foreach ($resultsValues as $code => $raw) {
    if (!is_array($raw)) {
        continue;
    }
    $path = trim((string) ($raw['path'] ?? ''));
    if ($path === '') {
        continue;
    }
    $pdfLinks[] = [
        'code' => (string) $code,
        'name' => trim((string) ($raw['name'] ?? '')) ?: ((string) $code . '.pdf'),
    ];
}
?>
<p class="meta"><a href="<?= e(url('/partner/alumnos')) ?>">← Alumnos</a></p>
<h1 style="margin:.2rem 0;color:var(--doceo-blue)" data-tour="case-title"><?= e((string) $tracking['product_name']) ?></h1>
<p>
    Matrícula <strong><?= e((string) $tracking['matricula']) ?></strong>
    · <span class="pill"><?= e((string) $tracking['status']) ?></span>
    · pago <?= e((string) $tracking['purchase_status']) ?> · <?= money($tracking['charged_amount']) ?>
</p>
<?php if (in_array($tracking['purchase_status'] ?? '', ['awaiting_payment', 'payment_review'], true)): ?>
    <p class="flash flash-info" style="margin-top:.75rem">
        El comprobante está en revisión. El caso avanzará cuando administración confirme el pago.
    </p>
<?php endif; ?>
<p class="muted">
    <?= e(trim(($tracking['first_name'] ?? '') . ' ' . ($tracking['last_name_p'] ?? ''))) ?>
    · <?= e((string) $tracking['student_email']) ?>
    <?php if (!empty($tracking['student_phone'])): ?> · <?= e((string) $tracking['student_phone']) ?><?php endif; ?>
</p>

<div class="panel" style="margin-top:1rem" data-tour="case-registration">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Datos de registro del alumno</h2>
    <?php if (!empty($canEditRegistration)): ?>
        <p class="muted" style="font-size:.85rem;margin-top:0">
            Corrige nombre u otros datos si el alumno se equivocó al registrarse.
            Solo se permite <strong>antes de asignar los códigos de acceso</strong>.
        </p>
    <?php endif; ?>
    <?php
    $canEditRegistration = !empty($canEditRegistration);
    $formAction = url('/partner/caso/' . (int) $tracking['id'] . '/datos');
    /** @var list<array<string,mixed>>|null $registrationFields */
    $registrationFields = $registrationFields ?? null;
    require BASE_PATH . '/views/partials/registration_edit_form.php';
    ?>
</div>

<div class="panel" style="margin-top:1rem<?= $hasCodes ? ';border:2px solid var(--doceo-yellow)' : '' ?>" data-tour="case-codes">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Códigos de acceso</h2>
    <?php if ($hasCodes): ?>
        <ul style="margin:.35rem 0;padding-left:1.1rem">
            <?php if ($folio !== ''): ?>
                <li>Folio: <strong style="font-family:ui-monospace,monospace"><?= e($folio) ?></strong></li>
            <?php endif; ?>
            <?php if ($accessKey !== ''): ?>
                <li>Clave: <strong style="font-family:ui-monospace,monospace"><?= e($accessKey) ?></strong></li>
            <?php endif; ?>
            <?php if ($zoom !== ''): ?>
                <li>Extra / Zoom:
                    <?php if (preg_match('#^https?://#i', $zoom)): ?>
                        <a href="<?= e($zoom) ?>" target="_blank" rel="noopener"><?= e($zoom) ?></a>
                    <?php else: ?>
                        <strong><?= e($zoom) ?></strong>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        </ul>
        <p class="muted" style="font-size:.82rem;margin:0">Estos datos también se envían al alumno por correo cuando corresponde.</p>
    <?php else: ?>
        <p class="muted" style="margin:0">Aún no hay folio, clave ni dato extra asignados.</p>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem" data-tour="case-mails">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Correos al alumno</h2>
    <p class="muted" style="margin-top:0;font-size:.82rem">
        Solo se muestran correos enviados al alumno de este caso (sin publicidad ni correos a proveedor).
    </p>
    <?php if ($mailLogs === []): ?>
        <p class="muted" style="margin:0">Aún no hay correos registrados para este alumno.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Plantilla</th>
                    <th>Asunto</th>
                    <th>Destinatario</th>
                    <th>Fecha</th>
                    <th>Estatus</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($mailLogs as $m): ?>
                    <?php
                    $highlight = '';
                    if (!empty($m['is_access'])) {
                        $highlight = 'background:#fffbeb';
                    } elseif (!empty($m['is_results'])) {
                        $highlight = 'background:#eff6ff';
                    }
                    ?>
                    <tr style="<?= e($highlight) ?>">
                        <td>
                            <?= e((string) ($m['template_name'] ?? $m['template_code'] ?? '—')) ?>
                            <?php if (!empty($m['is_access'])): ?>
                                <span class="pill" style="margin-left:.25rem;background:#fef3c7;color:#92400e">Accesos</span>
                            <?php elseif (!empty($m['is_results'])): ?>
                                <span class="pill" style="margin-left:.25rem;background:#dbeafe;color:#1e40af">Resultados</span>
                            <?php endif; ?>
                            <?php if (!empty($m['template_code'])): ?>
                                <br><span class="muted" style="font-size:.75rem"><?= e((string) $m['template_code']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($m['subject'] ?? '')) ?></td>
                        <td><?= e((string) ($m['to_email'] ?? '')) ?></td>
                        <td><?= e((string) ($m['created_at'] ?? '')) ?></td>
                        <td>
                            <?php if (($m['status'] ?? '') === 'sent'): ?>
                                <span class="pill" style="background:#dcfce7;color:#166534">Enviado</span>
                            <?php else: ?>
                                <span class="pill" style="background:#fecaca;color:#991b1b">Fallido</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem" data-tour="case-results">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Resultados / certificados</h2>
    <?php if ($level === '' && ($score === null || $score === '') && $resultsUrl === '' && $pdfLinks === [] && empty($tracking['cenni_folio'])): ?>
        <p class="muted" style="margin:0">Aún no hay resultados ni certificados publicados.</p>
    <?php else: ?>
        <ul style="margin:.35rem 0;padding-left:1.1rem">
            <?php if ($level !== ''): ?>
                <li>Nivel: <strong><?= e($level) ?></strong></li>
            <?php endif; ?>
            <?php if ($score !== null && $score !== ''): ?>
                <li>Puntaje: <strong><?= e((string) $score) ?></strong></li>
            <?php endif; ?>
            <?php if (!empty($tracking['cenni_folio'])): ?>
                <li>Folio CENNI: <strong><?= e((string) $tracking['cenni_folio']) ?></strong></li>
            <?php endif; ?>
        </ul>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
            <?php if ($resultsUrl !== ''): ?>
                <a class="btn btn-accent btn-sm" href="<?= e($resultsUrl) ?>" target="_blank" rel="noopener">Ver certificado / resultados</a>
            <?php endif; ?>
            <?php foreach ($pdfLinks as $pdf): ?>
                <a class="btn btn-primary btn-sm"
                   href="<?= e(url('/partner/caso/' . (int) $tracking['id'] . '/resultados-pdf?field=' . urlencode($pdf['code']))) ?>"
                   target="_blank" rel="noopener">
                    Descargar <?= e($pdf['name']) ?>
                </a>
            <?php endforeach; ?>
            <?php if ($pdfLinks === [] && $resultsUrl === '' && ($level !== '' || ($score !== null && $score !== ''))): ?>
                <a class="btn btn-ghost btn-sm"
                   href="<?= e(url('/partner/caso/' . (int) $tracking['id'] . '/resultados-pdf')) ?>"
                   target="_blank" rel="noopener">Descargar PDF</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($steps !== []): ?>
<div class="panel" style="margin-top:1rem" data-tour="case-progress">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Progreso</h2>
    <?php
    $codes = array_column($steps, 'code');
    $curIdx = array_search($current, $codes, true);
    $currentIsHidden = $current !== '' && $curIdx === false;
    ?>
    <ol style="margin:0;padding-left:1.2rem">
        <?php foreach ($steps as $i => $s): ?>
            <?php $active = is_int($curIdx) && $i === $curIdx; ?>
            <li style="<?= $active ? 'font-weight:700;color:var(--doceo-blue)' : '' ?>">
                <?= e((string) $s['label']) ?><?= $active ? ' ← actual' : '' ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if ($currentIsHidden): ?>
        <p class="muted" style="margin:.5rem 0 0;font-size:.85rem">El caso está en un paso interno de DOCEO.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1rem">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Fecha de examen</h2>
    <?php if (in_array((string) ($tracking['product_type'] ?? ''), ['certification', 'procedure'], true) || !empty($tracking['exam_date'])): ?>
        <?php if (!empty($tracking['exam_date'])): ?>
            <p style="margin-top:0">
                <strong><?= e((string) $tracking['exam_date']) ?></strong>
                <?php if (!empty($tracking['exam_time'])): ?>
                    a las <?= e(substr((string) $tracking['exam_time'], 0, 5)) ?>
                <?php endif; ?>
            </p>
            <?php if (!empty($tracking['exam_date_2'])): ?>
                <p class="muted" style="font-size:.85rem;margin-top:0">
                    Reagenda (admin): <?= e((string) $tracking['exam_date_2']) ?>
                    <?php if (!empty($tracking['exam_time_2'])): ?>
                        <?= e(substr((string) $tracking['exam_time_2'], 0, 5)) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted" style="margin-top:0">Aún no hay fecha asignada.</p>
        <?php endif; ?>
        <p class="muted" style="font-size:.85rem;margin:.75rem 0 0">
            Para asignar o reagendar la fecha, contacta a DOCEO. Solo administración puede cambiarla.
        </p>
    <?php else: ?>
        <p class="muted" style="margin:0">Este producto no requiere fecha de examen.</p>
    <?php endif; ?>
</div>

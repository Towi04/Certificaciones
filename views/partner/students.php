<?php
/** @var array<string,mixed>|null $partner */
/** @var list<array<string,mixed>> $trackings */
/** @var array<int,array<string,mixed>> $accessMails */
$accessMails = is_array($accessMails ?? null) ? $accessMails : [];
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center" data-tour="students-header">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Alumnos</h1>
        <p class="muted" style="margin:.25rem 0 0">
            <?= e($partner['display_name'] ?? 'Partner') ?>
            · código <strong><?= e((string) ($partner['code'] ?? '')) ?></strong>
            · crédito <?= money($partner['credit_balance'] ?? 0) ?>
        </p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center">
        <a class="btn btn-accent" href="<?= e(url('/partner/registrar')) ?>" data-tour="register-cta">Registrar alumno</a>
        <a class="btn btn-ghost" href="<?= e(url('/partner/avance')) ?>">Ver avance</a>
    </div>
</div>

<div class="panel" style="margin-top:1rem" data-tour="students-table">
    <h2 style="margin-top:0">Cartera de alumnos</h2>
    <?php if ($trackings === []): ?>
        <div class="empty">
            Aún no hay alumnos.
            <a href="<?= e(url('/partner/registrar')) ?>">Elige un producto del catálogo</a>
            y completa el registro.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Matrícula</th>
                    <th>Alumno</th>
                    <th>Producto</th>
                    <th>Examen</th>
                    <th>Estatus</th>
                    <th>Folio / clave</th>
                    <th>Último correo accesos</th>
                    <th>Resultados</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($trackings as $t): ?>
                    <?php
                    $tid = (int) ($t['id'] ?? 0);
                    $folio = trim((string) ($t['folio'] ?? ''));
                    $key = trim((string) ($t['access_key'] ?? ''));
                    $level = trim((string) ($t['results_level'] ?? ''));
                    $score = $t['results_score'] ?? null;
                    $mail = $accessMails[$tid] ?? null;
                    ?>
                    <tr>
                        <td><?= e((string) ($t['matricula'] ?? '')) ?></td>
                        <td>
                            <?= e(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name_p'] ?? ''))) ?>
                            <br><span class="muted"><?= e((string) ($t['email'] ?? '')) ?></span>
                        </td>
                        <td><?= e((string) ($t['product_name'] ?? '')) ?></td>
                        <td>
                            <?= e((string) ($t['exam_date'] ?? '—')) ?>
                            <?php if (!empty($t['exam_time'])): ?>
                                <?= e(substr((string) $t['exam_time'], 0, 5)) ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="pill"><?= e((string) ($t['status'] ?? '')) ?></span></td>
                        <td style="font-family:ui-monospace,monospace;font-size:.82rem">
                            <?php if ($folio !== '' || $key !== ''): ?>
                                <?= e($folio !== '' ? $folio : '—') ?>
                                <?php if ($key !== ''): ?><br><?= e($key) ?><?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem">
                            <?php if (is_array($mail)): ?>
                                <?= e((string) ($mail['template_name'] ?? $mail['template_code'] ?? 'Accesos')) ?>
                                <br><span class="muted"><?= e((string) ($mail['created_at'] ?? '')) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem">
                            <?php if ($level !== '' || ($score !== null && $score !== '')): ?>
                                <?= $level !== '' ? e($level) : '' ?>
                                <?php if ($score !== null && $score !== ''): ?>
                                    <?= $level !== '' ? ' · ' : '' ?><?= e((string) $score) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?= e(url('/partner/caso/' . $tid)) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

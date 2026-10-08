<?php
/** @var array<string,mixed>|null $partner */
/** @var list<array<string,mixed>> $trackings */
/** @var array<int,array<string,mixed>> $accessMails */
/** @var array<string,array{status:string,label:string,count:int,amount:float}> $paymentSummary */
/** @var array{q:string,status:string,exam:string,producto:int,pago:string} $filters */
/** @var list<string> $statusOptions */
/** @var list<array{id:int,name:string}> $productOptions */
$accessMails = is_array($accessMails ?? null) ? $accessMails : [];
$paymentSummary = is_array($paymentSummary ?? null) ? $paymentSummary : [];
$filters = is_array($filters ?? null) ? $filters : ['q' => '', 'status' => 'all', 'exam' => 'all', 'producto' => 0, 'pago' => 'all'];
$statusOptions = is_array($statusOptions ?? null) ? $statusOptions : [];
$productOptions = is_array($productOptions ?? null) ? $productOptions : [];
$inputStyle = 'padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px;min-width:0';

$exportQs = http_build_query(array_filter([
    'q' => ($filters['q'] ?? '') !== '' ? $filters['q'] : null,
    'status' => (($filters['status'] ?? 'all') !== 'all') ? $filters['status'] : null,
    'exam' => (($filters['exam'] ?? 'all') !== 'all') ? $filters['exam'] : null,
    'producto' => ((int) ($filters['producto'] ?? 0) > 0) ? (int) $filters['producto'] : null,
    'pago' => (($filters['pago'] ?? 'all') !== 'all') ? $filters['pago'] : null,
], static fn ($v) => $v !== null && $v !== ''));

$paymentCards = [
    'awaiting_payment' => $paymentSummary['awaiting_payment'] ?? ['count' => 0, 'amount' => 0.0, 'label' => 'Por pagar'],
    'payment_review' => $paymentSummary['payment_review'] ?? ['count' => 0, 'amount' => 0.0, 'label' => 'En revisión'],
    'paid' => $paymentSummary['paid'] ?? ['count' => 0, 'amount' => 0.0, 'label' => 'Pagados'],
];
$activePago = (string) ($filters['pago'] ?? 'all');
?>
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center" data-tour="students-header">
    <div>
        <h1 style="margin:0;color:var(--doceo-blue)">Alumnos</h1>
        <p class="muted" style="margin:.25rem 0 0">
            <?= e($partner['display_name'] ?? 'Partner') ?>
            · código <strong><?= e((string) ($partner['code'] ?? '')) ?></strong>
            · crédito
            <a href="<?= e(url('/partner/credito')) ?>" style="font-weight:700">
                <?= money($partner['credit_balance'] ?? 0) ?>
            </a>
        </p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center">
        <a class="btn btn-accent" href="<?= e(url('/partner/registrar')) ?>" data-tour="register-cta">Registrar alumno</a>
        <a class="btn btn-ghost" href="<?= e(url('/partner/alumnos/export.csv' . ($exportQs !== '' ? '?' . $exportQs : ''))) ?>"
           data-tour="students-export">Exportar CSV</a>
        <a class="btn btn-ghost" href="<?= e(url('/partner/avance')) ?>">Ver avance</a>
    </div>
</div>

<div class="stats" style="margin:1rem 0" data-tour="students-payments">
    <?php foreach ($paymentCards as $status => $card): ?>
        <?php
        $qs = http_build_query(array_filter([
            'q' => ($filters['q'] ?? '') !== '' ? $filters['q'] : null,
            'status' => (($filters['status'] ?? 'all') !== 'all') ? $filters['status'] : null,
            'exam' => (($filters['exam'] ?? 'all') !== 'all') ? $filters['exam'] : null,
            'producto' => ((int) ($filters['producto'] ?? 0) > 0) ? (int) $filters['producto'] : null,
            'pago' => $activePago === $status ? null : $status,
        ], static fn ($v) => $v !== null && $v !== ''));
        $href = url('/partner/alumnos' . ($qs !== '' ? '?' . $qs : ''));
        $isActive = $activePago === $status;
        ?>
        <a class="stat" href="<?= e($href) ?>"
           style="text-decoration:none;color:inherit;<?= $isActive ? 'outline:2px solid var(--doceo-blue);border-radius:14px;' : '' ?>">
            <div class="label"><?= e((string) ($card['label'] ?? $status)) ?><?= $isActive ? ' · filtro' : '' ?></div>
            <div class="value" style="font-size:1.15rem"><?= (int) ($card['count'] ?? 0) ?></div>
            <div class="muted" style="font-size:.82rem;margin-top:.2rem"><?= money($card['amount'] ?? 0) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= e(url('/partner/alumnos')) ?>" class="panel" style="margin-top:1rem" data-tour="students-filters">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Buscar y filtrar</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.65rem;align-items:end">
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600;grid-column:span 2">
            Buscar
            <input type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"
                   placeholder="Matrícula, alumno, correo, folio, producto…"
                   style="<?= e($inputStyle) ?>">
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
            Estatus
            <select name="status" style="<?= e($inputStyle) ?>">
                <option value="all" <?= (($filters['status'] ?? 'all') === 'all') ? 'selected' : '' ?>>Todos</option>
                <?php foreach ($statusOptions as $st): ?>
                    <option value="<?= e($st) ?>" <?= (($filters['status'] ?? '') === $st) ? 'selected' : '' ?>>
                        <?= e($st) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
            Pago
            <select name="pago" style="<?= e($inputStyle) ?>">
                <?php
                $pagoOpts = [
                    'all' => 'Todos',
                    'awaiting_payment' => 'Por pagar',
                    'payment_review' => 'En revisión',
                    'paid' => 'Pagados',
                    'cancelled' => 'Cancelados',
                ];
                foreach ($pagoOpts as $val => $label):
                    ?>
                    <option value="<?= e($val) ?>" <?= $activePago === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
            Producto
            <select name="producto" style="<?= e($inputStyle) ?>">
                <option value="0" <?= ((int) ($filters['producto'] ?? 0) === 0) ? 'selected' : '' ?>>Todos</option>
                <?php foreach ($productOptions as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>"
                        <?= ((int) ($filters['producto'] ?? 0) === (int) $opt['id']) ? 'selected' : '' ?>>
                        <?= e((string) $opt['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="muted" style="display:flex;flex-direction:column;gap:.3rem;font-size:.82rem;font-weight:600">
            Examen
            <select name="exam" style="<?= e($inputStyle) ?>">
                <?php
                $exam = (string) ($filters['exam'] ?? 'all');
                $examOpts = [
                    'all' => 'Todos',
                    'upcoming' => 'Próximos',
                    'past' => 'Pasados',
                    'set' => 'Con fecha',
                    'none' => 'Sin fecha',
                ];
                foreach ($examOpts as $val => $label):
                    ?>
                    <option value="<?= e($val) ?>" <?= $exam === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div style="display:flex;gap:.45rem;flex-wrap:wrap">
            <button class="btn btn-primary" type="submit">Buscar</button>
            <a class="btn btn-ghost" href="<?= e(url('/partner/alumnos')) ?>">Limpiar</a>
        </div>
    </div>
</form>

<div class="panel" style="margin-top:1rem" data-tour="students-table">
    <h2 style="margin-top:0">Cartera de alumnos</h2>
    <?php if ($trackings === []): ?>
        <div class="empty">
            <?php if (($filters['q'] ?? '') !== '' || ($filters['status'] ?? 'all') !== 'all' || (int) ($filters['producto'] ?? 0) > 0 || ($filters['exam'] ?? 'all') !== 'all' || ($filters['pago'] ?? 'all') !== 'all'): ?>
                No hay alumnos con esos filtros.
                <a href="<?= e(url('/partner/alumnos')) ?>">Ver todos</a>
            <?php else: ?>
                Aún no hay alumnos.
                <a href="<?= e(url('/partner/registrar')) ?>">Elige un producto del catálogo</a>
                y completa el registro.
            <?php endif; ?>
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
                    <th>Pago</th>
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
                    $pay = (string) ($t['purchase_status'] ?? '');
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
                        <td><span class="pill"><?= e($pay !== '' ? $pay : '—') ?></span></td>
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
        <?php require BASE_PATH . '/views/shared/pagination.php'; ?>
    <?php endif; ?>
</div>

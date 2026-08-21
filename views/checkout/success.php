<?php
/** @var array<string,mixed> $purchase */
/** @var list<array<string,mixed>> $items */
/** @var array{bank:string,clabe:string,holder:string,concept:string} $bank */
/** @var ?string $openpayPdf */
$statusLabels = [
    'awaiting_payment' => 'Esperando pago',
    'payment_review' => 'Comprobante en revisión',
    'paid' => 'Pagado',
    'awaiting_docs' => 'Faltan documentos',
    'draft' => 'Borrador',
    'cancelled' => 'Cancelado',
];
?>
<article class="panel" style="margin:1.25rem 0 2.5rem">
    <p class="meta">Compra registrada</p>
    <h1 style="margin:.2rem 0;color:var(--doceo-blue)">Matrícula <?= e($purchase['matricula']) ?></h1>
    <p class="muted">Estatus: <span class="pill"><?= e($statusLabels[$purchase['status']] ?? $purchase['status']) ?></span></p>

    <div class="price-box" style="margin:1rem 0;display:flex;gap:2rem;flex-wrap:wrap">
        <div>
            <div class="muted" style="font-size:.85rem">Monto</div>
            <div class="price" style="font-size:1.5rem"><?= money($purchase['charged_amount']) ?></div>
        </div>
        <div>
            <div class="muted" style="font-size:.85rem">Método</div>
            <div><strong><?= e($purchase['payment_method']) ?></strong></div>
        </div>
    </div>

    <?php if ($items !== []): ?>
        <h2 style="font-size:1.05rem;color:var(--doceo-blue)">Productos</h2>
        <ul>
            <?php foreach ($items as $it): ?>
                <li><?= e($it['product_name']) ?> — <?= money($it['unit_charged_price']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (in_array($purchase['status'], ['awaiting_payment', 'payment_review'], true)): ?>
        <hr style="border:0;border-top:1px solid #e6ebf2;margin:1.25rem 0">
        <h2 style="font-size:1.05rem;color:var(--doceo-blue)">Instrucciones de pago</h2>

        <?php if (!empty($purchase['openpay_clabe'])): ?>
            <p>Realiza una transferencia SPEI a esta CLABE única:</p>
            <p style="font-family:ui-monospace,monospace;font-size:1.15rem;letter-spacing:.04em">
                <?= e($purchase['openpay_clabe']) ?>
            </p>
            <p class="muted">Beneficiario: <?= e(\App\Config\Env::get('OPENPAY_BENEFICIARY_NAME', 'Instituto DOCEO')) ?></p>
            <?php if ($openpayPdf): ?>
                <p><a class="btn btn-primary btn-sm" href="<?= e($openpayPdf) ?>" target="_blank" rel="noopener">Descargar ficha SPEI</a></p>
            <?php endif; ?>
        <?php elseif ($purchase['payment_method'] === 'transfer_proof'): ?>
            <p>Recibimos tu comprobante. Lo revisaremos y te avisaremos por correo.</p>
            <?php if ($bank['clabe'] !== ''): ?>
                <p class="muted">Cuenta DOCEO de referencia: <?= e($bank['bank']) ?> · CLABE <?= e($bank['clabe']) ?> · <?= e($bank['holder']) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p>Transfiere el monto a la cuenta DOCEO e incluye tu matrícula en el concepto:</p>
            <?php if ($bank['clabe'] !== ''): ?>
                <ul>
                    <li>Banco: <strong><?= e($bank['bank']) ?></strong></li>
                    <li>CLABE: <strong style="font-family:ui-monospace,monospace"><?= e($bank['clabe']) ?></strong></li>
                    <li>Titular: <strong><?= e($bank['holder']) ?></strong></li>
                    <li>Concepto: <strong><?= e($bank['concept']) ?> <?= e($purchase['matricula']) ?></strong></li>
                </ul>
            <?php else: ?>
                <p class="muted">Configura <code>BANK_TRANSFER_CLABE</code> en el .env o pide los datos a administración.</p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem">
        <a class="btn btn-accent" href="<?= e(url('/alumno')) ?>">Ir a mi panel</a>
        <a class="btn btn-ghost" href="<?= e(url('/catalogo')) ?>">Seguir explorando</a>
    </div>
</article>

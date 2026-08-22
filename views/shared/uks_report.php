<?php
/** @var array<string,mixed>|null $uksReport */
/** @var array<string,mixed> $tracking */
if ($uksReport === null && empty($tracking['results_level']) && empty($tracking['cenni_folio'])) {
    return;
}
$cenni = is_array($uksReport['cenni'] ?? null) ? $uksReport['cenni'] : [];
$docs = is_array($cenni['docs'] ?? null) ? $cenni['docs'] : [];
$docLabels = [
    'solicitud' => 'Solicitud CENNI',
    'curp' => 'CURP',
    'ine' => 'Identificación oficial',
];
$statusClass = static fn (string $s): string => match ($s) {
    'approved' => 'color:#1b7f4a',
    'rejected' => 'color:#b00020',
    default => 'color:#666',
};
?>
<div class="panel" style="margin-top:1rem;border:2px solid #dbeafe">
    <h2 style="margin-top:0;font-size:1.05rem;color:var(--doceo-blue)">Resultados ELeT</h2>
    <?php if (!empty($uksReport['exam_completed_at']) || !empty($tracking['results_level'])): ?>
        <p style="margin-top:0">
            <?php if (!empty($uksReport['exam_completed_at'])): ?>
                Examen realizado: <strong><?= e((string) $uksReport['exam_completed_at']) ?></strong>
            <?php endif; ?>
            <?php if (!empty($tracking['results_level'])): ?>
                · Nivel: <strong><?= e((string) $tracking['results_level']) ?></strong>
            <?php endif; ?>
            <?php if ($tracking['results_score'] !== null && $tracking['results_score'] !== ''): ?>
                · Puntaje: <strong><?= e((string) $tracking['results_score']) ?></strong>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if (!empty($tracking['folio'])): ?>
        <p class="muted" style="margin-top:0">Folio UKS: <strong><?= e((string) $tracking['folio']) ?></strong></p>
    <?php endif; ?>
    <?php if (!empty($tracking['results_url'])): ?>
        <p><a class="btn btn-accent btn-sm" href="<?= e((string) $tracking['results_url']) ?>" target="_blank" rel="noopener">Ver certificado</a></p>
    <?php endif; ?>

    <?php if ($docs !== [] || !empty($cenni['documentacion']) || !empty($tracking['cenni_folio'])): ?>
        <hr style="border:0;border-top:1px solid #e6ebf2;margin:1rem 0">
        <h3 style="margin:0 0 .5rem;font-size:.98rem;color:var(--doceo-blue)">Trámite CENNI</h3>
        <p class="muted" style="font-size:.85rem;margin-top:0">
            UKS suele publicar el estatus de documentos 2–3 días después del examen.
            El folio CENNI suele estar disponible alrededor de los 15 días.
        </p>
        <?php if (!empty($cenni['documentacion'])): ?>
            <p>Documentación general: <strong><?= e((string) $cenni['documentacion']) ?></strong></p>
        <?php endif; ?>
        <?php if ($docs !== []): ?>
            <ul style="margin:.5rem 0;padding-left:1.2rem">
                <?php foreach ($docLabels as $key => $label): ?>
                    <?php if (!isset($docs[$key])) continue; ?>
                    <?php $st = (string) ($docs[$key]['status'] ?? 'pending'); ?>
                    <li style="<?= $statusClass($st) ?>">
                        <?= e($label) ?>: <strong><?= e((string) ($docs[$key]['label'] ?? $st)) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($tracking['cenni_folio'])): ?>
            <p>
                Folio CENNI: <strong><?= e((string) $tracking['cenni_folio']) ?></strong>
            </p>
            <p>
                <a class="btn btn-primary btn-sm" href="https://cennisistema.sep.gob.mx/cenni/consulta/consultaEstatus.jsp" target="_blank" rel="noopener">
                    Consultar estatus en SEP
                </a>
            </p>
        <?php else: ?>
            <p class="muted" style="font-size:.85rem">El folio CENNI aún no está publicado.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

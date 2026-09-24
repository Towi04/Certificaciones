<?php
/** @var int $year */
/** @var array<int, string> $codes */
/** @var array<int, string> $monthLabels */
/** @var int $currentMonth */
/** @var int $currentYear */
/** @var string $currentCode */
/** @var string $whatsapp */
?>
<h1 style="margin-top:0;color:var(--doceo-blue)">Códigos promocionales DOCEO</h1>
<p class="muted">
    Registra los 12 códigos del año una sola vez. Solo el código del mes en curso es válido:
    si alguien usa el de un mes anterior, el sistema avisará que ya venció; si usa uno futuro,
    que aún no está vigente. Al usarlo, el alumno paga el precio público DOCEO.
</p>

<div class="panel" style="margin-top:1rem;max-width:640px">
    <p style="margin-top:0">
        <strong>Código vigente ahora:</strong>
        <code style="font-size:1.1rem"><?= e($currentCode) ?></code>
        <span class="muted" style="font-size:.85rem">
            (<?= e($monthLabels[$currentMonth] ?? '') ?> <?= (int) $currentYear ?>)
        </span>
    </p>

    <form method="post" action="<?= e(url('/admin/promo')) ?>">
        <?= csrf_field() ?>

        <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:1rem">
            <a class="btn" href="<?= e(url('/admin/promo?year=' . ((int) $year - 1))) ?>">← <?= (int) $year - 1 ?></a>
            <strong style="min-width:4rem;text-align:center"><?= (int) $year ?></strong>
            <a class="btn" href="<?= e(url('/admin/promo?year=' . ((int) $year + 1))) ?>"><?= (int) $year + 1 ?> →</a>
            <?php if ((int) $year !== (int) $currentYear): ?>
                <a class="btn" href="<?= e(url('/admin/promo?year=' . (int) $currentYear)) ?>">Ir a <?= (int) $currentYear ?></a>
            <?php endif; ?>
        </div>

        <label>Año a guardar
            <input type="number" name="year" value="<?= (int) $year ?>" min="2020" max="2100" required
                style="font:inherit;padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:140px">
        </label>
        <p class="muted" style="font-size:.82rem;margin:.35rem 0 1rem">
            Navega de año con los botones o cambia el número y guarda. Los códigos deben ser distintos entre meses del mismo año.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1rem;margin-bottom:1rem">
            <?php foreach ($monthLabels as $m => $label): ?>
                <?php
                $isCurrent = ((int) $year === (int) $currentYear && (int) $m === (int) $currentMonth);
                $val = (string) ($codes[$m] ?? '');
                ?>
                <label style="<?= $isCurrent ? 'background:#fff8e6;border:1px solid #f0d78c;border-radius:10px;padding:.55rem .65rem' : '' ?>">
                    <?= e($label) ?><?= $isCurrent ? ' · <strong>mes actual</strong>' : '' ?>
                    <input type="text" name="codes[<?= (int) $m ?>]" value="<?= e($val) ?>"
                        pattern="[A-Za-z0-9_-]{0,40}" maxlength="40" placeholder="Ej. DOCEO<?= sprintf('%02d', $m) ?>"
                        style="text-transform:uppercase;font:inherit;padding:.5rem .65rem;border:1px solid #cfd8e6;border-radius:10px;width:100%;margin-top:.35rem;box-sizing:border-box">
                </label>
            <?php endforeach; ?>
        </div>

        <label>WhatsApp de asesores (con código de país)
            <input type="text" name="school_whatsapp" value="<?= e($whatsapp) ?>"
                placeholder="Ej. 5215512345678"
                style="font:inherit;padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%">
        </label>
        <p class="muted" style="font-size:.82rem;margin:.35rem 0 1rem">
            Se usa en el checkout de combos: enlace para que el alumno pida un código vigente.
            Solo dígitos; México suele iniciar con <code>521</code>.
        </p>

        <button class="btn btn-accent" type="submit">Guardar calendario</button>
    </form>
</div>

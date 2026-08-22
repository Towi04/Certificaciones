<?php
/** @var array<string,mixed> $product */
/** @var list<array{code:string,label:string,required:bool,type:string}> $fields */
/** @var list<array{code:string,label:string,required:bool,accept:string}> $docs */
/** @var array<string,string> $prefill */
/** @var array<string,mixed> $quote */
/** @var bool $openpayReady */
$step = 1;
$opts = $quote['payment_options'] ?? [];
$msiPlans = $opts['msi'] ?? $quote['msi_plans'] ?? [];
?>
<article class="panel checkout" style="margin:1.25rem 0 2.5rem">
    <p class="meta"><a href="<?= e(url('/producto/' . $product['slug'])) ?>">← <?= e($product['name']) ?></a></p>
    <h1 style="margin:.2rem 0 .4rem;color:var(--doceo-blue)">Adquirir</h1>
    <p class="muted" style="margin-top:0">
        Solo te pedimos lo necesario para este producto.
        <?php if ($docs === []): ?>
            Los documentos del proceso se solicitan después en tu panel, si aplica.
        <?php endif; ?>
    </p>

    <form method="post" action="<?= e(url('/adquirir/' . $product['slug'])) ?>" enctype="multipart/form-data" class="checkout-form" id="checkout-form">
        <?= csrf_field() ?>
        <input type="hidden" name="payment_method" id="payment_method" value="<?= $openpayReady ? 'openpay_spei' : 'transfer_proof' ?>">
        <input type="hidden" name="card_msi_months" id="card_msi_months" value="1">

        <section class="checkout-section">
            <h2><?= $step++ ?>. Tus datos</h2>
            <div class="form-grid">
                <?php foreach ($fields as $field): ?>
                    <?php
                    $code = $field['code'];
                    $val = $prefill[$code] ?? '';
                    $req = !empty($field['required']);
                    ?>
                    <?php if ($field['type'] === 'select' && $code === 'sex'): ?>
                        <label><?= e($field['label']) ?><?= $req ? ' *' : '' ?>
                            <select name="sex" <?= $req ? 'required' : '' ?>>
                                <option value="">—</option>
                                <option value="F">Femenino</option>
                                <option value="M">Masculino</option>
                                <option value="X">Otro / X</option>
                            </select>
                        </label>
                    <?php else: ?>
                        <label><?= e($field['label']) ?><?= $req ? ' *' : '' ?>
                            <input type="<?= e($field['type']) ?>" name="<?= e($code) ?>" value="<?= e((string) $val) ?>"
                                <?= $req ? 'required' : '' ?>
                                <?= $code === 'curp' ? 'maxlength="18" style="text-transform:uppercase"' : '' ?>
                                <?= $code === 'email' ? 'autocomplete="email"' : '' ?>
                                <?= $code === 'phone' ? 'autocomplete="tel"' : '' ?>>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="checkout-section">
            <h2><?= $step++ ?>. Precio y código</h2>
            <div class="price-box">
                <div>
                    <div class="muted" style="font-size:.85rem">Precio base</div>
                    <div class="price" id="price-base"><?= money($quote['base'] ?? $quote['catalog']) ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size:.85rem">Total a pagar</div>
                    <div class="price" id="price-total" style="font-size:1.6rem"><?= money($quote['charged']) ?></div>
                    <div class="muted" id="price-fee" style="font-size:.8rem"></div>
                    <div class="muted" id="price-label" style="font-size:.85rem"><?= e($quote['label']) ?></div>
                </div>
            </div>
            <label style="max-width:320px;display:block;margin-top:.75rem">Código promocional o de partner
                <input type="text" name="promo_code" id="promo_code" placeholder="Opcional" style="text-transform:uppercase">
            </label>
            <p class="muted" id="quote-error" style="color:#b00020;display:none"></p>
        </section>

        <?php if ($docs !== []): ?>
            <section class="checkout-section">
                <h2><?= $step++ ?>. Documentos</h2>
                <div class="form-grid">
                    <?php foreach ($docs as $doc): ?>
                        <label><?= e($doc['label']) ?><?= $doc['required'] ? ' *' : '' ?>
                            <input type="file" name="doc_<?= e($doc['code']) ?>" accept="<?= e($doc['accept']) ?>" <?= $doc['required'] ? 'required' : '' ?>>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="checkout-section">
            <h2><?= $step++ ?>. Pago</h2>
            <?php if ($openpayReady): ?>
                <p class="muted" style="margin-top:0;font-size:.85rem">Elige cómo pagar. Tarjeta se completa en la página segura de OpenPay — aquí no capturamos datos bancarios.</p>
                <div class="pay-tiles" role="group" aria-label="Método de pago">
                    <button type="button" class="pay-tile" data-method="openpay_card" data-ui="msi" aria-pressed="false">
                        <span class="pay-tile-icon" aria-hidden="true">💳</span>
                        <span class="pay-tile-label">MSI</span>
                        <span class="pay-tile-sub">Tarjeta</span>
                    </button>
                    <button type="button" class="pay-tile active" data-method="openpay_spei" data-ui="spei" aria-pressed="true">
                        <span class="pay-tile-icon" aria-hidden="true">🏦</span>
                        <span class="pay-tile-label">SPEI</span>
                        <span class="pay-tile-sub">Transferencia</span>
                    </button>
                    <button type="button" class="pay-tile" data-method="openpay_store" data-ui="oxxo" aria-pressed="false">
                        <span class="pay-tile-icon" aria-hidden="true">🏪</span>
                        <span class="pay-tile-label">OXXO</span>
                        <span class="pay-tile-sub">Efectivo</span>
                    </button>
                </div>

                <div id="msi-picker" class="msi-picker" hidden>
                    <p class="muted" style="font-size:.82rem;margin:.65rem 0 .4rem">Meses sin intereses (cargo total hoy; tu banco difiere el cobro):</p>
                    <div class="msi-chips" id="msi-chips">
                        <?php foreach ($msiPlans as $i => $plan): ?>
                            <button type="button" class="msi-chip<?= $i === 0 ? ' active' : '' ?>"
                                    data-months="<?= (int) $plan['months'] ?>"
                                    data-total="<?= e(number_format((float) $plan['total'], 2, '.', '')) ?>"
                                    data-fee="<?= e(number_format((float) ($plan['fee'] ?? 0), 2, '.', '')) ?>">
                                <?= (int) $plan['months'] === 1 ? 'Contado' : ((int) $plan['months'] . ' MSI') ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <p class="muted" id="pay-hint" style="font-size:.82rem;margin-top:.65rem"></p>
            <?php else: ?>
                <p class="muted">OpenPay no está configurado. Sube comprobante de transferencia a DOCEO.</p>
                <label>Comprobante *
                    <input type="file" name="payment_proof" required accept=".pdf,.jpg,.jpeg,.png">
                </label>
            <?php endif; ?>
        </section>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem">
            <button class="btn btn-accent" type="submit" id="checkout-submit"><?= $openpayReady ? 'Continuar' : 'Confirmar compra' ?></button>
            <a class="btn btn-ghost" href="<?= e(url('/producto/' . $product['slug'])) ?>">Cancelar</a>
        </div>
    </form>
</article>
<style>
.checkout-section { margin-top:1.4rem; padding-top:1rem; border-top:1px solid #e6ebf2; }
.checkout-section h2 { margin:0 0 .75rem; font-size:1.05rem; color:var(--doceo-blue); }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:.75rem 1rem; }
.form-grid label, .checkout-form label { display:flex; flex-direction:column; gap:.35rem; font-size:.88rem; font-weight:600; color:var(--doceo-muted); }
.form-grid input, .form-grid select { font:inherit; padding:.55rem .7rem; border:1px solid #cfd8e6; border-radius:10px; background:#fff; }
.price-box { display:flex; gap:2rem; flex-wrap:wrap; align-items:flex-end; }
.pay-tiles { display:flex; gap:.65rem; flex-wrap:wrap; }
.pay-tile {
  flex:1; min-width:88px; max-width:120px; display:flex; flex-direction:column; align-items:center; gap:.2rem;
  padding:.75rem .5rem; border:2px solid #d5deea; border-radius:14px; background:#fbfcfe; cursor:pointer;
  font:inherit; color:var(--doceo-text); transition:border-color .15s, background .15s;
}
.pay-tile:hover { border-color:#9cb4d8; }
.pay-tile.active { border-color:var(--doceo-blue); background:#eef4fc; }
.pay-tile-icon { font-size:1.45rem; line-height:1; }
.pay-tile-label { font-weight:700; font-size:.95rem; color:var(--doceo-blue); }
.pay-tile-sub { font-size:.72rem; color:var(--doceo-muted); font-weight:500; }
.msi-chips { display:flex; gap:.45rem; flex-wrap:wrap; }
.msi-chip {
  padding:.4rem .85rem; border:1px solid #cfd8e6; border-radius:999px; background:#fff;
  font:inherit; font-size:.82rem; font-weight:600; cursor:pointer; color:var(--doceo-muted);
}
.msi-chip.active { border-color:var(--doceo-blue); color:var(--doceo-blue); background:#eef4fc; }
</style>
<script>
(function () {
  const slug = <?= json_encode($product['slug'], JSON_UNESCAPED_UNICODE) ?>;
  const openpayReady = <?= $openpayReady ? 'true' : 'false' ?>;
  let quoteData = <?= json_encode($quote, JSON_UNESCAPED_UNICODE) ?>;
  let payUi = 'spei';

  const codeInput = document.getElementById('promo_code');
  const baseEl = document.getElementById('price-base');
  const totalEl = document.getElementById('price-total');
  const feeEl = document.getElementById('price-fee');
  const labelEl = document.getElementById('price-label');
  const errEl = document.getElementById('quote-error');
  const methodInput = document.getElementById('payment_method');
  const msiInput = document.getElementById('card_msi_months');
  const msiPicker = document.getElementById('msi-picker');
  const msiChips = document.getElementById('msi-chips');
  const payHint = document.getElementById('pay-hint');
  const submitBtn = document.getElementById('checkout-submit');
  let timer = null;

  function money(n) {
    return '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function activeMsiMonths() {
    const chip = msiChips && msiChips.querySelector('.msi-chip.active');
    return chip ? Number(chip.getAttribute('data-months') || 1) : 1;
  }

  function updatePriceDisplay() {
    if (!quoteData) return;
    const base = Number(quoteData.base || quoteData.charged_base || quoteData.catalog || 0);
    baseEl.textContent = money(base);

    let total = base;
    let fee = 0;
    const opts = quoteData.payment_options || {};

    if (payUi === 'msi') {
      const months = activeMsiMonths();
      const plans = opts.msi || quoteData.msi_plans || [];
      const plan = plans.find(p => Number(p.months) === months) || plans[0];
      if (plan) {
        total = Number(plan.total || 0);
        fee = Number(plan.fee || 0);
      }
    } else if (payUi === 'spei' && opts.spei) {
      total = Number(opts.spei.gross || base);
      fee = Number(opts.spei.fee || 0);
    } else if (payUi === 'oxxo' && opts.oxxo) {
      total = Number(opts.oxxo.gross || base);
      fee = Number(opts.oxxo.fee || 0);
    }

    totalEl.textContent = money(total);
    feeEl.textContent = fee > 0 ? ('Incluye comisión pasarela ' + money(fee)) : '';
  }

  function updateHint() {
    if (!payHint) return;
    if (payUi === 'msi') {
      const m = activeMsiMonths();
      payHint.textContent = m > 1
        ? 'Al continuar irás a OpenPay para pagar con tarjeta en ' + m + ' MSI.'
        : 'Al continuar irás a OpenPay para pagar con tarjeta de contado.';
      if (submitBtn) submitBtn.textContent = 'Continuar al pago seguro';
    } else if (payUi === 'spei') {
      payHint.textContent = 'Al confirmar te damos una CLABE SPEI única por el total.';
      if (submitBtn) submitBtn.textContent = 'Confirmar y ver CLABE';
    } else if (payUi === 'oxxo') {
      payHint.textContent = 'Al confirmar te damos referencia y código de barras para pagar en tienda.';
      if (submitBtn) submitBtn.textContent = 'Confirmar y ver referencia OXXO';
    }
  }

  function selectPayUi(ui, method) {
    payUi = ui;
    if (methodInput) methodInput.value = method;
    document.querySelectorAll('.pay-tile').forEach(t => {
      const on = t.getAttribute('data-ui') === ui;
      t.classList.toggle('active', on);
      t.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (msiPicker) msiPicker.hidden = ui !== 'msi';
    if (ui === 'msi' && msiChips) {
      const chip = msiChips.querySelector('.msi-chip.active') || msiChips.querySelector('.msi-chip');
      if (chip) msiInput.value = chip.getAttribute('data-months') || '1';
    } else {
      msiInput.value = '1';
    }
    updatePriceDisplay();
    updateHint();
  }

  document.querySelectorAll('.pay-tile').forEach(btn => {
    btn.addEventListener('click', () => selectPayUi(btn.getAttribute('data-ui'), btn.getAttribute('data-method')));
  });

  if (msiChips) {
    msiChips.addEventListener('click', e => {
      const chip = e.target.closest('.msi-chip');
      if (!chip) return;
      msiChips.querySelectorAll('.msi-chip').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      msiInput.value = chip.getAttribute('data-months') || '1';
      updatePriceDisplay();
      updateHint();
    });
  }

  function renderMsiChips(plans) {
    if (!msiChips) return;
    const list = Array.isArray(plans) && plans.length ? plans : [{months:1,total:0,fee:0}];
    msiChips.innerHTML = list.map((p, i) => {
      const m = Number(p.months || 1);
      return '<button type="button" class="msi-chip' + (i === 0 ? ' active' : '') + '" data-months="' + m + '">'
        + (m === 1 ? 'Contado' : (m + ' MSI')) + '</button>';
    }).join('');
  }

  function refreshQuote() {
    const code = (codeInput.value || '').trim();
    fetch(<?= json_encode(url('/api/cotizar/')) ?> + encodeURIComponent(slug) + '?code=' + encodeURIComponent(code))
      .then(r => r.json())
      .then(data => {
        if (!data.ok) {
          errEl.style.display = 'block';
          errEl.textContent = data.error || 'Código inválido';
          return;
        }
        errEl.style.display = 'none';
        quoteData = data.quote;
        labelEl.textContent = data.quote.label || '';
        renderMsiChips(data.quote.payment_options?.msi || data.quote.msi_plans || []);
        updatePriceDisplay();
        updateHint();
      })
      .catch(() => {});
  }

  codeInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refreshQuote, 400); });

  if (openpayReady) {
    updatePriceDisplay();
    updateHint();
  }
})();
</script>

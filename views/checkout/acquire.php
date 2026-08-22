<?php
/** @var array<string,mixed> $product */
/** @var list<array{code:string,label:string,required:bool,type:string}> $fields */
/** @var list<array{code:string,label:string,required:bool,accept:string}> $docs */
/** @var array{template_url:string,doc_code:string,required_before_checkout:bool}|null $reglamento */
/** @var array<string,string> $prefill */
/** @var array<string,mixed> $quote */
/** @var bool $openpayReady */
/** @var array{bank:string,clabe:string,holder:string,concept:string} $bank */
$step = 1;
$catalogPrice = (float) ($quote['catalog'] ?? $product['catalog_price'] ?? 0);
$basePrice = (float) ($quote['base'] ?? $catalogPrice);
?>
<article class="panel checkout" style="margin:1.25rem 0 2.5rem">
    <p class="meta"><a href="<?= e(url('/producto/' . $product['slug'])) ?>">← <?= e($product['name']) ?></a></p>
    <h1 style="margin:.2rem 0 .4rem;color:var(--doceo-blue)">Adquirir</h1>
    <p class="muted" style="margin-top:0">
        <?php if (!empty($reglamento)): ?>
            Lee y firma el reglamento, luego completa tus datos y elige cómo pagar.
        <?php else: ?>
            Solo te pedimos lo necesario para este producto.
        <?php endif; ?>
    </p>

    <form method="post" action="<?= e(url('/adquirir/' . $product['slug'])) ?>" enctype="multipart/form-data" class="checkout-form" id="checkout-form">
        <?= csrf_field() ?>
        <input type="hidden" name="payment_method" id="payment_method" value="transfer_proof">
        <input type="hidden" name="card_msi_months" id="card_msi_months" value="3">

        <?php if (!empty($reglamento)): ?>
            <?php require BASE_PATH . '/views/checkout/_reglamento_signature.php'; ?>
            <?php $step++; ?>
        <?php endif; ?>

        <section class="checkout-section">
            <h2><?= $step++ ?>. Código promocional</h2>
            <p class="muted" style="margin-top:0;font-size:.88rem">
                ¿Tienes un código DOCEO? Ingrésalo para obtener el precio preferencial.
                Sin código aplica el precio de lista.
            </p>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;max-width:420px">
                <label style="flex:1;min-width:180px">Código
                    <input type="text" name="promo_code" id="promo_code" placeholder="Opcional" style="text-transform:uppercase;padding:.55rem .7rem;border:1px solid #cfd8e6;border-radius:10px;width:100%">
                </label>
                <button type="button" class="btn btn-primary btn-sm" id="apply-promo" style="margin-bottom:2px">Aplicar</button>
            </div>
            <p class="muted" id="quote-error" style="color:#b00020;display:none;margin:.5rem 0 0"></p>
            <div class="price-summary" style="margin-top:1rem;padding:.85rem 1rem;background:#f4f7fb;border-radius:12px;max-width:420px">
                <div class="muted" style="font-size:.82rem" id="price-label"><?= e($quote['label'] ?? 'Precio de lista') ?></div>
                <div style="display:flex;align-items:baseline;gap:.75rem;flex-wrap:wrap;margin-top:.25rem">
                    <span class="price" id="price-list" style="font-size:1.35rem;color:var(--doceo-blue)"><?= money($catalogPrice) ?></span>
                    <span id="price-arrow" style="display:none;color:var(--doceo-muted)">→</span>
                    <span class="price" id="price-final" style="font-size:1.35rem;color:var(--doceo-blue);display:none"></span>
                </div>
            </div>
        </section>

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
                                <?= $code === 'email' ? 'autocomplete="email"' : '' ?>
                                <?= $code === 'phone' ? 'autocomplete="tel"' : '' ?>>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
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
            <h2><?= $step++ ?>. Forma de pago</h2>
            <p class="muted" style="margin-top:0;font-size:.88rem">Elige cómo realizarás tu pago.</p>

            <div class="pay-tiles" role="group" aria-label="Método de pago">
                <button type="button" class="pay-tile active" data-method="transfer_proof" data-ui="transfer" aria-pressed="true">
                    <span class="pay-tile-icon" aria-hidden="true">🏦</span>
                    <span class="pay-tile-label">Transferencia</span>
                    <span class="pay-tile-sub">SPEI · sube comprobante</span>
                </button>
                <?php if ($openpayReady): ?>
                    <button type="button" class="pay-tile" data-method="openpay_store" data-ui="oxxo" aria-pressed="false">
                        <span class="pay-tile-icon" aria-hidden="true">🏪</span>
                        <span class="pay-tile-label">OXXO</span>
                        <span class="pay-tile-sub">Efectivo en tienda</span>
                    </button>
                    <button type="button" class="pay-tile" data-method="openpay_card" data-ui="msi" aria-pressed="false">
                        <span class="pay-tile-icon" aria-hidden="true">💳</span>
                        <span class="pay-tile-label">Meses</span>
                        <span class="pay-tile-sub">MSI con tarjeta</span>
                    </button>
                <?php endif; ?>
            </div>

            <div id="pay-transfer-panel" class="pay-panel">
                <p class="muted" style="font-size:.88rem;margin:.75rem 0 .5rem">
                    Realiza la transferencia por el monto indicado y sube tu comprobante ahora.
                </p>
                <?php if (!empty($bank['clabe'])): ?>
                    <ul class="muted" style="font-size:.85rem;margin:.5rem 0 .75rem;padding-left:1.1rem">
                        <li>Banco: <strong><?= e($bank['bank']) ?></strong></li>
                        <li>CLABE: <strong style="font-family:ui-monospace,monospace"><?= e($bank['clabe']) ?></strong></li>
                        <li>Titular: <strong><?= e($bank['holder']) ?></strong></li>
                    </ul>
                <?php endif; ?>
                <label>Comprobante de pago *
                    <input type="file" name="payment_proof" id="payment_proof" accept=".pdf,.jpg,.jpeg,.png">
                </label>
                <p class="muted" style="font-size:.82rem;margin:.35rem 0 0">Total a transferir: <strong id="transfer-amount"><?= money($basePrice) ?></strong></p>
            </div>

            <div id="pay-oxxo-panel" class="pay-panel" hidden>
                <p class="muted" style="font-size:.88rem;margin:.75rem 0 0">
                    Al confirmar tu registro te mostraremos la referencia y código de barras para pagar en OXXO.
                </p>
            </div>

            <div id="pay-msi-panel" class="pay-panel" hidden>
                <p class="muted" style="font-size:.82rem;margin:.75rem 0 .4rem">¿A cuántos meses?</p>
                <div class="msi-chips" id="msi-chips"></div>
                <p style="margin:.85rem 0 0;font-size:1.1rem;color:var(--doceo-blue);font-weight:700" id="msi-monthly-line"></p>
                <p class="muted" style="font-size:.82rem;margin:.35rem 0 0">
                    Cargo total con tarjeta en OpenPay; tu banco difiere el cobro mensual.
                </p>
            </div>

            <p class="muted" id="pay-hint" style="font-size:.82rem;margin-top:.65rem"></p>
        </section>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem">
            <button class="btn btn-accent" type="submit" id="checkout-submit">Confirmar registro</button>
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
.pay-tiles { display:flex; gap:.65rem; flex-wrap:wrap; }
.pay-tile {
  flex:1; min-width:100px; max-width:140px; display:flex; flex-direction:column; align-items:center; gap:.2rem;
  padding:.75rem .5rem; border:2px solid #d5deea; border-radius:14px; background:#fbfcfe; cursor:pointer;
  font:inherit; color:var(--doceo-text); transition:border-color .15s, background .15s;
}
.pay-tile:hover { border-color:#9cb4d8; }
.pay-tile.active { border-color:var(--doceo-blue); background:#eef4fc; }
.pay-tile-icon { font-size:1.45rem; line-height:1; }
.pay-tile-label { font-weight:700; font-size:.95rem; color:var(--doceo-blue); }
.pay-tile-sub { font-size:.68rem; color:var(--doceo-muted); font-weight:500; text-align:center; line-height:1.2; }
.msi-chips { display:flex; gap:.45rem; flex-wrap:wrap; }
.msi-chip {
  padding:.45rem .9rem; border:1px solid #cfd8e6; border-radius:999px; background:#fff;
  font:inherit; font-size:.85rem; font-weight:600; cursor:pointer; color:var(--doceo-muted);
}
.msi-chip.active { border-color:var(--doceo-blue); color:var(--doceo-blue); background:#eef4fc; }
.price-summary .price-strike { text-decoration:line-through; opacity:.55; font-size:1.1rem; }
</style>
<script>
(function () {
  const slug = <?= json_encode($product['slug'], JSON_UNESCAPED_UNICODE) ?>;
  const openpayReady = <?= $openpayReady ? 'true' : 'false' ?>;
  let quoteData = <?= json_encode($quote, JSON_UNESCAPED_UNICODE) ?>;
  let payUi = 'transfer';

  const codeInput = document.getElementById('promo_code');
  const applyBtn = document.getElementById('apply-promo');
  const priceList = document.getElementById('price-list');
  const priceFinal = document.getElementById('price-final');
  const priceArrow = document.getElementById('price-arrow');
  const labelEl = document.getElementById('price-label');
  const errEl = document.getElementById('quote-error');
  const methodInput = document.getElementById('payment_method');
  const msiInput = document.getElementById('card_msi_months');
  const msiChips = document.getElementById('msi-chips');
  const msiMonthlyLine = document.getElementById('msi-monthly-line');
  const transferAmount = document.getElementById('transfer-amount');
  const transferPanel = document.getElementById('pay-transfer-panel');
  const oxxoPanel = document.getElementById('pay-oxxo-panel');
  const msiPanel = document.getElementById('pay-msi-panel');
  const proofInput = document.getElementById('payment_proof');
  const payHint = document.getElementById('pay-hint');
  const submitBtn = document.getElementById('checkout-submit');
  const form = document.getElementById('checkout-form');

  function money(n) {
    return '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function baseAmount() {
    return Number(quoteData.base ?? quoteData.catalog ?? 0);
  }

  function catalogAmount() {
    return Number(quoteData.catalog ?? 0);
  }

  function updatePriceSummary() {
    const catalog = catalogAmount();
    const base = baseAmount();
    const hasDiscount = base > 0 && base < catalog - 0.009;

    priceList.textContent = money(catalog);
    if (hasDiscount) {
      priceList.classList.add('price-strike');
      priceArrow.style.display = '';
      priceFinal.style.display = '';
      priceFinal.textContent = money(base);
    } else {
      priceList.classList.remove('price-strike');
      priceArrow.style.display = 'none';
      priceFinal.style.display = 'none';
    }
    if (transferAmount) transferAmount.textContent = money(base);
  }

  function activeMsiMonths() {
    const chip = msiChips && msiChips.querySelector('.msi-chip.active');
    return chip ? Number(chip.getAttribute('data-months') || 3) : 3;
  }

  function updateMsiDisplay() {
    if (!msiMonthlyLine || !msiChips) return;
    const months = activeMsiMonths();
    const plans = quoteData.payment_options?.msi || quoteData.msi_plans || [];
    const plan = plans.find(p => Number(p.months) === months) || plans[0];
    if (plan && Number(plan.months) > 1) {
      msiMonthlyLine.textContent = 'Mensualidades de ' + money(plan.monthly_estimate);
    } else {
      msiMonthlyLine.textContent = '';
    }
  }

  function updateHint() {
    if (!payHint || !submitBtn) return;
    if (payUi === 'msi') {
      payHint.textContent = 'Al continuar irás a la página segura de OpenPay para pagar con tarjeta.';
      submitBtn.textContent = 'Continuar al pago con tarjeta';
    } else if (payUi === 'oxxo') {
      payHint.textContent = 'Al confirmar verás la referencia para pagar en OXXO.';
      submitBtn.textContent = 'Confirmar y ver referencia OXXO';
    } else {
      payHint.textContent = 'Sube tu comprobante de transferencia para completar el registro.';
      submitBtn.textContent = 'Confirmar registro';
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

    if (transferPanel) transferPanel.hidden = ui !== 'transfer';
    if (oxxoPanel) oxxoPanel.hidden = ui !== 'oxxo';
    if (msiPanel) msiPanel.hidden = ui !== 'msi';

    if (proofInput) {
      proofInput.required = ui === 'transfer';
      if (ui !== 'transfer') proofInput.value = '';
    }

    if (ui === 'msi' && msiChips) {
      const chip = msiChips.querySelector('.msi-chip.active') || msiChips.querySelector('.msi-chip');
      if (chip) msiInput.value = chip.getAttribute('data-months') || '3';
      updateMsiDisplay();
    } else {
      msiInput.value = '1';
    }

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
      msiInput.value = chip.getAttribute('data-months') || '3';
      updateMsiDisplay();
    });
  }

  function renderMsiChips(plans) {
    if (!msiChips) return;
    const list = (Array.isArray(plans) ? plans : []).filter(p => Number(p.months) > 1);
    if (list.length === 0) {
      msiChips.innerHTML = '<span class="muted">MSI no disponible para este monto.</span>';
      return;
    }
    msiChips.innerHTML = list.map((p, i) => {
      const m = Number(p.months);
      return '<button type="button" class="msi-chip' + (i === 0 ? ' active' : '') + '" data-months="' + m + '">' + m + ' meses</button>';
    }).join('');
    msiInput.value = String(list[0].months || 3);
    updateMsiDisplay();
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
        labelEl.textContent = data.quote.label || 'Precio de lista';
        renderMsiChips(data.quote.payment_options?.msi || data.quote.msi_plans || []);
        updatePriceSummary();
        updateMsiDisplay();
      })
      .catch(() => {});
  }

  applyBtn.addEventListener('click', refreshQuote);
  codeInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); refreshQuote(); }
  });

  form.addEventListener('submit', e => {
    if (payUi === 'transfer' && proofInput && !proofInput.files.length) {
      e.preventDefault();
      alert('Sube el comprobante de tu transferencia.');
      proofInput.focus();
    }
  });

  renderMsiChips(quoteData.payment_options?.msi || quoteData.msi_plans || []);
  updatePriceSummary();
  updateHint();
  selectPayUi('transfer', 'transfer_proof');
})();
</script>

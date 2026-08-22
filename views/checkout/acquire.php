<?php
/** @var array<string,mixed> $product */
/** @var list<array{code:string,label:string,required:bool,type:string}> $fields */
/** @var list<array{code:string,label:string,required:bool,accept:string}> $docs */
/** @var array<string,string> $prefill */
/** @var array<string,mixed> $quote */
/** @var array{bank:string,clabe:string,holder:string,concept:string} $bank */
/** @var bool $openpayReady */
/** @var bool $openpayCardReady */
/** @var string $openpayMerchantId */
/** @var string $openpayPublicKey */
/** @var bool $openpaySandbox */
$step = 1;
$msiPlans = $quote['msi_plans'] ?? [];
if ($msiPlans === []) {
    $msiPlans = [['months' => 1, 'total' => $quote['charged'], 'monthly_estimate' => $quote['charged'], 'label' => 'Un solo pago (contado)']];
}
$defaultPay = $openpayCardReady ? 'openpay_card' : ($openpayReady ? 'openpay_spei' : 'transfer_proof');
?>
<article class="panel checkout" style="margin:1.25rem 0 2.5rem">
    <p class="meta"><a href="<?= e(url('/producto/' . $product['slug'])) ?>">← <?= e($product['name']) ?></a></p>
    <h1 style="margin:.2rem 0 .4rem;color:var(--doceo-blue)">Adquirir</h1>
    <p class="muted" style="margin-top:0">
        Solo te pedimos lo necesario para este producto.
        <?php if ($docs === []): ?>
            Los documentos del proceso (reglamento, firma, etc.) se solicitan después, en tu panel, solo si aplica.
        <?php else: ?>
            Sube los documentos indicados y elige cómo pagar.
        <?php endif; ?>
    </p>

    <form method="post" action="<?= e(url('/adquirir/' . $product['slug'])) ?>" enctype="multipart/form-data" class="checkout-form" id="checkout-form">
        <?= csrf_field() ?>
        <input type="hidden" name="openpay_token" id="openpay_token" value="">
        <input type="hidden" name="device_session_id" id="device_session_id" value="">

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
                            <input
                                type="<?= e($field['type']) ?>"
                                name="<?= e($code) ?>"
                                value="<?= e((string) $val) ?>"
                                <?= $req ? 'required' : '' ?>
                                <?= $code === 'curp' ? 'maxlength="18" style="text-transform:uppercase"' : '' ?>
                                <?= $code === 'email' ? 'autocomplete="email"' : '' ?>
                                <?= $code === 'phone' ? 'autocomplete="tel"' : '' ?>
                            >
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="checkout-section">
            <h2><?= $step++ ?>. Precio y código</h2>
            <div class="price-box">
                <div>
                    <div class="muted" style="font-size:.85rem">Precio de lista</div>
                    <div class="price" id="price-catalog"><?= money($quote['catalog']) ?></div>
                </div>
                <div>
                    <div class="muted" style="font-size:.85rem">A pagar</div>
                    <div class="price" id="price-charged" style="font-size:1.6rem"><?= money($quote['charged']) ?></div>
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
                            <span class="muted" style="font-weight:500;font-size:.78rem">Formatos: <?= e(strtoupper(str_replace('.', '', $doc['accept']))) ?> · máx. 8 MB</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="checkout-section">
            <h2><?= $step++ ?>. Pago</h2>
            <div class="pay-options">
                <?php if ($openpayCardReady): ?>
                    <label class="pay-option">
                        <input type="radio" name="payment_method" value="openpay_card" <?= $defaultPay === 'openpay_card' ? 'checked' : '' ?> data-needs-proof="0" data-needs-card="1">
                        <span>
                            <strong>Tarjeta de crédito (OpenPay)</strong>
                            <small>Pagas el total hoy con tu tarjeta. Si eliges MSI, tu banco te cobra en mensualidades; nosotros recibimos el monto completo.</small>
                        </span>
                    </label>
                <?php endif; ?>
                <label class="pay-option">
                    <input type="radio" name="payment_method" value="openpay_spei" <?= $defaultPay === 'openpay_spei' ? 'checked' : '' ?> data-needs-proof="0" data-needs-card="0">
                    <span>
                        <strong>SPEI (OpenPay)</strong>
                        <small><?= $openpayReady ? 'Te generamos una CLABE única por el monto total.' : 'Si OpenPay no responde, usa transferencia DOCEO.' ?></small>
                    </span>
                </label>
                <label class="pay-option">
                    <input type="radio" name="payment_method" value="transfer_proof" <?= $defaultPay === 'transfer_proof' ? 'checked' : '' ?> data-needs-proof="1" data-needs-card="0">
                    <span>
                        <strong>Transferencia a cuenta DOCEO</strong>
                        <small>Deposita el monto total y sube tu comprobante. Validamos el pago manualmente.</small>
                        <?php if ($bank['clabe'] !== ''): ?>
                            <small class="bank-hint"><?= e($bank['bank']) ?> · CLABE <?= e($bank['clabe']) ?> · <?= e($bank['holder']) ?></small>
                        <?php endif; ?>
                    </span>
                </label>
            </div>

            <div id="msi-section" style="margin-top:1rem;display:none">
                <h3 style="margin:0 0 .5rem;font-size:.95rem;color:var(--doceo-blue)">Meses sin intereses (opcional)</h3>
                <p class="muted" style="margin-top:0;font-size:.85rem">El cargo a tu tarjeta es por el <strong>total</strong>. Tu banco puede diferir el cobro en mensualidades según el plan elegido.</p>
                <div class="pay-options" id="msi-options">
                    <?php foreach ($msiPlans as $i => $plan): ?>
                        <label class="pay-option">
                            <input type="radio" name="card_msi_months" value="<?= (int) $plan['months'] ?>"
                                   data-total="<?= e(number_format((float) $plan['total'], 2, '.', '')) ?>"
                                   data-estimate="<?= e(number_format((float) $plan['monthly_estimate'], 2, '.', '')) ?>"
                                   <?= $i === 0 ? 'checked' : '' ?>>
                            <span>
                                <strong><?= e($plan['label']) ?></strong>
                                <?php if ((int) $plan['months'] > 1): ?>
                                    <small>Cargo hoy: <?= money($plan['total']) ?> · referencia ~<?= money($plan['monthly_estimate']) ?>/mes en tu estado de cuenta</small>
                                <?php else: ?>
                                    <small>Un solo cargo por <?= money($plan['total']) ?></small>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted" id="msi-hint" style="font-size:.85rem;margin-top:.5rem"></p>
            </div>

            <div id="card-wrap" style="margin-top:1rem;display:none">
                <h3 style="margin:0 0 .5rem;font-size:.95rem;color:var(--doceo-blue)">Datos de la tarjeta</h3>
                <div class="form-grid">
                    <label>Titular *
                        <input type="text" id="card_holder" autocomplete="cc-name" placeholder="Como aparece en la tarjeta">
                    </label>
                    <label>Número de tarjeta *
                        <input type="text" id="card_number" inputmode="numeric" autocomplete="cc-number" maxlength="19" placeholder="0000 0000 0000 0000">
                    </label>
                    <label>Vence (MM/AA) *
                        <input type="text" id="card_expiry" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/AA">
                    </label>
                    <label>CVV *
                        <input type="text" id="card_cvv" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="123">
                    </label>
                </div>
                <p class="muted" id="card-error" style="color:#b00020;display:none;font-size:.85rem;margin-top:.5rem"></p>
            </div>

            <div id="proof-wrap" style="margin-top:.85rem">
                <label>Comprobante de transferencia *
                    <input type="file" name="payment_proof" id="payment_proof" accept=".pdf,.jpg,.jpeg,.png">
                </label>
            </div>
        </section>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem">
            <button class="btn btn-accent" type="submit" id="checkout-submit">Confirmar compra</button>
            <a class="btn btn-ghost" href="<?= e(url('/producto/' . $product['slug'])) ?>">Cancelar</a>
        </div>
        <p class="muted" style="font-size:.8rem;margin-top:.75rem">Si el correo es nuevo, creamos tu cuenta con contraseña temporal <code>Doceo*1234</code> (configurable).</p>
    </form>
</article>
<style>
.checkout-section { margin-top:1.4rem; padding-top:1rem; border-top:1px solid #e6ebf2; }
.checkout-section h2 { margin:0 0 .75rem; font-size:1.05rem; color:var(--doceo-blue); }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:.75rem 1rem; }
.form-grid label, .checkout-form label { display:flex; flex-direction:column; gap:.35rem; font-size:.88rem; font-weight:600; color:var(--doceo-muted); }
.form-grid input, .form-grid select, .checkout-form input[type=text], .checkout-form input[type=email], .checkout-form input[type=tel], .checkout-form input[type=date], .checkout-form input[type=file] {
  font:inherit; font-weight:500; color:var(--doceo-text); padding:.55rem .7rem; border:1px solid #cfd8e6; border-radius:10px; background:#fff;
}
.price-box { display:flex; gap:2rem; flex-wrap:wrap; align-items:flex-end; }
.pay-options { display:grid; gap:.65rem; }
.pay-option { display:flex; gap:.75rem; align-items:flex-start; padding:.85rem 1rem; border:1px solid #d5deea; border-radius:12px; background:#fbfcfe; cursor:pointer; font-weight:500; }
.pay-option input { margin-top:.25rem; }
.pay-option strong { display:block; color:var(--doceo-blue); }
.pay-option small { display:block; color:var(--doceo-muted); font-weight:500; margin-top:.15rem; }
.bank-hint { font-family:ui-monospace,monospace; }
</style>
<?php if ($openpayCardReady): ?>
<script src="https://openpay.s3.amazonaws.com/openpay.v1.min.js"></script>
<script src="https://openpay.s3.amazonaws.com/openpay-data.v1.min.js"></script>
<?php endif; ?>
<script>
(function () {
  const slug = <?= json_encode($product['slug'], JSON_UNESCAPED_UNICODE) ?>;
  const codeInput = document.getElementById('promo_code');
  const chargedEl = document.getElementById('price-charged');
  const labelEl = document.getElementById('price-label');
  const errEl = document.getElementById('quote-error');
  const form = document.getElementById('checkout-form');
  const msiBox = document.getElementById('msi-options');
  const msiHint = document.getElementById('msi-hint');
  const msiSection = document.getElementById('msi-section');
  const cardWrap = document.getElementById('card-wrap');
  const cardError = document.getElementById('card-error');
  const submitBtn = document.getElementById('checkout-submit');
  const openpayCardReady = <?= $openpayCardReady ? 'true' : 'false' ?>;
  let timer = null;
  let tokenizing = false;

  function moneyFmt(n) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n || 0));
  }

  function money(n) {
    return '$' + Number(n).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function selectedPayment() {
    return document.querySelector('input[name=payment_method]:checked');
  }

  function renderMsiPlans(plans) {
    if (!msiBox) return;
    const list = Array.isArray(plans) && plans.length ? plans : [{ months: 1, total: 0, monthly_estimate: 0, label: 'Un solo pago (contado)' }];
    msiBox.innerHTML = list.map((p, i) => {
      const months = Number(p.months || 1);
      const total = Number(p.total || 0);
      const estimate = Number(p.monthly_estimate || total);
      const label = p.label || (months === 1 ? 'Un solo pago (contado)' : (months + ' MSI'));
      const small = months > 1
        ? ('Cargo hoy: ' + moneyFmt(total) + ' · referencia ~' + moneyFmt(estimate) + '/mes en tu estado de cuenta')
        : ('Un solo cargo por ' + moneyFmt(total));
      return '<label class="pay-option">'
        + '<input type="radio" name="card_msi_months" value="' + months + '" data-total="' + total.toFixed(2) + '" data-estimate="' + estimate.toFixed(2) + '"' + (i === 0 ? ' checked' : '') + '>'
        + '<span><strong>' + label + '</strong><small>' + small + '</small></span></label>';
    }).join('');
    msiBox.querySelectorAll('input[name=card_msi_months]').forEach(el => el.addEventListener('change', updateMsiHint));
    updateMsiHint();
  }

  function updateMsiHint() {
    if (!msiHint) return;
    const selected = document.querySelector('input[name=card_msi_months]:checked');
    if (!selected) { msiHint.textContent = ''; return; }
    const months = Number(selected.value || 1);
    const total = selected.getAttribute('data-total');
    const estimate = selected.getAttribute('data-estimate');
    msiHint.textContent = months > 1
      ? ('Hoy se autoriza el cargo total de ' + moneyFmt(total) + '. Tu banco puede mostrar ~' + moneyFmt(estimate) + ' mensuales en tu tarjeta.')
      : 'Se cobrará el monto total en un solo pago.';
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
        chargedEl.textContent = money(data.quote.charged);
        labelEl.textContent = data.quote.label || '';
        renderMsiPlans(data.quote.msi_plans || []);
      })
      .catch(() => {});
  }

  codeInput.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(refreshQuote, 400);
  });

  const proofWrap = document.getElementById('proof-wrap');
  const proofInput = document.getElementById('payment_proof');

  function syncPaymentUi() {
    const selected = selectedPayment();
    const needsProof = selected && selected.getAttribute('data-needs-proof') === '1';
    const needsCard = selected && selected.getAttribute('data-needs-card') === '1';
    proofWrap.style.display = needsProof ? 'block' : 'none';
    proofInput.required = !!needsProof;
    if (msiSection) msiSection.style.display = needsCard ? 'block' : 'none';
    if (cardWrap) cardWrap.style.display = needsCard ? 'block' : 'none';
    if (!needsCard) {
      const contado = document.querySelector('input[name=card_msi_months][value="1"]');
      if (contado) contado.checked = true;
    }
    updateMsiHint();
  }

  document.querySelectorAll('input[name=payment_method]').forEach(el => el.addEventListener('change', syncPaymentUi));
  syncPaymentUi();
  updateMsiHint();

  if (openpayCardReady && typeof OpenPay !== 'undefined') {
    OpenPay.setId(<?= json_encode($openpayMerchantId) ?>);
    OpenPay.setApiKey(<?= json_encode($openpayPublicKey) ?>);
    OpenPay.setSandboxMode(<?= $openpaySandbox ? 'true' : 'false' ?>);
    if (typeof OpenPay.deviceData !== 'undefined') {
      OpenPay.deviceData.setup('checkout-form', 'device_session_id');
    }
  }

  form.addEventListener('submit', function (ev) {
    const selected = selectedPayment();
    if (!selected || selected.value !== 'openpay_card' || !openpayCardReady) return;

    ev.preventDefault();
    if (tokenizing) return;
    if (typeof OpenPay === 'undefined') {
      cardError.style.display = 'block';
      cardError.textContent = 'OpenPay no está disponible. Recarga la página o elige otro método.';
      return;
    }

    cardError.style.display = 'none';
    const holder = (document.getElementById('card_holder').value || '').trim();
    const number = (document.getElementById('card_number').value || '').replace(/\s+/g, '');
    const expiry = (document.getElementById('card_expiry').value || '').trim();
    const cvv = (document.getElementById('card_cvv').value || '').trim();
    const m = expiry.match(/^(\d{2})\s*\/?\s*(\d{2})$/);
    if (!holder || number.length < 13 || !m || cvv.length < 3) {
      cardError.style.display = 'block';
      cardError.textContent = 'Completa los datos de la tarjeta.';
      return;
    }

    tokenizing = true;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Procesando tarjeta…';

    OpenPay.token.create({
      card_number: number,
      holder_name: holder,
      expiration_month: m[1],
      expiration_year: m[2],
      cvv2: cvv
    }, function (response) {
      document.getElementById('openpay_token').value = response.data.id;
      form.submit();
    }, function (response) {
      tokenizing = false;
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirmar compra';
      cardError.style.display = 'block';
      const desc = response.data && response.data.description ? response.data.description : 'No se pudo tokenizar la tarjeta.';
      cardError.textContent = desc;
    });
  });
})();
</script>

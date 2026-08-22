<?php
/** @var array<string,mixed> $product */
/** @var list<array{code:string,label:string,required:bool,type:string}> $fields */
/** @var list<array{code:string,label:string,required:bool,accept:string}> $docs */
/** @var array<string,string> $prefill */
/** @var array<string,mixed> $quote */
/** @var array{bank:string,clabe:string,holder:string,concept:string} $bank */
/** @var bool $openpayReady */
$step = 1;
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


        <section class="checkout-section" id="deferred-section">
            <h2><?= $step++ ?>. Plan de pago</h2>
            <p class="muted" style="margin-top:0">Elige contado o pagos diferidos. Con SPEI OpenPay se genera la CLABE del <strong>primer pago</strong>; el resto queda calendarizado.</p>
            <div class="pay-options" id="deferred-options">
                <?php
                $plans = $quote['deferred_plans'] ?? [];
                if ($plans === []) {
                    $plans = [['months' => 1, 'monthly' => $quote['charged'], 'label' => 'Pago de contado', 'total' => $quote['charged']]];
                }
                foreach ($plans as $i => $plan):
                ?>
                    <label class="pay-option">
                        <input type="radio" name="installment_count" value="<?= (int) $plan['months'] ?>"
                               data-monthly="<?= e(number_format((float) $plan['monthly'], 2, '.', '')) ?>"
                               <?= $i === 0 ? 'checked' : '' ?>>
                        <span>
                            <strong><?= e($plan['label']) ?></strong>
                            <?php if ((int) $plan['months'] > 1): ?>
                                <small>Total <?= money($plan['total']) ?> · primer cargo <?= money($plan['monthly']) ?></small>
                            <?php else: ?>
                                <small>Un solo cargo por el total</small>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="muted" id="deferred-hint" style="font-size:.85rem;margin-top:.5rem"></p>
        </section>

        <section class="checkout-section">
            <h2><?= $step++ ?>. Pago</h2>
            <div class="pay-options">
                <label class="pay-option">
                    <input type="radio" name="payment_method" value="openpay_spei" <?= $openpayReady ? 'checked' : '' ?> data-needs-proof="0">
                    <span>
                        <strong>SPEI (OpenPay)</strong>
                        <small><?= $openpayReady ? 'Te generamos una CLABE única al confirmar.' : 'Si OpenPay no responde, usa transferencia DOCEO.' ?></small>
                    </span>
                </label>
                <label class="pay-option">
                    <input type="radio" name="payment_method" value="transfer_proof" <?= $openpayReady ? '' : 'checked' ?> data-needs-proof="1">
                    <span>
                        <strong>Transferencia a cuenta DOCEO</strong>
                        <small>Deposita y sube tu comprobante. Validamos el pago manualmente.</small>
                        <?php if ($bank['clabe'] !== ''): ?>
                            <small class="bank-hint"><?= e($bank['bank']) ?> · CLABE <?= e($bank['clabe']) ?> · <?= e($bank['holder']) ?></small>
                        <?php endif; ?>
                    </span>
                </label>
            </div>
            <div id="proof-wrap" style="margin-top:.85rem">
                <label>Comprobante de transferencia *
                    <input type="file" name="payment_proof" id="payment_proof" accept=".pdf,.jpg,.jpeg,.png">
                </label>
            </div>
        </section>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem">
            <button class="btn btn-accent" type="submit">Confirmar compra</button>
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
<script>

  const deferredBox = document.getElementById('deferred-options');
  const deferredHint = document.getElementById('deferred-hint');

  function moneyFmt(n) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(n || 0));
  }

  function renderDeferredPlans(plans) {
    if (!deferredBox) return;
    const list = Array.isArray(plans) && plans.length ? plans : [{ months: 1, monthly: 0, total: 0, label: 'Pago de contado' }];
    deferredBox.innerHTML = list.map((p, i) => {
      const months = Number(p.months || 1);
      const monthly = Number(p.monthly || 0);
      const total = Number(p.total || monthly);
      const label = p.label || (months === 1 ? 'Pago de contado' : (months + ' pagos'));
      const small = months > 1
        ? ('Total ' + moneyFmt(total) + ' · primer cargo ' + moneyFmt(monthly))
        : 'Un solo cargo por el total';
      return '<label class="pay-option">'
        + '<input type="radio" name="installment_count" value="' + months + '" data-monthly="' + monthly.toFixed(2) + '"' + (i === 0 ? ' checked' : '') + '>'
        + '<span><strong>' + label + '</strong><small>' + small + '</small></span></label>';
    }).join('');
    deferredBox.querySelectorAll('input[name=installment_count]').forEach(el => el.addEventListener('change', updateDeferredHint));
    updateDeferredHint();
  }

  function updateDeferredHint() {
    if (!deferredHint) return;
    const selected = document.querySelector('input[name=installment_count]:checked');
    if (!selected) { deferredHint.textContent = ''; return; }
    const months = Number(selected.value || 1);
    const monthly = selected.getAttribute('data-monthly');
    deferredHint.textContent = months > 1
      ? ('Se cobrará ahora el pago 1 de ' + months + ' (' + moneyFmt(monthly) + '). Los siguientes quedan en tu calendario.')
      : 'Se cobrará el monto total en un solo pago.';
  }

(function () {
  const slug = <?= json_encode($product['slug'], JSON_UNESCAPED_UNICODE) ?>;
  const codeInput = document.getElementById('promo_code');
  const chargedEl = document.getElementById('price-charged');
  const labelEl = document.getElementById('price-label');
  const errEl = document.getElementById('quote-error');
  let timer = null;

  function money(n) {
    return '$' + Number(n).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
        if (typeof renderDeferredPlans === 'function') renderDeferredPlans(data.quote.deferred_plans || []);
        labelEl.textContent = data.quote.label || '';
      })
      .catch(() => {});
  }

  codeInput.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(refreshQuote, 400);
  });

  const proofWrap = document.getElementById('proof-wrap');
  const proofInput = document.getElementById('payment_proof');
  function syncProof() {
    const selected = document.querySelector('input[name=payment_method]:checked');
    const needs = selected && selected.getAttribute('data-needs-proof') === '1';
    proofWrap.style.display = needs ? 'block' : 'none';
    proofInput.required = !!needs;
  }
  document.querySelectorAll('input[name=payment_method]').forEach(el => el.addEventListener('change', syncProof));
  syncProof();
  updateDeferredHint();
})();
</script>

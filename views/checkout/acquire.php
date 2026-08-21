<?php
/** @var array<string,mixed> $product */
/** @var list<array{code:string,label:string,required:bool,accept:string}> $docs */
/** @var array<string,string> $prefill */
/** @var array<string,mixed> $quote */
/** @var array{bank:string,clabe:string,holder:string,concept:string} $bank */
/** @var bool $openpayReady */
?>
<article class="panel checkout" style="margin:1.25rem 0 2.5rem">
    <p class="meta"><a href="<?= e(url('/producto/' . $product['slug'])) ?>">← <?= e($product['name']) ?></a></p>
    <h1 style="margin:.2rem 0 .4rem;color:var(--doceo-blue)">Adquirir</h1>
    <p class="muted" style="margin-top:0">Sube los documentos requeridos y elige cómo pagar. Al confirmar se crea tu matrícula y acceso al portal.</p>

    <form method="post" action="<?= e(url('/adquirir/' . $product['slug'])) ?>" enctype="multipart/form-data" class="checkout-form" id="checkout-form">
        <?= csrf_field() ?>

        <section class="checkout-section">
            <h2>1. Datos del alumno</h2>
            <div class="form-grid">
                <label>Correo *
                    <input type="email" name="email" required value="<?= e($prefill['email']) ?>" autocomplete="email">
                </label>
                <label>Teléfono
                    <input type="tel" name="phone" value="<?= e($prefill['phone']) ?>" autocomplete="tel">
                </label>
                <label>Nombre(s) *
                    <input type="text" name="first_name" required value="<?= e($prefill['first_name']) ?>">
                </label>
                <label>Apellido paterno *
                    <input type="text" name="last_name_p" required value="<?= e($prefill['last_name_p']) ?>">
                </label>
                <label>Apellido materno
                    <input type="text" name="last_name_m" value="<?= e($prefill['last_name_m']) ?>">
                </label>
                <label>CURP
                    <input type="text" name="curp" maxlength="18" value="" style="text-transform:uppercase">
                </label>
                <label>Fecha de nacimiento
                    <input type="date" name="birth_date">
                </label>
                <label>Sexo
                    <select name="sex">
                        <option value="">—</option>
                        <option value="F">Femenino</option>
                        <option value="M">Masculino</option>
                        <option value="X">Otro / X</option>
                    </select>
                </label>
            </div>
        </section>

        <section class="checkout-section">
            <h2>2. Precio y código</h2>
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

        <section class="checkout-section">
            <h2>3. Documentos (antes del pago)</h2>
            <div class="form-grid">
                <?php foreach ($docs as $doc): ?>
                    <label><?= e($doc['label']) ?><?= $doc['required'] ? ' *' : '' ?>
                        <input type="file" name="doc_<?= e($doc['code']) ?>" accept="<?= e($doc['accept']) ?>" <?= $doc['required'] ? 'required' : '' ?>>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="muted" style="font-size:.82rem">PDF, JPG o PNG · máx. 8 MB c/u</p>
        </section>

        <section class="checkout-section">
            <h2>4. Pago</h2>
            <div class="pay-options">
                <label class="pay-option">
                    <input type="radio" name="payment_method" value="transfer_proof" checked data-needs-proof="1">
                    <span>
                        <strong>Transferencia bancaria</strong>
                        <small>Deposita y sube tu comprobante. Validamos el pago manualmente.</small>
                        <?php if ($bank['clabe'] !== ''): ?>
                            <small class="bank-hint"><?= e($bank['bank']) ?> · CLABE <?= e($bank['clabe']) ?> · <?= e($bank['holder']) ?></small>
                        <?php endif; ?>
                    </span>
                </label>
                <label class="pay-option">
                    <input type="radio" name="payment_method" value="openpay_spei" data-needs-proof="0" <?= $openpayReady ? '' : '' ?>>
                    <span>
                        <strong>SPEI (OpenPay)</strong>
                        <small><?= $openpayReady ? 'Te generamos una CLABE única al confirmar.' : 'Si OpenPay no está configurado, te mostraremos la cuenta DOCEO al confirmar.' ?></small>
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
})();
</script>

<?php
/** @var array{template_url:string,doc_code:string,required_before_checkout:bool} $reglamento */
$docCode = $reglamento['doc_code'];
$docField = 'doc_' . $docCode;
?>
<section class="checkout-section reglamento-section" id="reglamento-section">
    <h2><?= (int) $step ?>. Reglamento</h2>
    <p class="muted" style="margin-top:0;font-size:.88rem">
        Lee el reglamento completo. Debes firmarlo digitalmente antes de continuar con la compra.
    </p>

    <div class="reglamento-viewer">
        <iframe
            src="<?= e($reglamento['template_url']) ?>#toolbar=1"
            title="Reglamento"
            class="reglamento-frame"
        ></iframe>
        <p class="muted" style="font-size:.82rem;margin:.5rem 0 0">
            <a href="<?= e($reglamento['template_url']) ?>" target="_blank" rel="noopener">Abrir reglamento en pestaña nueva</a>
        </p>
    </div>

    <label class="reglamento-check" style="display:flex;gap:.5rem;align-items:flex-start;margin:1rem 0;font-size:.9rem;font-weight:600;color:var(--doceo-text)">
        <input type="checkbox" name="reglamento_accepted" id="reglamento_accepted" value="1" required style="margin-top:.2rem">
        <span>He leído y acepto el reglamento del examen.</span>
    </label>

    <div class="signature-block">
        <p style="margin:0 0 .5rem;font-weight:600;color:var(--doceo-blue);font-size:.92rem">Firma digital</p>
        <p class="muted" style="font-size:.82rem;margin:0 0 .65rem">Firma dentro del recuadro con mouse o dedo. Se adjuntará como última página del PDF.</p>
        <div class="signature-pad-wrap">
            <canvas id="signature-canvas" width="520" height="160" aria-label="Área de firma"></canvas>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.6rem">
            <button type="button" class="btn btn-ghost btn-sm" id="signature-clear">Limpiar firma</button>
            <span class="muted" id="signature-status" style="font-size:.82rem;align-self:center"></span>
        </div>
    </div>

    <input type="file" name="<?= e($docField) ?>" id="<?= e($docField) ?>" accept=".pdf" hidden>
</section>

<style>
.reglamento-viewer { border:1px solid #d5deea; border-radius:12px; overflow:hidden; background:#f8fafc; }
.reglamento-frame { width:100%; height:min(420px,55vh); border:0; display:block; }
.signature-pad-wrap {
  border:2px dashed #cfd8e6; border-radius:12px; background:#fff; max-width:540px;
  touch-action: none;
}
#signature-canvas { display:block; width:100%; max-width:520px; height:160px; cursor:crosshair; }
</style>

<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script>
(function () {
  const templateUrl = <?= json_encode($reglamento['template_url'], JSON_UNESCAPED_UNICODE) ?>;
  const docInput = document.getElementById(<?= json_encode($docField) ?>);
  const canvas = document.getElementById('signature-canvas');
  const clearBtn = document.getElementById('signature-clear');
  const accepted = document.getElementById('reglamento_accepted');
  const statusEl = document.getElementById('signature-status');
  const form = document.getElementById('checkout-form');
  if (!canvas || !form || !docInput) return;

  const ctx = canvas.getContext('2d');
  let drawing = false;
  let hasStroke = false;
  let reglamentoAttached = false;

  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.strokeStyle = '#1a2744';
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';

  function pos(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const src = e.touches ? e.touches[0] : e;
    return {
      x: (src.clientX - rect.left) * scaleX,
      y: (src.clientY - rect.top) * scaleY,
    };
  }

  function start(e) {
    e.preventDefault();
    drawing = true;
    const p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }

  function move(e) {
    if (!drawing) return;
    e.preventDefault();
    const p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    hasStroke = true;
    statusEl.textContent = '';
  }

  function end() { drawing = false; }

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  canvas.addEventListener('mouseup', end);
  canvas.addEventListener('mouseleave', end);
  canvas.addEventListener('touchstart', start, { passive: false });
  canvas.addEventListener('touchmove', move, { passive: false });
  canvas.addEventListener('touchend', end);

  clearBtn.addEventListener('click', () => {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    hasStroke = false;
    reglamentoAttached = false;
    docInput.value = '';
    statusEl.textContent = 'Firma borrada';
  });

  function signerName() {
    const parts = ['first_name', 'last_name_p', 'last_name_m']
      .map(n => {
        const el = form.querySelector('[name="' + n + '"]');
        return el ? String(el.value || '').trim() : '';
      })
      .filter(Boolean);
    return parts.join(' ') || 'Aspirante';
  }

  async function buildSignedPdf() {
    if (!accepted.checked) {
      throw new Error('Debes aceptar el reglamento.');
    }
    if (!hasStroke) {
      throw new Error('Dibuja tu firma en el recuadro.');
    }
    if (typeof PDFLib === 'undefined') {
      throw new Error('No se pudo cargar el generador de PDF. Recarga la página.');
    }

    const res = await fetch(templateUrl);
    if (!res.ok) throw new Error('No se pudo cargar el PDF del reglamento.');
    const templateBytes = await res.arrayBuffer();

    const pdfDoc = await PDFLib.PDFDocument.load(templateBytes);
    const page = pdfDoc.addPage([612, 792]);
    const font = await pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica);
    const bold = await pdfDoc.embedFont(PDFLib.StandardFonts.HelveticaBold);
    const now = new Date();
    const fecha = now.toLocaleString('es-MX', { dateStyle: 'long', timeStyle: 'short' });

    page.drawText('Firma del aspirante', { x: 50, y: 720, size: 14, font: bold, color: PDFLib.rgb(0.1, 0.15, 0.27) });
    page.drawText('Nombre: ' + signerName(), { x: 50, y: 690, size: 11, font });
    page.drawText('Fecha y hora: ' + fecha, { x: 50, y: 670, size: 11, font });
    page.drawText('Declaro haber leído y aceptado el reglamento del examen.', { x: 50, y: 650, size: 10, font, color: PDFLib.rgb(0.35, 0.35, 0.35) });

    const pngData = canvas.toDataURL('image/png');
    const pngImage = await pdfDoc.embedPng(pngData);
    const pngDims = pngImage.scale(0.65);
    page.drawImage(pngImage, {
      x: 50,
      y: 480,
      width: pngDims.width,
      height: pngDims.height,
    });

    const bytes = await pdfDoc.save();
    return new File([bytes], 'reglamento-firmado.pdf', { type: 'application/pdf' });
  }

  form.addEventListener('submit', async function (e) {
    if (reglamentoAttached) return;
    e.preventDefault();
    statusEl.textContent = 'Generando PDF firmado…';
    try {
      const file = await buildSignedPdf();
      const dt = new DataTransfer();
      dt.items.add(file);
      docInput.files = dt.files;
      reglamentoAttached = true;
      statusEl.textContent = 'Reglamento firmado listo ✓';
      form.requestSubmit();
    } catch (err) {
      reglamentoAttached = false;
      statusEl.textContent = '';
      alert(err.message || 'No se pudo generar el reglamento firmado.');
    }
  });
})();
</script>

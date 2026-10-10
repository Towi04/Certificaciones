# Reglamento Cambridge / Linguaskill (relleno automático)

Plantilla: `/assets/reglamentos/linguaskill-terminos-condiciones.pdf`

## Cambridge vs UKS/ELeT

| | Cambridge / Linguaskill | UKS / ELeT |
|--|-------------------------|------------|
| Modo | `fill_acroform` | `append_to_pdf` |
| UX checkout | Lee PDF + resumen/iniciales + marca “acepto” (+ preview opcional) | Firma en canvas **o** sube PDF escaneado |
| PDF resultante | Campos AcroForm rellenados (`NOMBRE`, `FECHA`, `FIRMA O INICIALES`) | Misma plantilla + página final con firma |
| Plantilla | `linguaskill-terminos-condiciones.pdf` | `elet-reglamento.pdf` |
| Doc code (seed) | `reglamento_cambridge_flexible` / `_fixed` | `reglamento_firmado` |

Ver también: `docs/products/elet-uks.md` (firma canvas).

## Modo `fill_acroform`

En Admin → Grupos → **Reglamento**:

- Activar reglamento
- Modo: **Solo aceptación + relleno automático — Cambridge/Linguaskill**
- Ruta: `/assets/reglamentos/linguaskill-terminos-condiciones.pdf`
- (Opcional) aliases de campos AcroForm y checkbox **Aplanar campos**

En checkout el alumno:

1. Completa **Datos**
2. Lee el PDF en **Reglamento** (solo lectura en iframe)
3. Revisa el resumen: nombre, fecha e **iniciales** (editables)
4. Opcional: **Ver PDF con mis datos** (abre el PDF ya relleno en pestaña nueva)
5. Marca “He leído y acepto…”
6. Al continuar, pdf-lib rellena AcroForm, hace `flatten` y adjunta el PDF al caso

Fallback colapsado: descargar plantilla → firmar fuera → subir PDF (reemplaza el automático).

| Campo PDF | Origen |
|-----------|--------|
| `NOMBRE` | Nombre + apellidos del paso Datos |
| `FECHA` | Fecha del día (`es-MX`) |
| `FIRMA O INICIALES` | Iniciales tipográficas (derivadas del nombre; editables en checkout) |

No se pide firma manuscrita en canvas: ops aceptó iniciales tipográficas en el campo AcroForm.

## Activación en servidores existentes

```bash
php bin/ensure-cutover-config.php
```

Parchea `cambridge-flexible` y `cambridge-fixed` **solo si falta** `reglamento` (o completa claves anidadas). No pisa agendas ni docs ya editados. Si la plantilla ya es Linguaskill con modo `append_to_pdf`, lo actualiza a `fill_acroform`.

## Config JSON

```json
{
  "template_path": "/assets/reglamentos/linguaskill-terminos-condiciones.pdf",
  "signature_mode": "fill_acroform",
  "flatten": true,
  "form_fields": {
    "name": ["NOMBRE"],
    "date": ["FECHA"],
    "initials": ["FIRMA O INICIALES"]
  },
  "doc_code": "reglamento_cambridge_flexible",
  "required_before_checkout": true
}
```

## QA Fase 4

```bash
php bin/cambridge-reglamento-qa.php
```

Exit `0` = lógica/assets OK. Los pasos `MANUAL` (navegadores, iPad, Acrobat, correo real) se validan en staging con la checklist abajo.

### Checklist MANUAL (staging)

| # | Caso | Esperado |
|---|------|----------|
| M1 | Chrome desktop — Cambridge | Sin descargar/subir: check → PDF adjunto con nombre/fecha/iniciales |
| M2 | Safari desktop | Igual que M1; preview “Ver PDF con mis datos” abre pestaña |
| M3 | Firefox desktop | Igual que M1 |
| M4 | iPad mostrador | Prefill nombre/iniciales; touch en check; sin canvas obligatorio |
| M5 | Abrir PDF resultante (Acrobat / Preview) | Texto visible en campos (flatten); no campos vacíos |
| M6 | Correo/proveedor Cambridge | Adjunta doc `reglamento_cambridge_flexible` o `_fixed` según grupo |
| M7 | Regresión UKS `append_to_pdf` | Canvas o “Descargar y subir”; no UI de preview AcroForm |
| M8 | Fallback Cambridge | Subir PDF manual reemplaza automático; check sigue obligatorio |

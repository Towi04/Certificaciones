# Reglamento Cambridge / Linguaskill (relleno automático)

Plantilla: `/assets/reglamentos/linguaskill-terminos-condiciones.pdf`

## Modo `fill_acroform`

En Admin → Grupos → **Reglamento**:

- Activar reglamento
- Modo: **Solo aceptación + relleno automático — Cambridge/Linguaskill**
- Ruta: `/assets/reglamentos/linguaskill-terminos-condiciones.pdf`

En checkout el alumno:

1. Lee el PDF (solo lectura en iframe)
2. Revisa el resumen: nombre, fecha e **iniciales** (editables)
3. Opcional: **Ver PDF con mis datos** (abre el PDF ya relleno en pestaña nueva)
4. Marca “He leído y acepto…”
5. Al continuar, pdf-lib rellena AcroForm, hace `flatten` y adjunta el PDF al caso

Fallback colapsado: descargar plantilla → firmar fuera → subir PDF (reemplaza el automático).

| Campo PDF | Origen |
|-----------|--------|
| `NOMBRE` | Nombre + apellidos del paso Datos |
| `FECHA` | Fecha del día (`es-MX`) |
| `FIRMA O INICIALES` | Iniciales tipográficas (derivadas del nombre; editables en checkout) |

No se pide firma manuscrita en canvas: ops aceptó iniciales tipográficas en el campo AcroForm.

Opciones en `config_json.reglamento`:

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
  "doc_code": "reglamento_cambridge",
  "required_before_checkout": true
}
```

UKS/ELeT sigue usando `signature_mode: append_to_pdf` (canvas o subir PDF).

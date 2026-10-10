# Reglamento Cambridge / Linguaskill (relleno automático)

Plantilla: `/assets/reglamentos/linguaskill-terminos-condiciones.pdf`

## Cambridge vs UKS/ELeT

| | Cambridge / Linguaskill | UKS / ELeT |
|--|-------------------------|------------|
| Modo | `fill_acroform` | `append_to_pdf` |
| UX checkout | Lee PDF + marca “acepto” | Firma en canvas **o** sube PDF escaneado |
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
2. Lee el PDF en **Reglamento**
3. Marca “He leído y acepto…”
4. Al continuar, pdf-lib rellena AcroForm y adjunta el PDF al caso

| Campo PDF | Origen |
|-----------|--------|
| `NOMBRE` | Nombre + apellidos del paso Datos |
| `FECHA` | Fecha del día (`es-MX`) |
| `FIRMA O INICIALES` | Iniciales derivadas del nombre |

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

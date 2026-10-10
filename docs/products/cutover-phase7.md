# Fase 7 — Config real por grupo (UKS / Cambridge / CENNI)

Sin presets de UI (se configuran en el grupo). Esta fase deja defaults seguros
y plantillas con placeholders de docs.

## Qué hace

| Pieza | Detalle |
|-------|---------|
| UKS ELeT | INE antes de pagar + gate de aprobación (agenda online se mantiene hasta el cutover) |
| Cambridge flexible/fijo | INE antes de pagar + gate |
| CENNI | Docs post-pago: INE, CURP, solicitud, certificado/constancia + gate |
| Plantilla `uks_solicitud` | `{{doc_ine_url}}`, `{{student_docs_html}}`, sede |
| Plantilla `cenni_solicitud` | Lista de docs del trámite |
| Seed | **No pisa** `config_json` de grupos ya existentes |
| CLI | `php bin/ensure-cutover-config.php` — solo rellena claves faltantes |

## Cómo aplicar en producción

```bash
php bin/ensure-cutover-config.php
```

Revisa el log: solo añade lo que falte (docs vacíos, timing, gate, placeholders de correo).

Luego en Admin (manual, no lo hace el script):

1. **UKS** → Docs: confirma INE + gate; Reglamento sigue en su pestaña.
2. **Cambridge fijo** → sedes/convocatorias reales en Fechas.
3. **CENNI** → confirma los 4 docs; enlaza plantilla `cenni_solicitud` en el paso de progreso si aplica.
4. **El día del cutover UKS presencial**: cambia agenda a `venue_schedules` (ver `uks-presencial-cutover.md`).

## Fases del plan maestro

| Fase | Estado |
|------|--------|
| 0–6 + gate ops | Hechas |
| **7 Config real por grupo** | Hecha (#265) |
| **8 QA cutover** | `docs/products/cutover-phase8-qa.md` + `php bin/cutover-phase8-matrix.php` |

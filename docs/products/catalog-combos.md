# Combos en el catálogo

Los paquetes (`combos`) se gestionan en `/admin/combos`. Además del precio y los
ítems, tienen campos de presentación para la pestaña **Combos** del catálogo.

## Campos de contenido

| Campo | Uso |
|-------|-----|
| `short_description` | Resumen HTML de la tarjeta |
| `description` | Descripción HTML de la ficha |
| `logo_path` | Imagen propia (opcional) |
| `is_public` | Visible en catálogo (independiente de `is_active`) |
| `is_star` | Destacado en la sección Combos |

## Fallback (Fase 2+)

Si no hay imagen/textos propios, el catálogo usará la información de las
**certificaciones** incluidas en el combo.

## Migración

```sql
-- sql/migrations/20261010_combo_catalog_fields.sql
ALTER TABLE combos
  ADD COLUMN short_description TEXT NULL AFTER description,
  ADD COLUMN logo_path VARCHAR(255) NULL AFTER short_description,
  ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;
```

## Catálogo público

- Pestaña `/catalogo?seccion=combos`
- Ficha `/paquete/{slug}` (alias `/combo/{slug}`)
- CTA → `/adquirir/{cert-slug}?combo_id={id}` (preselecciona el paquete)
- Partner: misma pestaña en `/partner/registrar?seccion=combos`

## Estado del plan

| Fase | Estado |
|------|--------|
| 1 Schema + admin | Lista |
| 2 Tab catálogo + presenter | Lista |
| 3 Ficha `/paquete/{slug}` | Base en Fase 2 (pulido pendiente) |
| 4 Checkout CTA + QA | Parcial (deep-link `combo_id`) |

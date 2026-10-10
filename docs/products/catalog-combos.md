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

## Ficha `/paquete/{slug}`

- Layout alineado a producto (hero, precio, CTA, admin edit)
- Aside sticky con ahorro vs precios de lista
- Tarjetas de ítems con logo + enlace a `/producto/{slug}`
- SEO: `meta description`, `canonical`, `og:title` / `og:image`

## Estado del plan

| Fase | Estado |
|------|--------|
| 1 Schema + admin | Lista |
| 2 Tab catálogo + presenter | Lista |
| 3 Ficha `/paquete/{slug}` | Lista (pulido visual + SEO) |
| 4 Checkout CTA + QA | Lista (CLI lógica; checklist staging abajo) |

## QA Fase 4

### Automático (sin BD)

```bash
php bin/catalog-combos-qa.php
```

Valida rutas/UI, `ComboCatalogPresenter` (overrides vs fallback), ahorro/CTA,
columna partner y migración. Con `.env` + BD también cuenta combos públicos.

### Manual (staging)

- [ ] Pestaña **Combos** + contador en `/catalogo`
- [ ] Combo sin overrides → imagen/textos de certificaciones
- [ ] Combo con imagen/resumen/descripción propios los muestra
- [ ] Partner `/partner/registrar?seccion=combos` con precio de nivel
- [ ] CTA ficha → `/adquirir/{cert}?combo_id=` preselecciona el paquete
- [ ] Tabs responsive (mobile): Certificaciones | Cursos | Combos

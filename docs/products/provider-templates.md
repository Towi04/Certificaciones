# Plantillas proveedor (CSV descarga / Excel correo)

Plan Notion: *Plantillas proveedor (CSV/Excel) desacopladas*.

## Fase 0 (hecho) — descubrir el catálogo CSV

| Dónde | Qué |
|-------|-----|
| **Admin → Automatización → Plantillas proveedor** | `/admin/plantillas-csv` (alias `/admin/plantillas-proveedor`) |
| **Admin → Grupos → Progreso** | Paso «Descargar CSV» → elegir plantilla + alcance |
| **Operación** | Botón del paso descarga el CSV |

## Fase 1 (hecho) — Catálogo CSV por proveedor

- Lista y formulario con **etiqueta Proveedor** (`supplier_id`)
- Seed: `uks_elet_registro` + ejemplo `cambridge_registro` (insert-only; no pisa custom)
- En Progreso el selector muestra `Proveedor · Nombre (código)` y default vacío (Cambridge no hereda UKS)
- Enlace: **Administrar plantillas proveedor**

## Fase 1b — Alcances y anti-duplicados (hecho)

En **Operación**, al pulsar el botón CSV se abre un modal:

| Alcance | Qué incluye |
|---------|-------------|
| Solo este alumno | El caso del botón (siempre, aunque tenga folio) |
| Pendientes del mismo examen | Mismo `product_id`, excluyendo registrados |
| Misma fecha + mismo examen | Lote del día de esa certificación |
| Misma fecha | Misma fecha (filtros de la plantilla) |
| Mismo examen | Misma certificación, cualquier fecha |

**Exclusión por defecto** (lotes / pendientes / exportaciones):

1. Ya descargado en esa plantilla/paso (`csv_downloads`)
2. **Folio** no vacío (= registrado; la clave no se exige)
3. Asistencia **present** (ya presentó)

Override: checkbox **Incluir ya registrados**.

Tras descargar un lote se marca `csv_downloads` en **todos** los tracking del archivo.

API resumen: `GET /admin/plantillas-csv/{code}/resumen?...`

## Fase 1c — Paquetes / combos multi-cert (hecho)

Si el lote incluye el mismo `student_user_id` en **≥2** trackings (paquete con varias certificaciones), el modal muestra:

| Opción | Efecto |
|--------|--------|
| **Repetir el nombre** (default) | Una fila por certificación; se añade columna «Certificación» (`product_name`) si la plantilla no la trae |
| **Una sola fila** | Se queda el caso del botón (o el primero del grupo); solo esos IDs se marcan como descargados |

La elección se recuerda en `localStorage` (`doceo-csv-combo-mode`). Parámetro: `combo_mode=repeat|single` en descarga y resumen.

## Fase 2 (hecho) — Excel en el catálogo + migración

| Qué | Dónde |
|-----|-------|
| Catálogo Excel | **Plantillas proveedor → Excel** (`?tipo=xlsx`) |
| Crear/editar | upload `.xlsx` + mapa de celdas + proveedor |
| Correo | selector «Plantilla del catálogo»; legacy colapsado (no se borra) |
| Migración | `php bin/migrate-provider-workbooks.php` (también en `ensure-cutover-config`) |

Códigos migrados: `xlsx_mail_{mail_code}`, `xlsx_group_{group_code}`.
Se escribe `workbook_template_code` en Settings/grupo **sin DELETE** del JSON legacy.

Resolución al enviar: catálogo (grupo → mail) → legacy mail → legacy grupo.

Seed CSV UKS: upsert **no destructivo** (no pisa `mapping_json` custom).

## Fase 3 (hecho) — Correo solo placeholder

| Qué | Detalle |
|-----|---------|
| Camino feliz | En plantilla de correo: elegir Excel del catálogo + `{{workbook_url}}` en el HTML |
| Opcional | `{{workbook:codigo}}` apunta a una plantilla concreta del catálogo |
| Legacy | Bloque colapsado «Respaldo legacy» — no se borra hasta QA TOEFL |
| Error claro | Si el HTML pide Excel y no hay catálogo ni legacy → mensaje con link a `/admin/plantillas-csv?tipo=xlsx` |

## Fase 4 — QA / cutover

### Checklist automático

```bash
php bin/provider-templates-qa.php
php bin/migrate-provider-workbooks.php   # staging/prod
php bin/ensure-cutover-config.php        # incluye migración workbooks
```

Exit `0` = lógica OK (quedan pasos MANUAL en el output).

### Checklist MANUAL staging

| # | Prueba | OK |
|---|--------|----|
| 1 | Menú **Automatización → Plantillas proveedor** (+ alias `/admin/plantillas-proveedor`) | ☐ |
| 2 | **Cambridge**: Progreso elige `cambridge_registro` (no UKS); descarga con alcances | ☐ |
| 3 | **UKS**: uno-a-uno + pendientes; 2ª descarga masiva vacía; folio/present excluidos | ☐ |
| 4 | **Combo** 2 certs: repetir nombre vs una fila | ☐ |
| 5 | **TOEFL**: tras migración, un envío = mismas celdas/archivo que antes | ☐ |
| 6 | Re-seed / ensure-cutover **no** pisa mapping UKS custom ni borra `mail_tpl_*_workbook` | ☐ |

## Relacionado

- UKS export/import: `docs/products/elet-uks.md`
- Exportaciones / import reporte: `/admin/exportaciones`

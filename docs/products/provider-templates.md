# Plantillas proveedor (CSV descarga / Excel correo)

Plan Notion: *Plantillas proveedor (CSV/Excel) desacopladas*.

## Fase 0 (hecho) — descubrir el catálogo CSV

| Dónde | Qué |
|-------|-----|
| **Admin → Configuración → Plantillas CSV** | `/admin/plantillas-csv` — crear/editar plantillas (UKS, Cambridge, …) |
| **Admin → Grupos → Progreso** | Paso con acción «Descargar CSV (plantilla proveedor)» → elegir plantilla + alcance |
| **Operación** | Botón del paso descarga el CSV |

El seed trae `uks_elet_registro`. Para Cambridge (u otro proveedor) crea otra plantilla en **Plantillas CSV** y selecciónala en el grupo correspondiente (no uses la de UKS).

Enlace directo desde el selector del progreso: **Administrar plantillas CSV**.

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

## Excel en correo (TOEFL, etc.) — aún en plantilla de correo

Hoy el `.xlsx` de TOEFL (y similares) vive en **Plantillas correo** → “Plantilla Excel” / `mail_tpl_{code}_workbook`, o en `provider_request.workbook` del grupo.

Fases 2–3 del plan: moverlos al mismo catálogo **sin borrar** lo ya configurado en BD.

## Relacionado

- UKS export/import: `docs/products/elet-uks.md`
- Exportaciones / import reporte: `/admin/exportaciones`

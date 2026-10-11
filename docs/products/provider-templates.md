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

## Decisiones ops (exclusión en lotes — Fase 1b+)

Al armar “pendientes” / lotes masivos se excluirá por defecto a quien:

1. **Ya se descargó** en esa plantilla/paso (`csv_downloads`), o
2. Tiene **folio** no vacío (= ya registrado en el proveedor; la clave no es necesaria para excluir), o
3. **Ya presentó** el examen (asistencia `present`)

Override explícito “incluir ya registrados” queda para Fase 1b.

## Excel en correo (TOEFL, etc.) — aún en plantilla de correo

Hoy el `.xlsx` de TOEFL (y similares) vive en **Plantillas correo** → “Plantilla Excel” / `mail_tpl_{code}_workbook`, o en `provider_request.workbook` del grupo.

Fases 2–3 del plan: moverlos al mismo catálogo **sin borrar** lo ya configurado en BD.

## Relacionado

- UKS export/import: `docs/products/elet-uks.md`
- Exportaciones / import reporte: `/admin/exportaciones`

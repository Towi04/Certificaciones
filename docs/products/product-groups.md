# Grupos de producto (proceso compartido por proveedor)

Los **grupos de producto** (`product_groups`) concentran el proceso de compra/adquisición
compartido por certificaciones del mismo proveedor. Cada producto hijo solo necesita
personalizar **contenido** (nombre, descripción, logo, galería, precios).

## Cómo se resuelve la configuración

```
product_groups.config_json   ← reglas del proceso (pagos, MSI, fechas, reglamento, pipeline…)
        +
products.config_json         ← overrides opcionales del SKU
        =
CheckoutRequirements::config($product)
```

El merge es profundo: las claves del producto pisan las del grupo. Las listas
(indexadas) se reemplazan completas.

## Qué se configura desde Admin (sin tocar código)

En **Admin → Grupos → Editar** puedes ajustar con formularios:

| Área | Campos |
|------|--------|
| Horarios de aplicación | Pedir fecha/hora en checkout, minutos por bloque, anticipo, Lun–Vie, sábado |
| Fechas bloqueadas | Vacaciones/cierres (`YYYY-MM-DD`, una por línea). Solo afectan a grupos que piden agenda |
| Reglamento | Activar/desactivar, ruta o URL de la plantilla PDF, link externo, código de documento |

Los grupos que deben estar “siempre disponibles” dejan desmarcada la opción de agenda
en checkout; las fechas bloqueadas no les aplican.

## Grupos sembrados

| Código | Uso |
|--------|-----|
| `uks-elet` | ELeT: pagos, MSI 1/3/6/9/12, examen, horario, reglamento, pipeline |
| `uks-elet-cenni` | Trámite CENNI post-examen ELeT |
| `itep-exams` | iTEP / OOPT — mismos pagos/MSI que ELeT |
| `linguafranca-exams` | TOEFL ITP / Junior — mismos pagos/MSI |
| `etc-certs` | Certificaciones IT |
| `doceo-procedures` | Trámites DOCEO |
| `doceo-courses` | Cursos Moodle (MSI desactivado) |

## Cómo agregar un producto nuevo sin empezar de cero

1. Crea o elige el **proveedor** en Admin → Proveedores.
2. Desde el proveedor (o en Grupos) crea el **grupo de proceso** (horarios, reglamento, pagos).
3. Opcional: sube un CSV de certificaciones desde la ficha del proveedor.
4. Afina cada producto en Admin → Productos (descripción, logo, galería).
5. Ajusta precios en lote en Admin → Precios (tabla o CSV).

## Precios masivos

| Acción | Ruta |
|--------|------|
| Tabla editable | `/admin/precios` |
| Plantilla CSV | `/admin/precios/plantilla.csv` |
| Importar CSV | POST `/admin/precios/import` |

Columnas CSV: `code,name,public_price,catalog_price,cost_price,price_cncm,price_partner_a,price_partner_b,price_partner_c`.

La edición de precios por producto individual se mantiene.

## Proveedores

| Acción | Ruta |
|--------|------|
| Listado / alta / edición | `/admin/proveedores` |
| Alta masiva de certificaciones | ficha del proveedor + CSV |
| Plantilla de certificaciones | `/admin/proveedores/{id}/plantilla-certificaciones.csv` |

## Pagos unificados

Todos los grupos de certificación/trámite heredan las mismas condiciones de cobro que ELeT:

- Transferencia (CLABE + comprobante)
- OXXO (tarjeta de depósito + comprobante)
- TDC OpenPay (meses 1, 3, 6, 9, 12; `min_amount` 0)

Tras desplegar, entra a **Admin → Grupos** y pulsa **Cargar grupos sugeridos**
(o vuelve a ejecutar el seed de catálogo) para crear los grupos y poder asignarlos
a cada producto.

## Admin (mapa rápido)

| Pantalla | Ruta | Para qué |
|----------|------|----------|
| Grupos de proceso | `/admin/grupos` | Horarios, fechas bloqueadas, reglamento, pagos/MSI |
| Precios masivos | `/admin/precios` | Subir costos/lista/partners en lote (+ CSV) |
| Proveedores | `/admin/proveedores` | CRUD + carga masiva de certificaciones |
| Productos | `/admin/productos` | Detalle, media y precios individuales |

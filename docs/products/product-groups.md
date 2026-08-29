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

1. Elige (o crea) el grupo del proveedor con el proceso deseado.
2. Crea el producto con `product_group_id` apuntando a ese grupo.
3. Deja `products.config_json` vacío (`{}`) salvo overrides puntuales.
4. Sube logo/galería y edita descripción/precios en admin.

En admin: **Productos → Editar → Grupo de proceso (proveedor)**.

## Pagos unificados

Todos los grupos de certificación/trámite heredan las mismas condiciones de cobro que ELeT:

- Transferencia (CLABE + comprobante)
- OXXO (tarjeta de depósito + comprobante)
- TDC OpenPay (meses 1, 3, 6, 9, 12; `min_amount` 0)

Tras desplegar, entra a **Admin → Grupos** y pulsa **Cargar grupos sugeridos**
(o vuelve a ejecutar el seed de catálogo) para crear los grupos y poder asignarlos
a cada producto. También puedes crear grupos nuevos desde esa misma pantalla.

## Admin

| Pantalla | Ruta | Para qué |
|----------|------|----------|
| Grupos de proceso | `/admin/grupos` | Crear/editar grupos y cargar sugeridos |
| Nuevo grupo | `/admin/grupos/nuevo` | Alta manual de un proceso de proveedor |
| Productos | `/admin/productos` | Listado |
| Nuevo producto | `/admin/productos/nuevo` | Alta (código, nombre, precios, grupo…) |
| Editar producto | `/admin/productos/{id}` | Cambiar código/nombre/grupo/precios + media |


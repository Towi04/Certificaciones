# Documentos del alumno (checkout / portal)

Configuración en **Admin → Grupos → Docs → Documentos del alumno**.

Esta pestaña es **solo** para archivos que el alumno/partner debe subir
(INE, CURP, solicitud, constancia, etc.). Tú defines código, etiqueta y descripción.

## Timing

| Modo | Clave JSON | Uso |
|------|------------|-----|
| Antes de pagar | `student_docs_timing: before_payment` + `required_docs` | Sin el archivo no confirma el registro/pago |
| Después de pagar | `student_docs_timing: after_payment` + `registration_docs` | Paga primero, sube en el portal (p. ej. CENNI) |

El check del formulario escribe la misma lista en el bucket correcto.

## Campos por documento

- `code` → placeholders:
  - `{{doc_<code>_url}}` → **URL firmada** (va en el `href` del enlace; alias `{{ine_url}}`)
  - `{{doc_<code>_label}}` → **texto visible** del enlace (no usar como `href`)
  - Ejemplo correcto: `<a href="{{doc_ine_url}}">{{doc_ine_label}}</a>`
- `label`, `description`, `accept`, `required`
- `include_in_provider_mail` → entra en `documentos_html` / `student_docs_html`
- `require_for_provider_send` → el envío a proveedor falla si no hay versión **aprobada**

## Gate de aprobación

Check en Docs: **Bloquear envío a proveedor hasta que los docs obligatorios estén aprobados**
(`student_docs_gate.block_until_approved`).

- Por defecto se activa si el grupo tiene al menos un doc `required`.
- Con gate activo, `ProviderRequestService::send` falla si falta algún required en `approved`.
- En el caso admin: contador `X / Y` obligatorios aprobados + checklist.
- En Operaciones: pestaña **Docs por revisar** (documentos `pending`).
- En el portal del alumno: banner si el gate está activo y aún no están listos.

Política UKS/Cambridge (docs en checkout): el alumno elige sede/fecha al registrarse;
ops no envía a proveedor hasta aprobar el INE (u otros required).

## Flujo

1. Alumno sube (checkout o portal) → status `pending`
2. Admin revisa en **Operación** (popup de documentos): marca aprobar/rechazar en lote y guarda
3. Si hay rechazos → **un solo correo** `student_document_rejected` con `{{rejection_list_html}}` + re-subida en portal
4. Correos a proveedor usan enlaces firmados (`SignedFileLinkService`) y exigen docs/reglamento **approved**

## Reglamento firmado

| Origen | Status inicial |
|--------|----------------|
| Firma en sistema (canvas / AcroForm) | `approved` (no requiere revisión) |
| Subida manual del PDF escaneado | `pending` (ops debe aprobar) |

Si el reglamento subido a mano queda `pending`/`rejected`, no se envía la solicitud al proveedor hasta aprobarlo. El alumno ve el motivo y puede re-subir desde su portal.

## Distinción con reglamento

El reglamento **no** se configura aquí.

- Pestaña **Reglamento**: link/plantilla PDF + paso de checkout
  (firma digital en pantalla **o** descargar / firmar en papel / subir PDF escaneado).
- Placeholders: `{{reglamento_url}}` (y el código `doc_code` del reglamento).
- Códigos reservados (`reglamento`, `signature`, `reglamento_firmado`, …) se ignoran
  si alguien los pone en Documentos del alumno.

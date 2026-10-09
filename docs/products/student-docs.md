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

- `code` → placeholder `{{doc_<code>_url}}` / `{{doc_<code>_label}}` (alias `{{ine_url}}`)
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
2. Admin aprueba / rechaza en seguimiento
3. Rechazo → plantilla `student_document_rejected` + re-subida en portal
4. Correos a proveedor usan enlaces firmados (`SignedFileLinkService`)

## Distinción con reglamento

El reglamento **no** se configura aquí.

- Pestaña **Reglamento**: link/plantilla PDF + paso de checkout
  (firma digital en pantalla **o** descargar / firmar en papel / subir PDF escaneado).
- Placeholders: `{{reglamento_url}}` (y el código `doc_code` del reglamento).
- Códigos reservados (`reglamento`, `signature`, `reglamento_firmado`, …) se ignoran
  si alguien los pone en Documentos del alumno.

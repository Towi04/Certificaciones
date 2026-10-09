# Documentos del alumno (checkout / portal)

Configuración en **Admin → Grupos → Docs → Documentos del alumno**.

## Timing

| Modo | Clave JSON | Uso |
|------|------------|-----|
| Antes de pagar | `student_docs_timing: before_payment` + `required_docs` | UKS, Cambridge: sin archivo no confirma el registro/pago |
| Después de pagar | `student_docs_timing: after_payment` + `registration_docs` | CENNI: paga primero, sube en el portal |

El check del formulario escribe la misma lista en el bucket correcto.

## Campos por documento

- `code` → placeholder `{{doc_<code>_url}}` / `{{doc_<code>_label}}` (alias `{{ine_url}}`)
- `label`, `description`, `accept`, `required`
- `include_in_provider_mail` → entra en `documentos_html` / `student_docs_html`
- `require_for_provider_send` → el envío a proveedor falla si no hay versión **aprobada**

## Flujo

1. Alumno sube (checkout o portal) → status `pending`
2. Admin aprueba / rechaza en seguimiento
3. Rechazo → plantilla `student_document_rejected` + re-subida en portal
4. Correos a proveedor usan enlaces firmados (`SignedFileLinkService`), igual que `{{reglamento_url}}`

## Distinción con reglamento

La pestaña **Reglamento** es firma digital en checkout (`reglamento` + `{{reglamento_url}}`).
No sustituye la subida de INE u otros PDFs del alumno.

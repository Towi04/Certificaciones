# Moodle — campus.institutodoceo.com

## Endpoint

`https://campus.institutodoceo.com/webservice/rest/server.php`

## Servicio externo (checklist)

1. **Administración del sitio → Servidor → Servicios web → Descripción general**  
   - Protocolos activos: REST  
   - Servicios web habilitados

2. **Servicios externos** → crea/edita el servicio del PDV (p. ej. `PDV Doceo`):
   - Habilitado
   - Autorizado solo usuarios concretos (el usuario del token)
   - **Funciones** (todas obligatorias):
     - `core_webservice_get_site_info` (diagnóstico en `/admin/salud`)
     - `core_course_get_courses`
     - `core_user_get_users_by_field`
     - `core_user_create_users`
     - `core_user_update_users` (recomendado: fijar contraseña si Moodle la genera)
     - `enrol_manual_enrol_users` (con `timestart` / `timeend` / `suspend` para 6 meses y prórrogas)

3. **Usuario del token** (cuenta de servicio, no un alumno):
   - Rol con capacidades en **contexto sistema** (o al menos en la categoría/cursos de prep):
     - `webservice/rest:use`
     - `moodle/user:viewdetails`
     - `moodle/user:viewalldetails` (si Moodle lo exige en tu versión)
     - `moodle/user:create`
     - `moodle/user:update` (recomendado)
     - `moodle/course:view`
     - `moodle/course:viewhiddencourses` (si el curso prep está oculto)
     - `enrol/manual:enrol`
     - `enrol/manual:manage` (recomendado para suspender vencidos)
   - Autorizado en el servicio externo
   - Generar **token** para ese usuario + ese servicio → pegarlo en `.env` como `MOODLE_TOKEN`

4. En cada curso de preparación: método de matrícula **Manual** habilitado.

Opcional en `.env`:

```env
# Solo si el pack de idioma está instalado (es / es_mx). Si se omite, el PDV no envía lang.
# MOODLE_USER_LANG=es_mx
```

## Error `accessexception` / “Excepción al control de acceso”

El inventario del PDV **sí** puede asignarse aunque Moodle falle: son caminos independientes tras el pago.

Causa habitual: el token solo tiene `core_course_get_courses` (Salud en verde “cursos visibles”) pero **no** puede `core_user_create_users` o `enrol_manual_enrol_users`.

Qué hacer:
1. Abre `/admin/salud` — Moodle ahora lista qué funciones faltan al token.
2. En Moodle, agrega las funciones faltantes al servicio y/o capacidades al usuario.
3. En el caso del alumno: **Sincronizar Moodle** (no hace falta reasignar el código de inventario).

## Error `invalidparameter` al crear usuario

Causas frecuentes:
1. **Idioma** — el PDV prueba `en` / `es` / `es_mx` / sin lang (y `MOODLE_USER_LANG` si lo defines).
2. **Política de contraseñas** — evita palabras de diccionario; genera claves aleatorias fuertes.
3. **Username** — solo `a-z0-9_` (sin puntos).
4. **Campos de perfil obligatorios** — hazlos opcionales o pon default en Moodle.
5. Último recurso del PDV: `createpassword=1` + `core_user_update_users` (agrega también esa función al servicio).

Para ver el campo exacto: Moodle → Desarrollo → Mensajes de depuración = **DEVELOPER**, reproduce, y mira `debuginfo`. Luego vuelve a “Ninguno”.

Tras corregir: **Sincronizar Moodle** en el caso.

## Contraseña estándar

Por defecto el PDV usa **`Doceo*1234`** (`MOODLE_DEFAULT_PASSWORD` en `.env`):

- Al **Sincronizar Moodle** crea o actualiza el usuario con esa clave y rellena `moodle_user` / `moodle_password` en el caso.
- Si el usuario ya existía, también **restablece** la clave a esa estándar.
- Activa `auth_forcepasswordchange` para que el alumno la cambie al entrar.
- Botón admin: **Restablecer password Moodle a Doceo*1234** (+ correo opcional).

Asegúrate de que la política de contraseñas de Moodle acepte esa clave, y que el servicio tenga `core_user_update_users`.

## Alta automática tras el pago

Cuando OpenPay confirma el pago (o el admin usa “Confirmar pago” / “Marcar pago”):

1. Busca cursos ligados a la certificación (`certification_courses`) con `platform_type=moodle` y `moodle_course_id`.
2. Si el alumno no tiene usuario Moodle (por e-mail), lo crea y guarda `moodle_user` / `moodle_password` en el caso.
3. Si ya existe, solo lo matricula en el/los cursos **con vigencia** (`access_months` del curso, default 6).
4. Si existe la plantilla `moodle_acceso`, envía el correo con las credenciales.

También puedes forzar la sync desde el caso: **Sincronizar Moodle**.

## Prórroga (+6 meses)

- En Admin → Cursos configura **Costo prórroga**.
- El alumno puede pagar SPEI o subir comprobante; admin confirma en el caso / Pendientes.
- Cron recomendado para suspender vencidos: `php bin/moodle-expire-enrolments.php`

Migración: `sql/migration_course_prorroga.sql`.  
Migración de plantilla: `sql/migration_mail_moodle_acceso.sql`.

## Seguridad

- El token vive solo en `.env` (`MOODLE_TOKEN`), nunca en Git.
- Retirar `test_moodle.php` del servidor tras validar `/admin/salud`.

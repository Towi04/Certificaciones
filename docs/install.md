# Instalación en Neubox (sin SSH / sin bash)

## 1) Variables en `.env` del servidor

Además de DB y admin, agrega:

```env
INSTALL_KEY=elige-una-clave-larga-secreta
APP_KEY=otra-clave-aleatoria-para-cifrar
ADMIN_EMAIL=tu@correo.com
ADMIN_PASSWORD=tu-password-admin
```

## 2) Instalar por navegador (no uses `bin/*.php`)

Los archivos en `bin/` son solo para CLI. En hosting compartido **no se ejecutan** al abrirlos en el explorador.

Usa el instalador web (cualquiera de estas URLs, con el valor de **INSTALL_KEY**, no APP_KEY):

```
https://pdv.institutodoceo.com/setup?key=TU_INSTALL_KEY
https://pdv.institutodoceo.com/setup.php?key=TU_INSTALL_KEY
```

La ruta `/setup` pasa por la app (recomendadasi `/setup.php` “no hace nada”).
La página muestra un bloque **Diagnóstico** si falta la key o no coincide.

Luego entra a `/login` y al catálogo `/`.

## 3) Deploy automático

`upload_version.php` **no se dispara solo** al hacer merge. Hay que llamarlo.

Opciones:

1. **Manual:** abre  
   `https://pdv.institutodoceo.com/upload_version.php?key=TU_CLAVE_DEPLOY`
2. **Automático (recomendado):** este repo incluye  
   `.github/workflows/deploy.yml`  
   En GitHub → Settings → Secrets → Actions crea:
   - `DEPLOY_URL` = `https://pdv.institutodoceo.com/upload_version.php`
   - `DEPLOY_KEY` = la misma `secret_key` de tu `upload_version.php`

   Cada push/merge a `main` llamará al script de Neubox.

## 4) Seguridad

- No subas `upload_version.php` ni `.env` a Git (ya están en `.gitignore` / plantilla).
- Tras instalar, puedes borrar `setup.php` del servidor; el próximo deploy lo volverá a traer, pero sin `INSTALL_KEY` correcta no hace nada.

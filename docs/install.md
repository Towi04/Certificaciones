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

## Estructura en Neubox (importante)

El deploy deja el repo completo. Si el subdominio apunta a la **raíz** (no a `/public`),
deben existir en esa raíz:

- `index.php` (app real, no la página “En construcción”)
- `setup.php`
- `bootstrap.php`, `src/`, `routes/`, `views/`, `sql/`, `assets/`…

Si aún ves “🚧 En construcción”, el servidor tiene un `index.php` viejo:
vuelve a desplegar para que se sobrescriba, luego abre:

```
https://pdv.institutodoceo.com/setup.php?key=TU_INSTALL_KEY
```

(usa `/setup.php` con `.php`; si usas solo `/setup` necesitas el `index.php` nuevo).

La página debe mostrar “Instituto DOCEO · instalador web”, no el logo con badge amarillo.

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

# Instalación rápida (Neubox)

1. Copia `env.example` → `.env` y completa DB, admin, OpenPay, SMTP, Moodle, APP_KEY.
2. Apunta el subdominio a `public/` **o** deja `index.php` + `assets/` en la raíz (como ya lo tienes).
3. En SSH o terminal PHP del hosting:

```bash
php bin/install.php
php bin/seed-catalog.php
```

4. Entra a `/login` con `ADMIN_EMAIL` / `ADMIN_PASSWORD`.
5. Revisa `/admin/salud` y el catálogo en `/`.

El deploy con `upload_version.php` **no** borra `.env` ni `upload_version.php`.

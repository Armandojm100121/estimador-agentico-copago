# Despliegue y variables de entorno (#18)

Guía de despliegue en **Railway** y referencia de todas las variables de entorno.
El mismo código corre en local (XAMPP) y en producción; lo único que cambia son
las variables/credenciales, que **nunca** van en el código ni en Git.

## Dónde se configuran las credenciales

| Entorno | Mecanismo | Archivo/lugar |
|---------|-----------|---------------|
| Local (XAMPP) | Archivos `*.local.php` (en `.gitignore`) | `config.local.php`, `db.local.php`, `mail.local.php` |
| Producción (Railway) | Variables de entorno | Panel de Railway → pestaña **Variables** |

El código lee **primero** las variables de entorno y, si no existen, cae a los
archivos locales. Así el mismo archivo sirve en los dos entornos.

## Variables de entorno (referencia completa)

| Variable | Obligatoria | Para qué sirve | Se lee en |
|----------|:-----------:|----------------|-----------|
| `GROQ_API_KEY` | Sí | API key de Groq (motor de IA) | `config.php` |
| `DB_HOST` | Sí | Host de MySQL | `db.php` |
| `DB_NAME` | Sí | Nombre de la base de datos | `db.php` |
| `DB_USER` | Sí | Usuario de MySQL | `db.php` |
| `DB_PASS` | Sí | Contraseña de MySQL | `db.php` |
| `GMAIL_USER` | No* | Gmail que envía los correos | `config.php` |
| `GMAIL_APP_PASSWORD` | No* | Contraseña de aplicación de Google | `config.php` |
| `MAIL_FROM_NAME` | No | Nombre que aparece como remitente | `config.php` |
| `APP_URL` | No | URL pública (enlaces de los correos) | `config.php` |
| `PORT` | Auto | Puerto del servidor (lo inyecta Railway) | `Dockerfile` |

\* Si no defines las de correo, la app funciona en **modo demo** (muestra el enlace
de recuperación/verificación en pantalla en vez de enviarlo). Ver `CORREO.md`.

## Pasos para desplegar en Railway

1. **Base de datos:** agrega el plugin **MySQL** en tu proyecto de Railway.
   Copia sus credenciales a `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
2. **Variables:** en el servicio web, pestaña **Variables**, agrega todas las de la
   tabla de arriba (usa `.env.example` como plantilla).
3. **Deploy:** haz `git push` a `main`. Railway construye con el `Dockerfile`
   (`php -S [::]:${PORT}`) y publica automáticamente.
4. **Instalar la BD:** entra una vez a `https://tu-app.up.railway.app/setup.php`
   para crear tablas/columnas y los datos base (es idempotente).
5. **Verifica:** abre `login.php`, regístrate y prueba una consulta.

## Seguridad
- `config.local.php`, `db.local.php` y `mail.local.php` están en `.gitignore`:
  las credenciales reales nunca llegan al repositorio.
- En Railway, las variables de entorno quedan fuera del código y del control de versiones.
- Tras instalar, puedes borrar `setup.php` en producción por precaución.

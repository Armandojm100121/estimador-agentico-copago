# Correo transaccional — Recuperación y verificación (#15)

Este módulo envía los correos de **recuperación de contraseña** y **verificación de
correo** usando **SMTP de Gmail**, sin librerías externas (cliente SMTP propio en
`mailer.php`).

## Arquitectura (híbrido, configurable por entorno)

El envío está **desacoplado**: el mismo código funciona en local y en producción.

```
¿Hay credenciales de Gmail (GMAIL_USER + GMAIL_APP_PASSWORD)?
   Sí  -> envía el correo real por SMTP
   No  -> "modo demo": muestra el enlace en pantalla
```

| Entorno | Comportamiento | Motivo |
|---------|----------------|--------|
| Local (XAMPP) sin credenciales | Muestra el enlace en pantalla | Desarrollar/demostrar sin internet |
| Local con `mail.local.php` | Envía correo real | Probar el envío real en tu PC |
| Railway (producción) con variables | Envía correo real | Producto real, a cualquier usuario |

## Flujo de seguridad (defendible en la tesis)

- Token aleatorio de **256 bits** (`random_bytes`) → imposible de adivinar.
- En la BD se guarda solo el **SHA-256** del token (tabla `tokens_correo`), nunca en claro.
- **Expira** (recuperación: 30 min; verificación: 24 h) y es de **un solo uso**.
- Al pedir recuperación, la respuesta es **neutra** ("si el correo existe, te enviamos…")
  para no revelar qué correos están registrados.
- **Rate-limit** básico: 1 solicitud cada 60 s por sesión.
- Credenciales **fuera del código**, en variables de entorno / `mail.local.php` (en `.gitignore`).

## Cómo activar el envío real

### Paso 1 — Contraseña de aplicación de Google (una sola vez)
1. Activa la **verificación en 2 pasos** en tu cuenta Google:
   https://myaccount.google.com/security
2. Entra a **Contraseñas de aplicaciones**:
   https://myaccount.google.com/apppasswords
3. Crea una para "Correo" → Google te da una clave de **16 caracteres** (sin espacios).
   Esa es tu `GMAIL_APP_PASSWORD` (NO es tu contraseña normal de Gmail).

### Paso 2a — Probar en local (opcional)
Crea el archivo `mail.local.php` (ya está en `.gitignore`):
```php
<?php
return [
    'user' => 'tucorreo@gmail.com',
    'pass' => 'los16caracteres',   // contraseña de aplicación, sin espacios
];
```

### Paso 2b — Producción (Railway)
En tu servicio de Railway → pestaña **Variables**, agrega:

| Variable | Valor |
|----------|-------|
| `GMAIL_USER` | tucorreo@gmail.com |
| `GMAIL_APP_PASSWORD` | los 16 caracteres |
| `MAIL_FROM_NAME` | Estimador Copago |
| `APP_URL` | https://tu-app.up.railway.app |

`APP_URL` asegura que los enlaces de los correos apunten al dominio correcto
(si se omite, se deduce del request).

## Archivos del módulo

| Archivo | Rol |
|---------|-----|
| `mailer.php` | Cliente SMTP de Gmail + plantillas de correo (híbrido) |
| `tokens.php` | Crear/validar tokens (recuperación y verificación) |
| `forgot.php` | Pedir recuperación de contraseña |
| `reset.php` | Poner nueva contraseña con el token |
| `verificar.php` | Confirmar propiedad del correo |
| `tokens_correo` (tabla) | Tokens hasheados, con tipo/expiración/uso |
| `usuarios.email_verificado` | Marca si el correo fue confirmado |

## Notas
- **Límite de Gmail:** ~500 correos/día en cuentas normales (más que suficiente para tesis).
- El correo puede caer en **spam** la primera vez; márcalo como "no es spam".
- Modo de verificación: **suave** (el usuario entra igual, solo ve un aviso hasta confirmar).

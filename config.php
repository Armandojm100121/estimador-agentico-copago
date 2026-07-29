<?php
// config.php  -  Configuración del LLM (seguro para subir a Git)
// Usamos Groq (API compatible con OpenAI). La key se lee desde:
//   1) variable de entorno GROQ_API_KEY    (producción / despliegue)
//   2) config.local.php                     (desarrollo en XAMPP, va en .gitignore)

$key = getenv('GROQ_API_KEY');
if (!$key && file_exists(__DIR__ . '/config.local.php')) {
    $key = require __DIR__ . '/config.local.php';
}

define('OPENAI_API_KEY', $key ?: '');                  // nombre histórico — guarda la key de Groq
define('OPENAI_MODEL', 'llama-3.3-70b-versatile');     // modelo de Groq con soporte de tool calling

// ---------------------------------------------------------------------------
// Correo transaccional (recuperación de contraseña / verificación) — #15
// Envío por SMTP de Gmail. Las credenciales se leen en este orden:
//   1) variables de entorno GMAIL_USER / GMAIL_APP_PASSWORD  (producción / Railway)
//   2) mail.local.php  -> ['user' => ..., 'pass' => ...]      (desarrollo, en .gitignore)
// Si NO hay credenciales, el sistema entra en "modo demo": no envía correo,
// sino que muestra el enlace en pantalla (útil para desarrollar sin internet).
// GMAIL_APP_PASSWORD es una "contraseña de aplicación" de Google, NO la normal.
// ---------------------------------------------------------------------------
$mailUser = getenv('GMAIL_USER') ?: '';
$mailPass = getenv('GMAIL_APP_PASSWORD') ?: '';
if ((!$mailUser || !$mailPass) && file_exists(__DIR__ . '/mail.local.php')) {
    $ml = require __DIR__ . '/mail.local.php';
    if (is_array($ml)) {
        $mailUser = $mailUser ?: ($ml['user'] ?? '');
        $mailPass = $mailPass ?: ($ml['pass'] ?? '');
    }
}
define('GMAIL_USER', $mailUser);
define('GMAIL_APP_PASSWORD', $mailPass);
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Estimador Copago');
// URL base del sitio para armar los enlaces de los correos (opcional; si está
// vacía se deduce del request). En Railway conviene fijarla, ej: https://tuapp.up.railway.app
define('APP_URL', rtrim(getenv('APP_URL') ?: '', '/'));

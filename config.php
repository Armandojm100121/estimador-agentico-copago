<?php
// config.php  -  Configuración del LLM (seguro para subir a Git; sin claves)
// Proveedor CONMUTABLE: Claude (Anthropic) o Groq (compatible con OpenAI).
// Claves y proveedor se leen en este orden:
//   1) variables de entorno  ANTHROPIC_API_KEY / GROQ_API_KEY / LLM_PROVIDER  (producción)
//   2) config.local.php  ->  ['provider'=>..., 'claude'=>..., 'groq'=>...]     (desarrollo, .gitignore)

$anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
$groqKey      = getenv('GROQ_API_KEY') ?: '';
$provider     = getenv('LLM_PROVIDER') ?: '';

if (file_exists(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) {
        $anthropicKey = $anthropicKey ?: ($local['claude'] ?? $local['anthropic'] ?? '');
        $groqKey      = $groqKey      ?: ($local['groq'] ?? '');
        $provider     = $provider     ?: ($local['provider'] ?? '');
    } else {
        // Compatibilidad hacia atrás: si config.local.php devuelve un string, es la key de Groq.
        $groqKey = $groqKey ?: (string) $local;
    }
}

// Proveedor por defecto: Claude si hay key de Claude; si no, Groq.
if ($provider === '') { $provider = $anthropicKey ? 'claude' : 'groq'; }
define('LLM_PROVIDER', $provider);

// --- Claude (Anthropic) ---  La plata sale del SQL; el modelo solo razona y decide.
define('ANTHROPIC_API_KEY', $anthropicKey);
define('ANTHROPIC_MODEL',   getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-5-5');  // el más barato; suficiente para estimar copagos
define('ANTHROPIC_VERSION', '2026-10-21');

// --- Groq (compatible con OpenAI) ---  nombres históricos, usados por el camino Groq.
define('OPENAI_API_KEY', $groqKey ?: '');
define('OPENAI_MODEL',   getenv('GROQ_MODEL') ?: 'openai/gpt-oss-20b');

// ---------------------------------------------------------------------------
// Correo transaccional (recuperación de contraseña / verificación) — #15
// Envío por SMTP de Gmail. Las credenciales se leen en este orden:
//   1) variables de entorno GMAIL_USER / GMAIL_APP_PASSWORD  (producción / Railway)
//   2) mail.local.php  -> ['user' => ..., 'pass' => ...]      (desarrollo, en .gitignore)
// Si NO hay credenciales, el sistema entra en "modo demo": no envía correo,
// sino que muestra el enlace en pantalla (útil para desarrollar sin internet).
// GMAIL_APP_PASSWORD es una "contraseña de aplicación" de Google, NO la normal.
// ---------------------------------------------------------------------------
$mailUser  = getenv('GMAIL_USER') ?: '';
$mailPass  = getenv('GMAIL_APP_PASSWORD') ?: '';
$brevoKey  = getenv('BREVO_API_KEY') ?: '';
$mailFrom  = getenv('MAIL_FROM') ?: '';
if (file_exists(__DIR__ . '/mail.local.php')) {
    $ml = require __DIR__ . '/mail.local.php';
    if (is_array($ml)) {
        $mailUser = $mailUser ?: ($ml['user']      ?? '');
        $mailPass = $mailPass ?: ($ml['pass']      ?? '');
        $brevoKey = $brevoKey ?: ($ml['brevo_key'] ?? '');
        $mailFrom = $mailFrom ?: ($ml['from']      ?? '');
    }
}
define('GMAIL_USER', $mailUser);
define('GMAIL_APP_PASSWORD', $mailPass);
// Brevo (API HTTP) — funciona en Railway porque NO usa puertos SMTP bloqueados.
define('BREVO_API_KEY', $brevoKey);
// Correo remitente (debe estar verificado en Brevo). Por defecto usa el de Gmail.
define('MAIL_FROM', $mailFrom ?: $mailUser);
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Estimador Copago');
// URL base del sitio para armar los enlaces de los correos (opcional; si está
// vacía se deduce del request). En Railway conviene fijarla, ej: https://tuapp.up.railway.app
define('APP_URL', rtrim(getenv('APP_URL') ?: '', '/'));

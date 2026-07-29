<?php
// mailer.php  -  Correo transaccional por SMTP de Gmail (#15).
//
// Diseño HÍBRIDO (transporte desacoplado, configurable por entorno):
//   - Si hay credenciales (GMAIL_USER + GMAIL_APP_PASSWORD) -> envía correo real.
//   - Si NO hay credenciales -> "modo demo": no envía, y el llamador muestra el
//     enlace en pantalla. Así se desarrolla en local sin internet y se demuestra
//     siempre, pero en producción (Railway) manda el correo de verdad.
//
// Sin librerías externas: implementa un cliente SMTP mínimo sobre SSL (puerto 465),
// autenticado con AUTH LOGIN. Las credenciales NUNCA van en el código (config.php
// las lee de variables de entorno / mail.local.php).

require_once __DIR__ . '/config.php';

/** ¿Está configurado el envío real de correo? */
function mail_configurado(): bool
{
    return GMAIL_USER !== '' && GMAIL_APP_PASSWORD !== '';
}

/**
 * URL base del sitio (para armar enlaces absolutos en los correos).
 * Usa APP_URL si está definida; si no, la deduce del request actual.
 */
function base_url(): string
{
    if (APP_URL !== '') {
        return APP_URL;
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Envía un correo HTML por SMTP de Gmail.
 * Devuelve ['ok' => bool, 'error' => string|null].
 * Si no hay credenciales, devuelve ok=false y error='no_config' (modo demo).
 */
function enviar_correo(string $para, string $asunto, string $html): array
{
    if (!mail_configurado()) {
        return ['ok' => false, 'error' => 'no_config'];
    }

    $host = 'smtp.gmail.com';
    $port = 465;
    $user = GMAIL_USER;
    $pass = GMAIL_APP_PASSWORD;
    $de   = GMAIL_USER;

    // Lee una respuesta SMTP (soporta multilínea: "250-..." continúa, "250 " cierra).
    $leer = function ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // La línea final tiene un espacio en la 4ª posición (no un guión).
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    // Envía un comando y verifica que la respuesta empiece por el código esperado.
    $cmd = function ($fp, $comando, $esperado) use ($leer) {
        if ($comando !== null) {
            fwrite($fp, $comando . "\r\n");
        }
        $resp = $leer($fp);
        $code = substr($resp, 0, 3);
        if ($code !== $esperado) {
            throw new RuntimeException("SMTP esperaba $esperado y recibió: " . trim($resp));
        }
        return $resp;
    };

    try {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]]);
        $fp = @stream_socket_client(
            "ssl://$host:$port",
            $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$fp) {
            return ['ok' => false, 'error' => "No se pudo conectar a Gmail SMTP ($errstr)"];
        }
        stream_set_timeout($fp, 15);

        $cmd($fp, null, '220');                              // saludo del servidor
        $cmd($fp, 'EHLO estimador-copago', '250');
        $cmd($fp, 'AUTH LOGIN', '334');
        $cmd($fp, base64_encode($user), '334');
        $cmd($fp, base64_encode($pass), '235');              // 235 = autenticado
        $cmd($fp, "MAIL FROM:<$de>", '250');
        $cmd($fp, "RCPT TO:<$para>", '250');
        $cmd($fp, 'DATA', '354');

        // Cabeceras + cuerpo. El punto solo en una línea marca el fin (por eso
        // protegemos líneas que empiecen con "." duplicándolo: "dot-stuffing").
        $fromName = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?=';
        $headers  = "From: $fromName <$de>\r\n";
        $headers .= "To: <$para>\r\n";
        $headers .= 'Subject: =?UTF-8?B?' . base64_encode($asunto) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        $cuerpo   = chunk_split(base64_encode($html));
        fwrite($fp, $headers . "\r\n" . $cuerpo . "\r\n.\r\n");
        $cmd($fp, null, '250');                              // aceptado

        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        if (isset($fp) && $fp) { @fclose($fp); }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Plantilla HTML común de los correos (encabezado con marca + contenido).
 */
function correo_plantilla(string $titulo, string $cuerpoHtml): string
{
    $marca = '#0f5c5c';
    return '<!DOCTYPE html><html><body style="margin:0;background:#e9e6dd;font-family:Arial,Helvetica,sans-serif;padding:24px">'
        . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px -18px rgba(0,0,0,.4)">'
        . '<div style="background:' . $marca . ';padding:20px 26px;color:#fff">'
        . '<span style="font-size:18px;font-weight:700">Estimador Copago</span></div>'
        . '<div style="padding:26px;color:#10231f;font-size:15px;line-height:1.6">'
        . '<h2 style="margin:0 0 14px;font-size:20px;color:#10231f">' . $titulo . '</h2>'
        . $cuerpoHtml
        . '</div>'
        . '<div style="padding:16px 26px;color:#7a8681;font-size:12px;border-top:1px solid #eef1ee">'
        . 'Si no solicitaste este correo, puedes ignorarlo sin problema.</div>'
        . '</div></body></html>';
}

/** Botón (enlace) con estilo de marca para los correos. */
function correo_boton(string $texto, string $url): string
{
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
        . 'style="display:inline-block;background:#0f5c5c;color:#fff;text-decoration:none;'
        . 'font-weight:600;padding:13px 22px;border-radius:11px;margin:10px 0">' . $texto . '</a>';
}

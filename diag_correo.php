<?php
// diag_correo.php  -  Diagnóstico TEMPORAL del envío de correo en producción.
// Uso:  /diag_correo.php?k=diag2026&to=tucorreo@gmail.com
// Muestra la configuración, prueba la conexión SMTP y el envío real, con el
// error exacto si falla. BORRAR este archivo después de diagnosticar.

require __DIR__ . '/mailer.php';
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['k'] ?? '') !== 'diag2026') {
    http_response_code(403);
    exit("Acceso restringido.\n");
}

echo "=== DIAGNOSTICO DE CORREO ===\n\n";

// 1) Configuración
echo "1) Configuracion\n";
echo "   mail_configurado(): " . (mail_configurado() ? 'SI' : 'NO') . "\n";
echo "   BREVO_API_KEY     : " . (BREVO_API_KEY !== '' ? ('configurada (' . strlen(BREVO_API_KEY) . ' chars)') : 'NO configurada') . "\n";
echo "   MAIL_FROM         : [" . MAIL_FROM . "]  (debe estar VERIFICADO en Brevo)\n";
echo "   MAIL_FROM_NAME    : [" . MAIL_FROM_NAME . "]\n";
echo "   GMAIL_USER        : [" . GMAIL_USER . "]  (solo respaldo SMTP local)\n";
echo "   APP_URL           : [" . APP_URL . "]\n";
echo "   base_url()        : [" . base_url() . "]\n\n";

// 2) Prueba de conexión cruda a Gmail SMTP (465 SSL)
echo "2) Conexion a smtp.gmail.com:465 (SSL)\n";
$t0 = microtime(true);
$fp = @stream_socket_client('ssl://smtp.gmail.com:465', $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
$dt = round((microtime(true) - $t0) * 1000);
if ($fp) {
    echo "   CONECTO OK en {$dt}ms\n";
    $saludo = fgets($fp, 515);
    echo "   Saludo del servidor: " . trim((string)$saludo) . "\n";
    fclose($fp);
} else {
    echo "   FALLO en {$dt}ms  errno=$errno  errstr=$errstr\n";
    echo "   (si falla aqui, Railway estaria bloqueando el puerto 465)\n";
}
echo "\n";

// 3) Envío real
$to = $_GET['to'] ?? '';
echo "3) Envio real\n";
if (!$to) {
    echo "   (agrega &to=tucorreo@gmail.com a la URL para probar el envio)\n";
} else {
    echo "   Enviando a: $to\n";
    $html = correo_plantilla('Prueba de diagnostico', '<p>Correo de prueba desde produccion. Si lo recibiste, el envio funciona.</p>');
    $res = enviar_correo($to, 'Diagnostico · Estimador Copago', $html);
    echo "   Resultado: " . ($res['ok'] ? 'ENVIADO OK' : ('FALLO -> ' . $res['error'])) . "\n";
}

echo "\n=== FIN ===\n";

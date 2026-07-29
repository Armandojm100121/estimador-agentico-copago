<?php
// forgot.php  -  "¿Olvidaste tu contraseña?" (#15).
// Pide el correo, genera un token seguro y entrega el enlace de recuperación
// (por correo real si hay credenciales, o en pantalla en modo demo).
// Seguridad: respuesta NEUTRA (no revela si el correo existe) + rate-limit básico.

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/tokens.php';
require __DIR__ . '/mailer.php';

if (estaLogueado()) {
    header('Location: index.php');
    exit;
}

$hecho     = false;     // ya se procesó la solicitud (mostramos mensaje neutro)
$error     = '';
$enlaceDemo = '';       // en modo demo, el enlace se muestra en pantalla
$emailOld  = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate-limit básico: 1 solicitud cada 60s por sesión (enlaza con #16).
    $ahora   = time();
    $ultimo  = $_SESSION['forgot_last'] ?? 0;
    if ($ahora - $ultimo < 60) {
        $error = 'Espera un momento antes de volver a solicitar el enlace.';
    } elseif (!filter_var($emailOld, FILTER_VALIDATE_EMAIL)) {
        $error = 'Escribe un correo válido.';
    } else {
        $_SESSION['forgot_last'] = $ahora;
        try {
            $db = getDB();
            $st = $db->prepare("SELECT id, nombre FROM usuarios WHERE email = ?");
            $st->execute([$emailOld]);
            $u = $st->fetch();

            // Solo si el correo existe generamos y enviamos; si no, igual mostramos
            // el mismo mensaje neutro para no filtrar qué correos están registrados.
            if ($u) {
                $token  = crear_token($db, (int) $u['id'], 'reset', 30);
                $enlace = base_url() . '/reset.php?token=' . $token;

                $html = correo_plantilla(
                    'Recupera tu contraseña',
                    '<p>Hola ' . htmlspecialchars($u['nombre'], ENT_QUOTES) . ',</p>'
                    . '<p>Recibimos una solicitud para restablecer tu contraseña. '
                    . 'Haz clic en el botón para crear una nueva. El enlace vence en 30 minutos.</p>'
                    . '<p style="text-align:center">' . correo_boton('Restablecer contraseña', $enlace) . '</p>'
                    . '<p style="font-size:13px;color:#7a8681">Si el botón no funciona, copia este enlace:<br>'
                    . '<a href="' . htmlspecialchars($enlace, ENT_QUOTES) . '">' . htmlspecialchars($enlace, ENT_QUOTES) . '</a></p>'
                );
                $res = enviar_correo($emailOld, 'Recupera tu contraseña · Estimador Copago', $html);

                // Modo demo (sin credenciales): mostramos el enlace en pantalla.
                if (!$res['ok'] && $res['error'] === 'no_config') {
                    $enlaceDemo = $enlace;
                }
                // Si hubo un error real de envío, no lo revelamos al usuario
                // (respuesta neutra); queda para el log del servidor.
                if (!$res['ok'] && $res['error'] !== 'no_config') {
                    error_log('Fallo envío recuperación: ' . $res['error']);
                }
            }
            $hecho = true;
        } catch (Throwable $e) {
            $error = 'No se pudo procesar la solicitud. Intenta más tarde.';
        }
    }
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recuperar contraseña · Estimador Copago</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@0,400;0,500;0,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="brand.css">
<script>(function(){try{var t=localStorage.getItem('tema');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);font-family:'IBM Plex Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;color:var(--text)}
  a{color:var(--marca);text-decoration:none}a:hover{color:var(--acento)}
  .sora{font-family:'Sora',sans-serif}
  .card{width:440px;max-width:100%;background:var(--surface);border-radius:20px;padding:38px 36px;box-shadow:0 30px 70px -40px rgba(16,35,31,.45)}
  .field{width:100%;border:1px solid var(--field-border);background:var(--surface-2);border-radius:12px;padding:13px 15px;font-size:14px;color:var(--text);outline:none}
  .field:focus{border-color:var(--marca)}
  .btn-primary{width:100%;background:var(--marca);border:none;color:#fff;font-family:'Sora',sans-serif;font-weight:600;font-size:15px;padding:14px;border-radius:12px;cursor:pointer}
  .btn-primary:hover{background:var(--marca-2)}
  .lbl{font-size:12.5px;font-weight:600;color:var(--text);display:block;margin-bottom:7px}
  .msg{padding:12px 15px;border-radius:11px;font-size:13.5px;margin-bottom:16px;line-height:1.5}
  .msg.err{background:#fdeaea;border:1px solid #f3c6c6;color:#b23c3c}
  .msg.ok{background:#e9f7ef;border:1px solid #c6ead5;color:#128a4e}
  .msg.demo{background:#fdf3e6;border:1px solid #f0d9b5;color:#8a5a12;word-break:break-all}
</style>
</head>
<body>
<div class="card">
  <h1 class="sora" style="font-size:23px;font-weight:700;letter-spacing:-.02em">Recuperar contraseña</h1>

  <?php if ($hecho): ?>
    <p style="font-size:14px;color:var(--muted);margin:8px 0 18px">Si ese correo está registrado, te enviamos un enlace para restablecer tu contraseña.</p>
    <?php if ($enlaceDemo): ?>
      <div class="msg demo">
        <b>Modo demo (sin correo configurado).</b><br>
        Abre este enlace para continuar:<br>
        <a href="<?= $h($enlaceDemo) ?>"><?= $h($enlaceDemo) ?></a>
      </div>
    <?php else: ?>
      <div class="msg ok">Revisa tu bandeja de entrada (y la carpeta de spam).</div>
    <?php endif; ?>
    <p style="font-size:13.5px;text-align:center;margin-top:8px"><a href="login.php">← Volver a iniciar sesión</a></p>

  <?php else: ?>
    <p style="font-size:14px;color:var(--muted);margin:8px 0 22px">Escribe tu correo y te enviaremos un enlace para crear una nueva contraseña.</p>
    <?php if ($error): ?><div class="msg err">⚠️ <?= $h($error) ?></div><?php endif; ?>
    <form method="post" style="display:flex;flex-direction:column;gap:14px">
      <div>
        <label class="lbl">Correo electrónico</label>
        <input class="field" type="email" name="email" placeholder="tucorreo@ejemplo.com" value="<?= $h($emailOld) ?>" required autofocus>
      </div>
      <button class="btn-primary" type="submit">Enviar enlace</button>
    </form>
    <p style="font-size:13.5px;text-align:center;margin-top:20px"><a href="login.php">← Volver a iniciar sesión</a></p>
  <?php endif; ?>
</div>
<script src="theme.js"></script>
</body>
</html>

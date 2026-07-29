<?php
// verificar.php  -  Verificación de propiedad del correo (#15, Nivel 2).
// Valida el token 'verify' del enlace y marca usuarios.email_verificado = 1.

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/tokens.php';

$db    = getDB();
$token = $_GET['token'] ?? '';
$fila  = validar_token($db, (string) $token, 'verify');
$ok    = false;

if ($fila) {
    try {
        $st = $db->prepare("UPDATE usuarios SET email_verificado = 1 WHERE id = ?");
        $st->execute([(int) $fila['usuario_id']]);
        marcar_token_usado($db, (int) $fila['id']);
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// A dónde enviamos tras verificar: si está logueado, a su área; si no, al login.
$destino = estaLogueado() ? (esAdmin() ? 'metricas.php' : 'index.php') : 'login.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verificación de correo · Estimador Copago</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@0,400;0,500;0,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="brand.css">
<script>(function(){try{var t=localStorage.getItem('tema');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);font-family:'IBM Plex Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;color:var(--text)}
  a{color:var(--marca);text-decoration:none}
  .sora{font-family:'Sora',sans-serif}
  .card{width:440px;max-width:100%;background:var(--surface);border-radius:20px;padding:40px 36px;box-shadow:0 30px 70px -40px rgba(16,35,31,.45);text-align:center}
  .btn-primary{display:inline-block;background:var(--marca);border:none;color:#fff;font-family:'Sora',sans-serif;font-weight:600;font-size:15px;padding:13px 24px;border-radius:12px;cursor:pointer;text-decoration:none;margin-top:8px}
  .btn-primary:hover{background:var(--marca-2)}
  .ico{font-size:46px;margin-bottom:10px}
</style>
</head>
<body>
<div class="card">
  <?php if ($ok): ?>
    <div class="ico">✅</div>
    <h1 class="sora" style="font-size:22px;font-weight:700">¡Correo verificado!</h1>
    <p style="font-size:14px;color:var(--muted);margin:10px 0 20px">Tu dirección de correo quedó confirmada. Gracias.</p>
    <a class="btn-primary" href="<?= $h($destino) ?>">Continuar</a>
  <?php else: ?>
    <div class="ico">⚠️</div>
    <h1 class="sora" style="font-size:22px;font-weight:700">Enlace no válido</h1>
    <p style="font-size:14px;color:var(--muted);margin:10px 0 20px">El enlace de verificación no es válido o ya expiró.</p>
    <a class="btn-primary" href="<?= $h($destino) ?>">Ir a la aplicación</a>
  <?php endif; ?>
</div>
<script src="theme.js"></script>
</body>
</html>

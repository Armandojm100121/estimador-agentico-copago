<?php
// reset.php  -  Restablecer contraseña con el token del enlace (#15).
// Valida el token (no usado, no expirado), pide la nueva contraseña,
// la guarda con password_hash() e invalida el token (un solo uso).

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/tokens.php';

if (estaLogueado()) {
    header('Location: index.php');
    exit;
}

$db     = getDB();
$token  = $_GET['token'] ?? ($_POST['token'] ?? '');
$error  = '';
$ok     = false;
$valido = false;
$fila   = validar_token($db, (string) $token, 'reset');
$valido = $fila !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valido) {
    $pass  = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');
    if (strlen($pass) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        try {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $st = $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
            $st->execute([$hash, (int) $fila['usuario_id']]);
            marcar_token_usado($db, (int) $fila['id']);   // un solo uso
            $ok = true;
        } catch (Throwable $e) {
            $error = 'No se pudo actualizar la contraseña. Intenta de nuevo.';
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
<title>Nueva contraseña · Estimador Copago</title>
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
</style>
</head>
<body>
<div class="card">
  <h1 class="sora" style="font-size:23px;font-weight:700;letter-spacing:-.02em">Nueva contraseña</h1>

  <?php if ($ok): ?>
    <div class="msg ok" style="margin-top:14px">✓ Tu contraseña se actualizó correctamente.</div>
    <a class="btn-primary" href="login.php" style="display:block;text-align:center;text-decoration:none;margin-top:6px">Iniciar sesión</a>

  <?php elseif (!$valido): ?>
    <div class="msg err" style="margin-top:14px">⚠️ El enlace no es válido o ya expiró. Solicita uno nuevo.</div>
    <a class="btn-primary" href="forgot.php" style="display:block;text-align:center;text-decoration:none;margin-top:6px">Pedir un nuevo enlace</a>

  <?php else: ?>
    <p style="font-size:14px;color:var(--muted);margin:8px 0 22px">Crea una contraseña nueva para tu cuenta.</p>
    <?php if ($error): ?><div class="msg err">⚠️ <?= $h($error) ?></div><?php endif; ?>
    <form method="post" style="display:flex;flex-direction:column;gap:14px">
      <input type="hidden" name="token" value="<?= $h($token) ?>">
      <div>
        <label class="lbl">Nueva contraseña <span style="color:#9aa8a2;font-weight:400">(mínimo 6)</span></label>
        <input class="field" type="password" name="password" placeholder="••••••••" required autofocus>
      </div>
      <div>
        <label class="lbl">Repite la contraseña</label>
        <input class="field" type="password" name="password2" placeholder="••••••••" required>
      </div>
      <button class="btn-primary" type="submit">Guardar contraseña</button>
    </form>
  <?php endif; ?>
</div>
<script src="theme.js"></script>
</body>
</html>

<?php
// login.php  -  Inicio de sesión y registro reales, validados contra la BD.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/tokens.php';
require __DIR__ . '/mailer.php';

// Si ya está logueado: el admin va a su panel; el paciente, al estimador.
if (estaLogueado()) {
    header('Location: ' . (esAdmin() ? 'metricas.php' : 'index.php'));
    exit;
}

$error   = '';
$modo    = $_POST['modo'] ?? 'login';   // 'login' o 'registro'
$emailOld = trim($_POST['email'] ?? '');

// Cargar planes para el desplegable del registro.
$planes = [];
try {
    $planes = getDB()
        ->query("SELECT id, aseguradora, nombre FROM planes ORDER BY aseguradora, nombre")
        ->fetchAll();
} catch (Throwable $e) {
    $error = 'No se pudo conectar con la base de datos. ¿Está MySQL activo en XAMPP?';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    try {
        $db = getDB();

        if ($modo === 'registro') {
            // ---- Crear cuenta ----
            $nombre  = trim($_POST['nombre'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $pass    = (string) ($_POST['password'] ?? '');
            $planId  = (int) ($_POST['plan_id'] ?? 0);

            if ($nombre === '' || $email === '' || $pass === '') {
                throw new RuntimeException('Completa nombre, correo y contraseña.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El correo no tiene un formato válido.');
            }
            if (strlen($pass) < 6) {
                throw new RuntimeException('La contraseña debe tener al menos 6 caracteres.');
            }
            if ($planId <= 0) {
                throw new RuntimeException('Elige tu plan de seguro.');
            }

            // ¿Ya existe el correo?
            $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Ese correo ya está registrado. Inicia sesión.');
            }

            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                "INSERT INTO usuarios (nombre, email, password_hash, plan_id) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$nombre, $email, $hash, $planId]);
            $nuevoId = (int) $db->lastInsertId();

            // Etiqueta del plan para la sesión.
            $stmt = $db->prepare("SELECT aseguradora, nombre FROM planes WHERE id = ?");
            $stmt->execute([$planId]);
            $p = $stmt->fetch();
            $etiqueta = $p ? ($p['aseguradora'] . ' - ' . $p['nombre']) : '';

            // Verificación de correo (#15, Nivel 2): genera token 'verify' y
            // envía el enlace de confirmación. Modo "suave": el usuario puede
            // entrar igual; solo verá un aviso hasta que confirme su correo.
            try {
                $tokenVer = crear_token($db, $nuevoId, 'verify', 60 * 24);   // 24 h
                $enlaceVer = base_url() . '/verificar.php?token=' . $tokenVer;
                $htmlVer = correo_plantilla(
                    'Confirma tu correo',
                    '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES) . ',</p>'
                    . '<p>Gracias por registrarte. Confirma tu correo para activar todas las funciones de tu cuenta.</p>'
                    . '<p style="text-align:center">' . correo_boton('Confirmar mi correo', $enlaceVer) . '</p>'
                    . '<p style="font-size:13px;color:#7a8681">Si el botón no funciona, copia este enlace:<br>'
                    . '<a href="' . htmlspecialchars($enlaceVer, ENT_QUOTES) . '">' . htmlspecialchars($enlaceVer, ENT_QUOTES) . '</a></p>'
                );
                $resVer = enviar_correo($email, 'Confirma tu correo · Estimador Copago', $htmlVer);
                // Modo demo (sin correo configurado): mostramos el enlace en el estimador.
                if (!$resVer['ok'] && $resVer['error'] === 'no_config') {
                    $_SESSION['verify_demo_link'] = $enlaceVer;
                }
            } catch (Throwable $e) {
                error_log('Fallo envío verificación: ' . $e->getMessage());
            }

            iniciarSesionUsuario([
                'id' => $nuevoId, 'nombre' => $nombre, 'email' => $email,
                'plan_id' => $planId, 'plan_etiqueta' => $etiqueta,
            ]);
            header('Location: index.php');
            exit;

        } else {
            // ---- Iniciar sesión ----
            $email = trim($_POST['email'] ?? '');
            $pass  = (string) ($_POST['password'] ?? '');
            if ($email === '' || $pass === '') {
                throw new RuntimeException('Escribe tu correo y contraseña.');
            }

            $stmt = $db->prepare(
                "SELECT u.id, u.nombre, u.email, u.password_hash, u.plan_id,
                        p.aseguradora, p.nombre AS plan_nombre
                 FROM usuarios u
                 LEFT JOIN planes p ON p.id = u.plan_id
                 WHERE u.email = ?"
            );
            $stmt->execute([$email]);
            $u = $stmt->fetch();

            if (!$u || !password_verify($pass, $u['password_hash'])) {
                throw new RuntimeException('Correo o contraseña incorrectos.');
            }

            $etiqueta = $u['aseguradora']
                ? ($u['aseguradora'] . ' - ' . $u['plan_nombre']) : '';

            iniciarSesionUsuario([
                'id' => $u['id'], 'nombre' => $u['nombre'], 'email' => $u['email'],
                'plan_id' => $u['plan_id'], 'plan_etiqueta' => $etiqueta,
            ]);
            // El admin entra a su panel; el paciente, al estimador.
            header('Location: ' . (esAdmin() ? 'metricas.php' : 'index.php'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimador Copago — Iniciar sesión</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="brand.css">
<script>(function(){try{var t=localStorage.getItem('tema');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<style>
  :root{ --ease-spring:cubic-bezier(0.32,0.72,0,1); }
  *{box-sizing:border-box;margin:0;padding:0}
  body{
    background:var(--bg);
    background-image:
      radial-gradient(1100px 560px at 12% -12%, rgba(47,191,113,.10), transparent 60%),
      radial-gradient(880px 520px at 112% 118%, rgba(15,92,92,.12), transparent 55%);
    font-family:'IBM Plex Sans',sans-serif;min-height:100dvh;
    display:flex;align-items:center;justify-content:center;padding:24px;
  }
  a{color:var(--marca);text-decoration:none;cursor:pointer;transition:color .2s var(--ease-spring)}
  a:hover{color:var(--acento)}
  input,select{font-family:'IBM Plex Sans',sans-serif}
  input::placeholder{color:#9aa8a2}
  .sora{font-family:'Sora',sans-serif}

  /* Doble bisel: la tarjeta parece una placa física, no un rectángulo plano */
  .shell{
    width:1080px;max-width:100%;background:var(--surface);
    border:1px solid rgba(16,35,31,.06);border-radius:28px;overflow:hidden;
    box-shadow:0 44px 100px -55px rgba(16,35,31,.55), 0 10px 30px -22px rgba(16,35,31,.30);
    display:grid;grid-template-columns:1fr 1fr;min-height:640px;
    animation:shell-in .85s var(--ease-spring) both;
  }
  @keyframes shell-in{ from{opacity:0;transform:translateY(18px) scale(.985)} to{opacity:1;transform:none} }
  /* Animación de SALIDA: al enviar el formulario, la tarjeta se desvanece y sube */
  body.leaving .shell{opacity:0;transform:translateY(-14px) scale(.985);transition:opacity .34s var(--ease-spring),transform .34s var(--ease-spring)}
  body.leaving{transition:background-color .34s ease}

  /* Etiqueta "eyebrow" premium sobre los títulos */
  .eyebrow{
    display:inline-flex;align-items:center;gap:7px;font-family:'Sora',sans-serif;
    font-size:10.5px;font-weight:600;letter-spacing:.18em;text-transform:uppercase;
    color:var(--marca);background:rgba(15,92,92,.08);border:1px solid rgba(15,92,92,.14);
    padding:5px 12px;border-radius:999px;margin-bottom:15px;
  }
  .eyebrow::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--acento);box-shadow:0 0 0 3px rgba(47,191,113,.18)}

  /* Inputs con brillo interior y foco tipo anillo (haptic) */
  .field{
    width:100%;border:1px solid var(--field-border);background:var(--surface-2);
    border-radius:12px;padding:13px 15px;font-size:14px;color:var(--text);outline:none;
    box-shadow:inset 0 1px 2px rgba(16,35,31,.04);
    transition:border-color .25s var(--ease-spring),box-shadow .25s var(--ease-spring),background .25s var(--ease-spring);
  }
  .field:focus{border-color:var(--marca);background:var(--surface);box-shadow:0 0 0 4px rgba(15,92,92,.12),inset 0 1px 2px rgba(16,35,31,.04)}

  /* Botón con degradado + física de pulsación (spring) */
  .btn-primary{
    width:100%;background:linear-gradient(180deg,var(--marca-2),var(--marca));border:none;color:#fff;
    font-family:'Sora',sans-serif;font-weight:600;font-size:15px;padding:14px;border-radius:12px;cursor:pointer;
    box-shadow:0 12px 26px -14px rgba(15,92,92,.75),inset 0 1px 0 rgba(255,255,255,.14);
    transition:transform .25s var(--ease-spring),box-shadow .25s var(--ease-spring),filter .2s ease;
  }
  .btn-primary:hover{filter:brightness(1.06);transform:translateY(-1px);box-shadow:0 16px 32px -14px rgba(15,92,92,.85),inset 0 1px 0 rgba(255,255,255,.18)}
  .btn-primary:active{transform:scale(.985)}

  .lbl{font-size:12.5px;font-weight:600;color:var(--text);display:block;margin-bottom:7px}
  .err{background:#fdeaea;border:1px solid #f3c6c6;color:#b23c3c;font-size:13.5px;padding:11px 14px;border-radius:11px;margin-bottom:16px;line-height:1.5}
  .hidden{display:none}
  .pwd-wrap{position:relative}
  .pwd-wrap .field{padding-right:44px}
  .pwd-eye{position:absolute;top:50%;right:8px;transform:translateY(-50%);width:30px;height:30px;border:none;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#9aa8a2;border-radius:8px;padding:0;transition:color .2s var(--ease-spring)}
  .pwd-eye:hover{color:var(--marca)}
  .pwd-eye:focus-visible{outline:2px solid var(--marca);outline-offset:2px}
  .pwd-eye svg{width:19px;height:19px;display:block}
  .pwd-eye .eye-off{display:none}
  .pwd-eye.on .eye-open{display:none}
  .pwd-eye.on .eye-off{display:block}
  .formside{padding:52px 52px;display:flex;flex-direction:column;justify-content:center;background:var(--surface)}

  /* Tarjetas de estadística (doble bisel) en el panel de marca */
  .stat{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px 14px;box-shadow:inset 0 1px 0 rgba(255,255,255,.06)}

  @media(max-width:860px){.shell{grid-template-columns:1fr;min-height:auto}.brand-side{display:none}}
  @media(max-width:560px){body{padding:14px}.shell{border-radius:20px}.formside{padding:36px 24px}}
</style>
</head>
<body>
<div class="shell">

  <!-- BRAND SIDE -->
  <div class="brand-side" style="background:#0d1f1b;color:#e3efe9;padding:44px 42px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden">
    <div style="position:absolute;right:-80px;bottom:-80px;width:340px;height:340px;background:radial-gradient(circle,#2fbf71 0%,transparent 70%);opacity:.28"></div>
    <div style="position:absolute;left:-110px;top:-90px;width:300px;height:300px;background:radial-gradient(circle,#12786b 0%,transparent 70%);opacity:.35"></div>
    <div style="display:flex;align-items:center;gap:11px;position:relative">
      <div class="sora" style="width:36px;height:36px;border-radius:10px;background:#2fbf71;display:flex;align-items:center;justify-content:center;color:#053023;font-weight:700;font-size:19px">c</div>
      <span class="sora" style="font-weight:700;font-size:18px;color:#fff">Estimador Copago</span>
    </div>
    <div style="position:relative">
      <h1 class="sora" style="font-size:30px;font-weight:700;line-height:1.25;letter-spacing:-0.02em;color:#fff">Sabe cuánto vas a pagar, antes de ir al médico.</h1>
      <p style="font-size:15px;line-height:1.6;color:#9db8ac;margin-top:16px;max-width:38ch">Ingresa tu plan y la IA te dice el copago más accesible para tu tratamiento, en lenguaje simple.</p>
      <div style="display:flex;gap:14px;margin-top:32px">
        <div class="stat"><div class="sora" style="font-size:24px;font-weight:800;color:#7ff0b3">$248</div><div style="font-size:12px;color:#7fa494;margin-top:2px">ahorro promedio / año</div></div>
        <div class="stat"><div class="sora" style="font-size:24px;font-weight:800;color:#7ff0b3">12+</div><div style="font-size:12px;color:#7fa494;margin-top:2px">aseguradoras en Ecuador</div></div>
      </div>
    </div>
    <div style="position:relative;font-size:12.5px;color:#5f7a70">Tus datos de salud se mantienen privados y cifrados.</div>
  </div>

  <!-- FORM SIDE -->
  <div class="formside">
    <div style="max-width:380px;width:100%;margin:0 auto">

      <?php if ($error): ?>
        <div class="err">⚠️ <?= $h($error) ?></div>
      <?php endif; ?>

      <!-- ====== LOGIN ====== -->
      <div id="panel-login" class="<?= $modo === 'registro' ? 'hidden' : '' ?>">
        <span class="eyebrow">Bienvenido</span>
        <h2 class="sora" style="font-size:25px;font-weight:700;letter-spacing:-0.02em;color:var(--text)">Inicia sesión</h2>
        <p style="font-size:14px;color:var(--muted);margin-top:6px">Bienvenido de nuevo. Continúa con tu estimación.</p>
        <form method="post" style="display:flex;flex-direction:column;gap:14px;margin-top:26px">
          <input type="hidden" name="modo" value="login">
          <div>
            <label class="lbl">Correo electrónico</label>
            <input class="field" type="email" name="email" placeholder="tucorreo@ejemplo.com" value="<?= $h($emailOld) ?>" required>
          </div>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:7px"><label class="lbl" style="margin:0">Contraseña</label><a href="forgot.php" style="font-size:12.5px;font-weight:500">¿Olvidaste tu contraseña?</a></div>
            <div class="pwd-wrap">
              <input class="field" type="password" name="password" placeholder="••••••••" required>
              <button type="button" class="pwd-eye" onclick="togglePwd(this)" aria-label="Mostrar contraseña" title="Mostrar/ocultar contraseña">
                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>
          <button class="btn-primary" type="submit" style="margin-top:6px">Entrar</button>
        </form>
        <p style="font-size:13.5px;color:#7a8681;text-align:center;margin-top:22px">¿No tienes cuenta? <a onclick="mostrar('registro')" style="font-weight:600">Regístrate</a></p>
      </div>

      <!-- ====== REGISTRO ====== -->
      <div id="panel-registro" class="<?= $modo === 'registro' ? '' : 'hidden' ?>">
        <span class="eyebrow">Nueva cuenta</span>
        <h2 class="sora" style="font-size:25px;font-weight:700;letter-spacing:-0.02em;color:var(--text)">Crea tu cuenta</h2>
        <p style="font-size:14px;color:var(--muted);margin-top:6px">Regístrate y elige tu plan para empezar.</p>
        <form method="post" style="display:flex;flex-direction:column;gap:14px;margin-top:22px">
          <input type="hidden" name="modo" value="registro">
          <div>
            <label class="lbl">Nombre completo</label>
            <input class="field" type="text" name="nombre" placeholder="Tu nombre" value="<?= $h($_POST['nombre'] ?? '') ?>" required>
          </div>
          <div>
            <label class="lbl">Correo electrónico</label>
            <input class="field" type="email" name="email" placeholder="tucorreo@ejemplo.com" value="<?= $modo === 'registro' ? $h($emailOld) : '' ?>" required>
          </div>
          <div>
            <label class="lbl">Contraseña <span style="color:#9aa8a2;font-weight:400">(mínimo 6 caracteres)</span></label>
            <div class="pwd-wrap">
              <input class="field" type="password" name="password" placeholder="••••••••" required>
              <button type="button" class="pwd-eye" onclick="togglePwd(this)" aria-label="Mostrar contraseña" title="Mostrar/ocultar contraseña">
                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>
          <div>
            <label class="lbl">Tu plan de seguro</label>
            <select class="field" name="plan_id" required>
              <option value="">Selecciona tu plan…</option>
              <?php foreach ($planes as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= (int)($_POST['plan_id'] ?? 0) === (int)$p['id'] ? ' selected' : '' ?>>
                  <?= $h($p['aseguradora'] . ' — ' . $p['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn-primary" type="submit" style="margin-top:6px">Crear cuenta</button>
        </form>
        <p style="font-size:13.5px;color:#7a8681;text-align:center;margin-top:22px">¿Ya tienes cuenta? <a onclick="mostrar('login')" style="font-weight:600">Inicia sesión</a></p>
      </div>

    </div>
  </div>
</div>

<script>
  function mostrar(cual){
    document.getElementById('panel-login').classList.toggle('hidden', cual !== 'login');
    document.getElementById('panel-registro').classList.toggle('hidden', cual !== 'registro');
  }
  function togglePwd(btn){
    var inp = btn.parentElement.querySelector('input');
    var mostrar = inp.type === 'password';
    inp.type = mostrar ? 'text' : 'password';
    btn.classList.toggle('on', mostrar);
    btn.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
  }

  // Transición de SALIDA al enviar el formulario (Entrar / Crear cuenta).
  // Si el formulario es inválido, dejamos que el navegador muestre su validación
  // (no animamos). Si es válido, reproducimos la animación y enviamos al terminar.
  document.querySelectorAll('form').forEach(function(f){
    f.addEventListener('submit', function(e){
      if(!f.checkValidity()) return;              // deja la validación nativa del navegador
      e.preventDefault();
      document.body.classList.add('leaving');
      setTimeout(function(){ f.submit(); }, 340); // envía cuando termina la animación
    });
  });
</script>
<script src="theme.js"></script>
</body>
</html>

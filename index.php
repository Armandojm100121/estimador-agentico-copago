<?php
// index.php  -  Estimador (diseño dashboard) conectado a la IA real (chat.php).
// Requiere un usuario logueado; usa el plan asociado a su cuenta.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
requiereLogin();

$u       = usuarioActual();
$esAdmin = esAdmin();   // muestra los accesos de administración solo a admins
$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$ini = iniciales($u['nombre']);
// Etiqueta bonita del plan: "BMI - Cobertura Nacional" -> aseguradora / nombre
$planTxt = $u['plan_etiqueta'] ?: 'Sin plan asignado';

// Ciudades disponibles y la ciudad actualmente elegida (por defecto Guayaquil).
$ciudades = [];
try {
    $ciudades = getDB()->query("SELECT DISTINCT ciudad FROM hospitales ORDER BY ciudad")
                       ->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* si la BD falla, el chat mostrará el error */ }
$ciudadActual = $_SESSION['ciudad'] ?? 'Guayaquil';
if ($ciudades && !in_array($ciudadActual, $ciudades, true)) {
    $ciudadActual = $ciudades[0];
}

// Aviso suave de verificación de correo (#15): true si el usuario aún no confirma.
$correoNoVerificado = false;
try {
    $stv = getDB()->prepare("SELECT email_verificado FROM usuarios WHERE id = ?");
    $stv->execute([$u['id']]);
    $ev = $stv->fetchColumn();                 // false si no hay fila
    $correoNoVerificado = ($ev !== false && (int) $ev === 0);
} catch (Throwable $e) { $correoNoVerificado = false; }
// Enlace de verificación en modo demo (sin correo configurado), si viene del registro.
$verifyDemoLink = $_SESSION['verify_demo_link'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimador Copago — Tu estimación</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="brand.css">
<script>(function(){try{var t=localStorage.getItem('tema');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<style>
  :root{ --ease-spring:cubic-bezier(0.32,0.72,0,1); }
  *{box-sizing:border-box;margin:0;padding:0}
  html{background:var(--surface-2)}
  body{background:var(--surface-2);font-family:'IBM Plex Sans',sans-serif}
  a{color:var(--marca);text-decoration:none;transition:color .2s var(--ease-spring)}
  a:hover{color:#2fbf71}
  input{font-family:'IBM Plex Sans',sans-serif}
  input::placeholder{color:#9aa8a2}
  ::-webkit-scrollbar{width:8px;height:8px}
  ::-webkit-scrollbar-thumb{background:#cdd6d1;border-radius:8px}
  ::-webkit-scrollbar-thumb:hover{background:#b6c2bc}
  /* App a pantalla completa con barra lateral colapsable (menú hamburguesa) */
  .app{width:100%;min-height:100vh;background:var(--surface);display:grid;grid-template-columns:300px 1fr;color:var(--text);transition:grid-template-columns .25s ease}
  .app.collapsed{grid-template-columns:0 1fr}
  aside{overflow:hidden;position:relative}
  aside>*{position:relative;z-index:1}
  /* Aurora sutil moviéndose DETRÁS de los recuadros de vidrio de la barra lateral */
  aside::before{content:"";position:absolute;inset:-20%;z-index:0;pointer-events:none;
    background:radial-gradient(50% 40% at 28% 14%,rgba(47,191,113,.16),transparent 60%),radial-gradient(55% 46% at 82% 82%,rgba(18,120,107,.18),transparent 62%);
    animation:aurora-drift 24s ease-in-out infinite alternate}
  .app.collapsed aside{visibility:hidden}
  .hamburger{width:40px;height:40px;border-radius:11px;border:1px solid var(--field-border);background:var(--surface);cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;flex-shrink:0;transition:border-color .2s var(--ease-spring),transform .2s var(--ease-spring)}
  .hamburger:hover{border-color:var(--marca)}
  .hamburger:active{transform:scale(.94)}
  .hamburger span{width:17px;height:2px;background:var(--text);border-radius:2px}
  .sora{font-family:'Sora',sans-serif}
  .chip{font-size:12px;background:#1c332d;color:#9db8ac;padding:6px 11px;border-radius:999px;cursor:pointer;border:none;transition:background .2s var(--ease-spring),transform .2s var(--ease-spring)}
  .chip:hover{background:#25423a;transform:translateY(-1px)}
  .chip:active{transform:scale(.96)}
  .btn-primary{background:linear-gradient(180deg,var(--marca-2),var(--marca));border:none;color:#fff;font-family:'Sora',sans-serif;font-weight:600;font-size:13.5px;padding:10px 18px;border-radius:11px;cursor:pointer;box-shadow:0 10px 22px -14px rgba(15,92,92,.7),inset 0 1px 0 rgba(255,255,255,.14);transition:transform .25s var(--ease-spring),box-shadow .25s var(--ease-spring),filter .2s ease}
  .btn-primary:hover{filter:brightness(1.06);transform:translateY(-1px);box-shadow:0 14px 28px -14px rgba(15,92,92,.8),inset 0 1px 0 rgba(255,255,255,.18)}
  .btn-primary:active{transform:scale(.97)}
  .btn-ghost{background:var(--surface);border:1px solid var(--field-border);color:var(--text);font-size:13.5px;font-weight:500;padding:10px 16px;border-radius:11px;cursor:pointer;transition:border-color .2s var(--ease-spring),color .2s var(--ease-spring),transform .2s var(--ease-spring)}
  .btn-ghost:hover:not([disabled]){border-color:var(--marca);color:var(--marca);transform:translateY(-1px)}
  .card{background:var(--surface);border:1px solid var(--borde);border-radius:20px;padding:24px;color:var(--text);box-shadow:0 18px 44px -34px rgba(16,35,31,.4);transition:box-shadow .3s var(--ease-spring)}
  .card:hover{box-shadow:0 26px 60px -38px rgba(16,35,31,.5)}
  .nav-item{padding:11px 13px;color:#9db8ac;display:flex;align-items:center;gap:11px;border-radius:10px;font-size:14px;cursor:pointer;transition:background .2s var(--ease-spring),color .2s var(--ease-spring)}
  .nav-item:hover{background:rgba(255,255,255,.07);color:#e3efe9}
  .nav-item.active{background:rgba(255,255,255,.12);color:#fff;font-weight:600;box-shadow:inset 0 1px 0 rgba(255,255,255,.12)}
  /* Selector de ciudad: opciones legibles al desplegar (fondo oscuro, texto claro) */
  #citySelect{color:#e3efe9}
  #citySelect option{background:#10231f;color:#e3efe9}
  .muted{color:var(--muted)}
  /* Entrada ESCALONADA del contenido (respeta prefers-reduced-motion vía brand.css) */
  @keyframes rise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
  .main>*{animation:rise .7s var(--ease-spring) both;position:relative;z-index:1}
  .main>*:nth-child(2){animation-delay:.05s}
  .main>*:nth-child(3){animation-delay:.10s}
  .main>*:nth-child(4){animation-delay:.15s}
  .main>*:nth-child(5){animation-delay:.20s}
  .main>*:nth-child(6){animation-delay:.25s}
  /* Aurora animada DENTRO del contenido (el dashboard es opaco y tapa el body) */
  .main::before{content:"";position:absolute;inset:0;z-index:0;pointer-events:none;
    background:
      radial-gradient(40vw 40vw at 14% 14%, rgba(47,191,113,.13), transparent 60%),
      radial-gradient(46vw 46vw at 88% 22%, rgba(15,92,92,.13), transparent 62%),
      radial-gradient(38vw 38vw at 60% 96%, rgba(18,120,107,.11), transparent 60%);
    animation:aurora-drift 28s ease-in-out infinite alternate}
  /* Burbujas del chat: entran con un pop suave (se aplica a las que agrega el JS) */
  @keyframes bubble-in{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
  #chat-log>*{animation:bubble-in .32s var(--ease-spring) both}

  /* ===== "LIQUID GLASS": brillo/reflejo en los verdes ===== */
  /* La tarjeta verde del copago como CRISTAL LÍQUIDO (conservando el verde):
     reflejo superior + destello de luz que se desliza lento (glass shine). */
  .hero::before{content:"";position:absolute;left:0;right:0;top:0;height:52%;pointer-events:none;z-index:0;
    background:linear-gradient(180deg,rgba(255,255,255,.24),rgba(255,255,255,0))}
  .hero::after{content:"";position:absolute;inset:0;pointer-events:none;z-index:0;
    background:linear-gradient(115deg,transparent 34%,rgba(255,255,255,.15) 48%,transparent 62%);
    transform:translateX(-26%);animation:hero-shine 7s ease-in-out infinite}
  @keyframes hero-shine{0%,100%{transform:translateX(-26%)}50%{transform:translateX(26%)}}
  .hero>*{position:relative;z-index:1}
  /* Barras de hospitales con brillo tipo cristal líquido */
  #comparacion div[style*="height:11px"]{box-shadow:inset 0 1px 2px rgba(16,35,31,.10)}
  #comparacion div[style*="height:11px"]>div{position:relative;box-shadow:inset 0 1px 0 rgba(255,255,255,.45)}
  #comparacion div[style*="height:11px"]>div::after{content:"";position:absolute;inset:0;border-radius:6px;pointer-events:none;
    background:linear-gradient(180deg,rgba(255,255,255,.42),rgba(255,255,255,0) 55%)}

  /* ===== CHAT FLOTANTE (Clara siempre accesible) ===== */
  /* El botón de tema (🌙/☀️) va JUNTO al de Clara, esquina inferior derecha */
  #btn-tema{right:20px;left:auto;bottom:20px;z-index:1401}
  /* Botón flotante permanente de Clara (a la izquierda del de tema) */
  #chatFab{position:fixed;right:78px;bottom:20px;z-index:1400;display:inline-flex;align-items:center;gap:9px;
    background:linear-gradient(180deg,rgba(255,255,255,.30),rgba(255,255,255,0) 46%),linear-gradient(180deg,#2fbf71,#12a266);color:#053023;border:none;border-radius:999px;
    padding:13px 20px 13px 14px;font-family:'Sora',sans-serif;font-weight:700;font-size:14px;cursor:pointer;
    box-shadow:0 18px 40px -16px rgba(15,92,92,.7),inset 0 1px 0 rgba(255,255,255,.35);
    animation:fab-in .5s var(--ease-spring) .25s both;
    transition:transform .25s var(--ease-spring),box-shadow .25s var(--ease-spring)}
  #chatFab::before{content:"";position:absolute;inset:0;border-radius:999px;pointer-events:none;animation:fab-pulse 2.8s ease-out infinite}
  #chatFab:hover{transform:translateY(-2px);box-shadow:0 22px 48px -16px rgba(15,92,92,.8),inset 0 1px 0 rgba(255,255,255,.4)}
  #chatFab:active{transform:scale(.97)}
  @keyframes fab-in{from{opacity:0;transform:translateY(12px) scale(.9)}to{opacity:1;transform:none}}
  @keyframes fab-pulse{0%{box-shadow:0 0 0 0 rgba(47,191,113,.45)}70%{box-shadow:0 0 0 14px rgba(47,191,113,0)}100%{box-shadow:0 0 0 0 rgba(47,191,113,0)}}
  #chatFab .ic{width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-size:15px;position:relative;z-index:3}
  #chatFab .label{position:relative;z-index:3}
  /* Panel flotante del chat */
  .chat-dock{position:fixed;right:20px;bottom:20px;z-index:1450;
    width:min(392px,calc(100vw - 32px));height:min(564px,calc(100dvh - 32px));
    background:#10231f;border:1px solid #24463d;border-radius:20px;color:#e3efe9;
    display:none;flex-direction:column;overflow:hidden;
    box-shadow:0 44px 100px -30px rgba(0,0,0,.65);
    transform-origin:bottom right;
    transform:translateY(28px) scale(.94);opacity:0;
    transition:transform .5s var(--ease-spring),opacity .38s ease}
  .chat-dock.open{display:flex;transform:none;opacity:1}
  .chat-dock-head{display:flex;gap:10px;align-items:center;padding:16px 16px 12px;border-bottom:1px solid #1c332d}
  .chat-dock-head .x{margin-left:auto;background:transparent;border:none;color:#7fa494;font-size:22px;line-height:1;cursor:pointer;width:32px;height:32px;border-radius:8px}
  .chat-dock-head .x:hover{background:#1c332d;color:#fff}
  .chat-dock #chat-log{max-height:none!important;flex:1 1 auto;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:12px}
  .chat-dock .sugs{display:flex;gap:7px;flex-wrap:wrap;padding:2px 16px 8px}
  .chat-dock .inrow{display:flex;align-items:center;gap:9px;background:#fff;border-radius:13px;padding:6px 6px 6px 15px;margin:0 16px 16px}
  @media(max-width:560px){
    .chat-dock{right:10px;left:10px;bottom:10px;width:auto;height:min(78dvh,560px)}
    #chatFab{right:74px;bottom:16px;padding:12px 14px}
    #chatFab .label{display:none}
    #btn-tema{right:16px;bottom:16px}
  }
  .metrics{display:grid;grid-template-columns:1.5fr 1fr 1fr;gap:16px}
  .cols{display:grid;grid-template-columns:1fr 1fr;gap:20px}
  .main{padding:32px 38px 34px;display:flex;flex-direction:column;gap:24px;background:var(--surface-2);background-image:radial-gradient(900px 420px at 100% -6%,rgba(47,191,113,.06),transparent 60%),radial-gradient(720px 380px at -6% 112%,rgba(15,92,92,.05),transparent 55%);min-width:0;position:relative;overflow:hidden}
  .backdrop{display:none}
  /* Grano de película sutil (premium, físico). Fijo y sin capturar clics. */
  body::after{content:"";position:fixed;inset:0;z-index:60;pointer-events:none;opacity:.035;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");}
  /* Etiqueta eyebrow sobre los títulos de sección */
  .eyebrow{display:inline-flex;align-items:center;gap:8px;font-family:'Sora',sans-serif;font-size:10px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--marca);margin-bottom:9px}
  .eyebrow::before{content:"";width:16px;height:2px;border-radius:2px;background:var(--acento)}

  /* Tema oscuro (#12): remapea los textos y bordes fijos DENTRO de las tarjetas
     del contenido para que sean legibles sobre superficies oscuras. La barra
     lateral y el chat ya son oscuros de por sí, así que no se tocan. */
  [data-theme="dark"] .main .card [style*="color:#10231f"],
  [data-theme="dark"] .main .card [style*="color:#334741"]{ color:var(--text) !important; }
  [data-theme="dark"] .main .card [style*="#eef1ee"]{ border-color:var(--borde) !important; }
  [data-theme="dark"] .main .card{ box-shadow:none }

  /* ================= RESPONSIVO (se adapta a cualquier pantalla) ================= */
  /* Pantallas medianas / tablet: el destacado ocupa toda la fila, las otras dos debajo */
  @media(max-width:1080px){
    .metrics{grid-template-columns:1fr 1fr}
    .metrics>:first-child{grid-column:1/-1}
  }
  /* Móvil: la barra lateral se convierte en un panel deslizable (se abre con ☰) */
  @media(max-width:960px){
    .app,.app.collapsed{grid-template-columns:1fr}
    aside{position:fixed;top:0;left:0;bottom:0;width:min(84%,300px);z-index:1000;
      visibility:hidden;transform:translateX(-100%);
      transition:transform .25s ease,visibility .25s;box-shadow:0 0 44px rgba(0,0,0,.45)}
    .app.collapsed aside{visibility:visible;transform:none}
    .app.collapsed .backdrop{display:block;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999}
    .main{padding:22px 16px 28px}
    .cols{grid-template-columns:1fr}
  }
  /* Móvil pequeño: todo apilado en una columna */
  @media(max-width:560px){
    .metrics{grid-template-columns:1fr}
    .metrics>:first-child{grid-column:auto}
    .card{padding:18px}
    h1.sora{font-size:23px!important}
  }
</style>
</head>
<body>
<a href="#main-content" class="skip-link">Saltar al contenido</a>
<div class="app" id="app">
  <!-- Fondo oscuro que aparece al abrir el menú en móvil (se cierra al tocarlo) -->
  <div class="backdrop" onclick="toggleMenu()"></div>

  <!-- SIDEBAR -->
  <aside style="background:#0d1f1b;color:#cfe0d8;padding:26px 22px;display:flex;flex-direction:column;gap:24px">
    <div style="display:flex;align-items:center;gap:11px">
      <div class="sora" style="width:34px;height:34px;border-radius:10px;background:#2fbf71;display:flex;align-items:center;justify-content:center;color:#053023;font-weight:700;font-size:18px">c</div>
      <span class="sora" style="font-weight:700;font-size:16px;color:#fff">Estimador Copago</span>
    </div>

    <div style="background:rgba(255,255,255,.06);backdrop-filter:blur(9px);-webkit-backdrop-filter:blur(9px);border:1px solid rgba(255,255,255,.10);box-shadow:inset 0 1px 0 rgba(255,255,255,.10);border-radius:14px;padding:16px">
      <div style="font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:#7fa494;margin-bottom:9px">Tu plan activo</div>
      <div style="font-size:14px;line-height:1.5;color:#e3efe9;font-weight:500"><?= $h($planTxt) ?></div>
      <label style="display:block;font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:#7fa494;margin:14px 0 7px">Ciudad de atención</label>
      <select id="citySelect" aria-label="Ciudad de atención" onchange="cambiarCiudad(this.value)"
        style="width:100%;background:rgba(255,255,255,.06);color:#e3efe9;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:9px 11px;font-family:'IBM Plex Sans',sans-serif;font-size:13.5px;cursor:pointer;outline:none">
        <?php foreach ($ciudades as $c): ?>
          <option value="<?= $h($c) ?>"<?= $c === $ciudadActual ? ' selected' : '' ?>><?= $h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <nav aria-label="Navegación principal" style="display:flex;flex-direction:column;gap:3px">
      <div class="nav-item active" onclick="cerrarMenuMovil()">◈ Recomendación</div>
      <div class="nav-item" onclick="cerrarMenuMovil();abrirChat()">✦ Chat con la IA</div>
      <div class="nav-item" onclick="cerrarMenuMovil();reiniciar()">◷ Nueva consulta</div>
      <a href="historial.php" class="nav-item" style="color:#9db8ac;text-decoration:none">◱ Historial</a>
    </nav>

    <?php if ($esAdmin): ?>
      <!-- Único acceso al ÁREA DE ADMINISTRACIÓN (pantalla aparte). Solo lo ve el admin. -->
      <a href="metricas.php" style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.06);backdrop-filter:blur(9px);-webkit-backdrop-filter:blur(9px);border:1px solid rgba(255,255,255,.12);box-shadow:inset 0 1px 0 rgba(255,255,255,.10);border-radius:12px;padding:12px 14px;text-decoration:none;color:#cfe0d8">
        <span style="font-size:16px">🛡️</span>
        <span><span style="display:block;font-size:13.5px;font-weight:600;color:#fff">Panel de administración</span><span style="font-size:11.5px;color:#7fa494">Métricas · Gestión · Evaluación</span></span>
      </a>
    <?php endif; ?>

    <div style="background:rgba(255,255,255,.06);backdrop-filter:blur(9px);-webkit-backdrop-filter:blur(9px);border:1px solid rgba(255,255,255,.10);box-shadow:inset 0 1px 0 rgba(255,255,255,.10);border-radius:14px;padding:15px">
      <div style="font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:#7fa494;margin-bottom:8px">Cómo funciona</div>
      <div style="font-size:12.5px;color:#9db8ac;line-height:1.55">Cuéntale tu síntoma a la IA. Ella deduce el especialista y calcula tu copago real desde tu póliza.</div>
    </div>

    <div style="margin-top:auto;display:flex;flex-direction:column;gap:10px">
      <div style="background:rgba(255,255,255,.06);backdrop-filter:blur(9px);-webkit-backdrop-filter:blur(9px);border:1px solid rgba(255,255,255,.10);box-shadow:inset 0 1px 0 rgba(255,255,255,.10);border-radius:14px;padding:13px;display:flex;gap:11px;align-items:center">
        <div style="width:36px;height:36px;border-radius:50%;background:#2fbf71;color:#053023;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;flex-shrink:0"><?= $h($ini) ?></div>
        <div style="min-width:0"><div style="font-size:13.5px;color:#fff;font-weight:600"><?= $h($u['nombre']) ?></div><div style="font-size:12px;color:#7fa494;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $h($planTxt) ?></div></div>
      </div>
      <a href="logout.php" style="font-size:12.5px;color:#7fa494;text-align:center;padding:6px">Cerrar sesión</a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main" id="main-content">

    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div style="display:flex;align-items:flex-start;gap:14px">
        <button class="hamburger" onclick="toggleMenu()" aria-label="Menú"><span></span><span></span><span></span></button>
        <div>
          <div style="font-size:13px;color:var(--muted);margin-bottom:7px">Hola <?= $h($u['nombre']) ?> · tu estimación en tiempo real</div>
          <h1 class="sora" style="font-size:32px;font-weight:800;letter-spacing:-0.03em;line-height:1.1">El hospital más accesible para ti</h1>
        </div>
      </div>
      <button id="btnPdf" class="btn-ghost" onclick="descargarPDF()" disabled title="Haz una consulta para poder descargar el comprobante" style="opacity:.45;cursor:not-allowed">⬇ Descargar PDF</button>
    </div>

    <?php if ($correoNoVerificado): ?>
    <!-- Aviso suave de verificación de correo (#15) -->
    <div style="display:flex;gap:12px;align-items:flex-start;background:#fdf3e6;border:1px solid #f0d9b5;color:#8a5a12;border-radius:14px;padding:13px 16px;font-size:13.5px;line-height:1.5">
      <span style="font-size:17px">✉️</span>
      <div>
        <b>Confirma tu correo.</b> Te enviamos un enlace de verificación a <b><?= $h($u['email']) ?></b>. Revisa tu bandeja (y spam).
        <?php if ($verifyDemoLink): ?>
          <div style="margin-top:6px;word-break:break-all">Modo demo: <a href="<?= $h($verifyDemoLink) ?>" style="color:#8a5a12;text-decoration:underline">abre este enlace para verificar</a>.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php unset($_SESSION['verify_demo_link']); endif; ?>

    <!-- metric row -->
    <div class="metrics" id="resumen" style="scroll-margin-top:16px">
      <div class="hero" style="background:linear-gradient(135deg,#0f5c5c,#12786b);border:1px solid rgba(255,255,255,.10);border-radius:22px;padding:28px;color:#eafaf3;position:relative;overflow:hidden;box-shadow:0 26px 60px -34px rgba(15,92,92,.75),inset 0 1px 0 rgba(255,255,255,.12)">
        <div style="position:absolute;right:-30px;top:-30px;width:170px;height:170px;background:radial-gradient(circle,#2fbf71 0%,transparent 70%);opacity:.38"></div>
        <div style="position:absolute;left:-50px;bottom:-60px;width:180px;height:180px;background:radial-gradient(circle,#7ff0b3 0%,transparent 70%);opacity:.15"></div>
        <div style="display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.12);font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:6px 12px;border-radius:999px;margin-bottom:18px;position:relative">✦ Recomendado para ti</div>
        <div class="sora" id="m-hospital" style="font-size:22px;font-weight:700;position:relative">Aún sin estimación</div>
        <div style="display:flex;align-items:flex-end;gap:10px;margin-top:14px;position:relative"><span class="sora" id="m-copago" style="font-size:52px;font-weight:800;line-height:1;letter-spacing:-0.02em">—</span><span id="m-copago-sub" style="font-size:13.5px;opacity:.82;padding-bottom:11px">copago de esta visita</span></div>
      </div>
      <div class="card" style="border-radius:20px;display:flex;flex-direction:column;justify-content:center">
        <div style="font-size:13px;color:#7a8681">Ahorro vs. más caro</div>
        <div class="sora" id="m-ahorro" style="font-size:32px;font-weight:800;color:#128a4e;margin-top:8px">—</div>
        <div id="m-ahorro-sub" style="font-size:12.5px;color:#128a4e;margin-top:5px">eligiendo el recomendado</div>
      </div>
      <div class="card" style="border-radius:20px;display:flex;flex-direction:column;justify-content:center">
        <div style="font-size:13px;color:#7a8681">Cubre tu seguro</div>
        <div class="sora" id="m-cobertura" style="font-size:32px;font-weight:800;color:#10231f;margin-top:8px">—</div>
        <div style="font-size:12.5px;color:#7a8681;margin-top:5px">según tu póliza vigente</div>
      </div>
    </div>

    <!-- Triaje de urgencias (verde/amarillo/rojo) y aviso de autorización (se llenan por JS) -->
    <div id="triajeNotice"></div>
    <div id="authNotice"></div>

    <div class="cols">

      <!-- Desglose de la estimación -->
      <div class="card">
        <span class="eyebrow">Tu estimación</span>
        <h3 class="sora" style="font-size:15px;font-weight:600;margin-bottom:6px">Desglose de tu estimación</h3>
        <p class="muted" style="font-size:12.5px;margin-bottom:18px" id="desglose-sub">Cuéntale tu síntoma a la IA para ver el detalle.</p>
        <div id="desglose" style="display:flex;flex-direction:column">
          <div class="muted" style="font-size:13.5px;line-height:1.6;padding:8px 0">Cuando la IA identifique tu especialista, aquí verás la especialidad, el hospital recomendado, el porcentaje que cubre tu seguro y tu copago final.</div>
        </div>
      </div>

      <!-- Hospitales de tu red -->
      <div class="card">
        <span class="eyebrow">Comparativa</span>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px"><h3 class="sora" style="font-size:15px;font-weight:600">Hospitales de tu red</h3></div>
        <p class="muted" style="font-size:12.5px;margin-bottom:18px" id="comparacion-sub">Copago estimado en cada hospital (<?= $h($ciudadActual) ?>)</p>
        <div id="comparacion" style="display:flex;flex-direction:column;gap:16px">
          <div class="muted" style="font-size:13.5px;line-height:1.6">Aún sin datos. La comparación aparece cuando la IA calcula tu copago.</div>
        </div>
      </div>

    </div>

    <!-- Qué significa esto (ancho completo, 3 columnas responsivas) -->
    <div class="card">
      <span class="eyebrow">En simple</span>
      <h3 class="sora" style="font-size:15px;font-weight:600;margin-bottom:16px">Qué significa esto</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px">
        <div style="display:flex;gap:12px;align-items:flex-start"><span style="width:22px;height:22px;border-radius:50%;background:#e2f6ec;color:#128a4e;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">✓</span><span style="font-size:13.5px;line-height:1.55;color:#334741">El <b>copago</b> es lo único que pagas tú; el resto lo cubre tu seguro según tu plan.</span></div>
        <div style="display:flex;gap:12px;align-items:flex-start"><span style="width:22px;height:22px;border-radius:50%;background:#e2f6ec;color:#128a4e;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">✓</span><span style="font-size:13.5px;line-height:1.55;color:#334741">Comparamos los <b>hospitales de tu red</b> en tu ciudad y te mostramos el más económico.</span></div>
        <div style="display:flex;gap:12px;align-items:flex-start"><span style="width:22px;height:22px;border-radius:50%;background:#e2f6ec;color:#128a4e;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">✓</span><span style="font-size:13.5px;line-height:1.55;color:#334741">Los montos salen de <b>tu póliza real</b>, no son inventados por la IA.</span></div>
      </div>
    </div>
  </main>
</div>

<!-- ===== CHAT FLOTANTE: Clara siempre accesible con un botón ===== -->
<button id="chatFab" onclick="abrirChat()" aria-label="Abrir chat con Clara">
  <span class="ic">✦</span><span class="label">Preguntar a Clara</span>
</button>
<div id="chatDock" class="chat-dock" role="dialog" aria-label="Chat con Clara">
  <div class="chat-dock-head">
    <div style="width:32px;height:32px;border-radius:9px;background:#2fbf71;display:flex;align-items:center;justify-content:center;font-size:16px">✦</div>
    <div><div class="sora" style="font-weight:600;font-size:14px;color:#fff">Clara · Asistente IA</div><div style="font-size:11.5px;color:#7fa494">Te dice tu especialista y tu copago</div></div>
    <button class="x" onclick="cerrarChat()" aria-label="Cerrar chat">×</button>
  </div>
  <div id="chat-log" role="log" aria-live="polite" aria-label="Conversación con Clara, asistente de salud">
    <!-- las burbujas se agregan por JS -->
  </div>
  <div class="sugs" id="sugerencias">
    <button class="chip" onclick="preguntar('Tengo dolor en el pecho')">Dolor en el pecho</button>
    <button class="chip" onclick="preguntar('A mi bebé le dio fiebre')">Fiebre de mi bebé</button>
    <button class="chip" onclick="preguntar('Me lastimé el tobillo')">Me lastimé el tobillo</button>
  </div>
  <div class="inrow">
    <input id="chat-input" aria-label="Escribe tu síntoma o molestia" placeholder="Cuéntame cómo te sientes…" style="border:none;outline:none;flex:1;font-size:13.5px;color:#10231f;background:transparent" onkeydown="if(event.key==='Enter')enviar()">
    <button id="send-btn" onclick="enviar()" aria-label="Enviar mensaje a Clara" style="background:#2fbf71;border:none;width:36px;height:36px;border-radius:10px;color:#053023;font-size:17px;cursor:pointer">↑</button>
  </div>
</div>

<script>
  // Colapsar / mostrar la barra lateral con el botón hamburguesa
  function toggleMenu(){ document.getElementById('app').classList.toggle('collapsed'); }
  // En móvil, cierra el panel lateral tras elegir una opción del menú
  function cerrarMenuMovil(){ if(window.innerWidth <= 960){ document.getElementById('app').classList.remove('collapsed'); } }

  // Chat flotante de Clara: abrir / cerrar (accesible desde el botón permanente)
  function abrirChat(){
    var dock = document.getElementById('chatDock');
    document.getElementById('chatFab').style.display = 'none';
    var t = document.getElementById('btn-tema'); if(t) t.style.display = 'none';  // no tapar el chat
    dock.style.display = 'flex';        // visible, pero aún en el estado oculto del CSS
    void dock.offsetWidth;              // fuerza un "reflow" para capturar el estado inicial
    dock.classList.add('open');         // ahora sí: sube suave y fluido
    var log = document.getElementById('chat-log');
    if(log) log.scrollTop = log.scrollHeight;
    setTimeout(function(){ var i = document.getElementById('chat-input'); if(i) i.focus(); }, 130);
  }
  function cerrarChat(){
    var dock = document.getElementById('chatDock');
    dock.classList.remove('open');                              // anima la salida
    setTimeout(function(){ dock.style.display = ''; }, 480);    // oculta tras la animación
    document.getElementById('chatFab').style.display = '';
    var t = document.getElementById('btn-tema'); if(t) t.style.display = '';
  }

  // Cambiar la ciudad de atención: guarda en sesión, reinicia la conversación y recalcula
  let ciudadActual = <?= json_encode($ciudadActual) ?>;
  async function cambiarCiudad(ciudad){
    try {
      const r = await fetch(API + '?set_ciudad=' + encodeURIComponent(ciudad));
      const data = await r.json();
      if(!data.ok) throw new Error(data.error || 'No se pudo cambiar la ciudad');
      ciudadActual = ciudad;
      document.getElementById('comparacion-sub').textContent = 'Copago estimado en cada hospital (' + ciudad + ')';
      // Limpiar resultado anterior (ya no corresponde a esta ciudad)
      resetResultado();
      log.innerHTML = '';
      bubble('Listo, ahora buscaré hospitales en ' + ciudad + '. Cuéntame tu síntoma de nuevo.', false);
      cerrarMenuMovil();
    } catch(e){
      alert('Error: ' + e.message);
    }
  }
  function resetResultado(){
    document.getElementById('m-hospital').textContent = 'Aún sin estimación';
    document.getElementById('m-copago').textContent = '—';
    document.getElementById('m-copago-sub').textContent = 'copago de esta visita';
    document.getElementById('m-ahorro').textContent = '—';
    document.getElementById('m-cobertura').textContent = '—';
    document.getElementById('desglose-sub').textContent = 'Cuéntale tu síntoma a la IA para ver el detalle.';
    document.getElementById('desglose').innerHTML = '<div class="muted" style="font-size:13.5px;line-height:1.6;padding:8px 0">Cuando la IA identifique tu especialista, aquí verás el detalle.</div>';
    document.getElementById('comparacion').innerHTML = '<div class="muted" style="font-size:13.5px;line-height:1.6">Aún sin datos. La comparación aparece cuando la IA calcula tu copago.</div>';
    document.getElementById('authNotice').innerHTML = '';
    document.getElementById('triajeNotice').innerHTML = '';
    ultimoDatos = null; pdfBtn(false);
  }

  // Activa/desactiva el botón de descargar PDF
  function pdfBtn(on){
    const b = document.getElementById('btnPdf');
    b.disabled = !on;
    b.style.opacity = on ? '1' : '.45';
    b.style.cursor = on ? 'pointer' : 'not-allowed';
  }

  // Genera un comprobante imprimible (el navegador permite "Guardar como PDF")
  function descargarPDF(){
    const d = ultimoDatos;
    if(!d || !d.recomendado){ return; }
    const best = d.recomendado;
    const cobPct = Number(best.porcentaje_cobertura) || 0;
    const tarifa = Number(d.costo_referencia) || 0;
    const cubre = tarifa * cobPct / 100;
    const base = tarifa - cubre;
    const copFin = Number(best.copago);
    const ajuste = copFin - base;
    const reqAuth = d.requiere_autorizacion === true;
    const fecha = new Date().toLocaleString('es-EC', {dateStyle:'long', timeStyle:'short'});

    const filasHosp = (d.opciones||[]).map(o => {
      const mejor = o.nombre === best.nombre;
      return '<tr style="' + (mejor?'background:#e9f7ef;font-weight:600;':'') + '">' +
        '<td style="padding:8px 10px;border-bottom:1px solid #eee">' + esc(o.nombre) + (mejor?' ✓':'') + '</td>' +
        '<td style="padding:8px 10px;border-bottom:1px solid #eee;color:#555">' + esc(o.red||'') + '</td>' +
        '<td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right">' + money(o.copago) + '</td></tr>';
    }).join('');

    const filaCalc = (k,v,col) => '<tr><td style="padding:6px 0;color:#333">' + k + '</td><td style="padding:6px 0;text-align:right;color:' + (col||'#111') + '">' + v + '</td></tr>';

    const html =
      '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Comprobante de estimación</title>' +
      '<style>*{box-sizing:border-box;font-family:Arial,Helvetica,sans-serif}' +
      'body{margin:0;padding:36px;color:#111;font-size:13px}' +
      'h1{font-size:20px;margin:0}.muted{color:#777}' +
      'table{width:100%;border-collapse:collapse}' +
      '.tot{font-size:22px;font-weight:800;color:#0f5c5c}' +
      '@media print{@page{margin:16mm}}</style></head><body>' +

      '<div style="display:flex;align-items:center;gap:12px;border-bottom:2px solid #0f5c5c;padding-bottom:16px;margin-bottom:20px">' +
        '<div style="width:40px;height:40px;border-radius:10px;background:#2fbf71;color:#053023;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:22px">c</div>' +
        '<div><h1>Estimador Copago</h1><div class="muted">Comprobante de estimación</div></div>' +
        '<div style="margin-left:auto;text-align:right" class="muted">' + esc(fecha) + '</div>' +
      '</div>' +

      '<table style="margin-bottom:18px"><tr>' +
        '<td style="padding:4px 0"><b>Afiliado:</b> ' + esc(USUARIO) + '</td>' +
        '<td style="padding:4px 0;text-align:right"><b>Plan:</b> ' + esc(PLAN) + '</td></tr>' +
        '<tr><td style="padding:4px 0"><b>Especialidad:</b> ' + esc(d.especialidad||'') + '</td>' +
        '<td style="padding:4px 0;text-align:right"><b>Ciudad:</b> ' + esc(d.ciudad||'') + '</td></tr></table>' +

      '<div style="background:#0f5c5c;color:#fff;border-radius:12px;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">' +
        '<div><div style="opacity:.8;font-size:12px">Hospital recomendado (más económico)</div>' +
        '<div style="font-size:18px;font-weight:700;margin-top:3px">' + esc(best.nombre) + ' · ' + esc(best.red||'') + '</div></div>' +
        '<div style="text-align:right"><div style="opacity:.8;font-size:12px">Tu copago</div><div style="font-size:30px;font-weight:800">' + money(copFin) + '</div></div>' +
      '</div>' +

      '<h3 style="margin:0 0 8px">Desglose del copago</h3>' +
      '<table style="margin-bottom:8px">' +
        filaCalc('Tarifa de referencia', money(tarifa)) +
        filaCalc('El seguro cubre (' + cobPct + '%)', '– ' + money(cubre), '#128a4e') +
        '<tr><td style="padding:8px 0;border-top:2px solid #ddd"><b>Base a tu cargo (' + (100-cobPct) + '%)</b></td><td style="padding:8px 0;border-top:2px solid #ddd;text-align:right"><b>' + money(base) + '</b></td></tr>' +
        (Math.abs(ajuste)>=0.005 ? filaCalc('Ajuste por red (' + esc(best.red||'') + ')', (ajuste>=0?'+ ':'– ') + money(Math.abs(ajuste))) : '') +
        '<tr><td style="padding:10px 0;border-top:2px solid #0f5c5c"><b>Tu copago a pagar</b></td><td style="padding:10px 0;border-top:2px solid #0f5c5c;text-align:right" class="tot">' + money(copFin) + '</td></tr>' +
      '</table>' +

      '<div style="margin:14px 0;padding:12px 16px;border-radius:10px;background:' + (reqAuth?'#fdf3e6':'#e9f7ef') + ';color:' + (reqAuth?'#b5730f':'#128a4e') + '">' +
        (reqAuth ? '⚠ Esta atención <b>requiere autorización previa</b> de tu seguro. Tramítala antes de la cita.' : '✓ Esta atención <b>no requiere autorización previa</b>.') +
      '</div>' +

      '<h3 style="margin:18px 0 8px">Comparación de hospitales en ' + esc(d.ciudad||'') + '</h3>' +
      '<table style="border:1px solid #eee;border-radius:8px;overflow:hidden"><thead><tr style="background:#f4f4f2">' +
        '<th style="padding:8px 10px;text-align:left">Hospital</th><th style="padding:8px 10px;text-align:left">Red</th><th style="padding:8px 10px;text-align:right">Copago</th></tr></thead><tbody>' + filasHosp + '</tbody></table>' +

      '<p class="muted" style="margin-top:26px;font-size:11px;line-height:1.5;border-top:1px solid #eee;padding-top:14px">' +
        'Estimación referencial calculada a partir de tu póliza vigente. No constituye una factura ni una autorización médica. ' +
        'Los valores pueden variar según condiciones específicas de tu plan y del prestador. Generado por Estimador Copago.</p>' +

      '<script>window.onload=function(){window.print();}<\/script></body></html>';

    const w = window.open('', '_blank');
    if(!w){ alert('Permite las ventanas emergentes para descargar el PDF.'); return; }
    w.document.open(); w.document.write(html); w.document.close();
  }

  const API = 'chat.php';
  const USUARIO = <?= json_encode($u['nombre']) ?>;
  const PLAN = <?= json_encode($planTxt) ?>;
  let ultimoDatos = null;   // guarda el último resultado para el comprobante PDF
  const log = document.getElementById('chat-log');
  const input = document.getElementById('chat-input');
  const sendBtn = document.getElementById('send-btn');
  let busy = false;

  const money = n => '$' + Number(n).toFixed(2);
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  // ---- Burbujas de chat ----
  function bubble(text, mine){
    const b = document.createElement('div');
    b.style.cssText = mine
      ? 'align-self:flex-end;background:#2fbf71;color:#053023;font-size:13px;line-height:1.5;padding:11px 14px;border-radius:14px 14px 4px 14px;max-width:82%'
      : 'align-self:flex-start;background:#1c332d;color:#cfe0d8;font-size:13px;line-height:1.55;padding:11px 14px;border-radius:14px 14px 14px 4px;max-width:88%';
    b.innerHTML = esc(text);
    log.appendChild(b);
    log.scrollTop = log.scrollHeight;
    return b;
  }
  // Enruta el resultado de la herramienta que usó el agente al render correcto.
  //  - estimacion (ciudad actual)   -> pinta el dashboard completo
  //  - estimacion (otra ciudad)     -> tarjeta compacta en el chat (no toca el dashboard)
  //  - comparacion_planes           -> tarjeta comparativa en el chat
  //  - explicacion_cobertura        -> tarjeta de transparencia en el chat
  function manejarDatos(d){
    if(!d) return;
    if(d.tipo === 'comparacion_planes'){ cardComparacionPlanes(d); return; }
    if(d.tipo === 'explicacion_cobertura'){ cardExplicacionCobertura(d); return; }
    // estimacion (buscar_copago o buscar_por_ciudad)
    if(d.ciudad && d.ciudad !== ciudadActual){ cardEstimacionOtraCiudad(d); return; }
    if(d.recomendado || d.cubierto === false){ pintarResultado(d); irAResumen(); }
  }

  // Sube suavemente a la tarjeta de valores (copago/ahorro/cobertura) cuando la
  // IA acaba de calcular una estimación, para que el usuario la vea sin scrollear.
  function irAResumen(){
    const r = document.getElementById('resumen');
    if(r) r.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Inserta una tarjeta clara (para el chat oscuro) como burbuja de ancho completo.
  function chatCard(innerHTML){
    const c = document.createElement('div');
    c.style.cssText = 'align-self:stretch;background:#fff;color:#10231f;border-radius:14px;padding:15px 16px;font-size:13px;line-height:1.5';
    c.innerHTML = innerHTML;
    log.appendChild(c);
    log.scrollTop = log.scrollHeight;
    return c;
  }

  // #5 · Tarjeta: comparación de planes (qué conviene contratar)
  function cardComparacionPlanes(d){
    if(d.error || !Array.isArray(d.planes) || !d.planes.length){
      chatCard('<b>Comparar planes</b><div style="color:#7a8681;margin-top:6px">No hay planes con cobertura para ' + esc(d.especialidad||'esa especialidad') + ' en ' + esc(d.ciudad||'esa ciudad') + '.</div>');
      return;
    }
    const filas = d.planes.map((p,i) => {
      const mejor = i === 0;
      return '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #eef1ee">' +
        '<div style="min-width:0"><div style="font-weight:' + (mejor?'700':'500') + ';color:' + (mejor?'#0f5c5c':'#10231f') + '">' + esc(p.plan) +
          (mejor?' <span style="font-size:10.5px;background:#e2f6ec;color:#128a4e;padding:2px 6px;border-radius:6px;margin-left:4px">Conviene</span>':'') + '</div>' +
          '<div style="font-size:11.5px;color:#7a8681">Cubre ' + (p.porcentaje) + '% · deducible ' + money(p.deducible) + '</div></div>' +
        '<div class="sora" style="font-weight:' + (mejor?'800':'600') + ';color:' + (mejor?'#0f5c5c':'#334741') + ';white-space:nowrap">' + money(p.mejor_copago) + '</div></div>';
    }).join('');
    chatCard('<div style="font-weight:700;margin-bottom:4px">🏆 Qué plan te conviene · ' + esc(d.especialidad) + ' en ' + esc(d.ciudad) + '</div>' +
      '<div style="font-size:11.5px;color:#7a8681;margin-bottom:8px">Copago más bajo disponible por plan (de menor a mayor)</div>' + filas);
  }

  // #5 · Tarjeta: explicación de la cobertura (transparencia del cálculo)
  function cardExplicacionCobertura(d){
    if(d.error){ chatCard('<b>Tu cobertura</b><div style="color:#7a8681;margin-top:6px">' + esc(d.error) + '</div>'); return; }
    let html = '<div style="font-weight:700;margin-bottom:8px">🔎 Cómo funciona tu cobertura</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">' +
        '<span style="background:#eef1ee;color:#334741;padding:4px 10px;border-radius:8px;font-size:12px">Cubre <b>' + d.porcentaje + '%</b></span>' +
        '<span style="background:#eef1ee;color:#334741;padding:4px 10px;border-radius:8px;font-size:12px">Deducible <b>' + money(d.deducible) + '</b></span>' +
      '</div>';
    if(d.ejemplo){
      const e = d.ejemplo;
      html += '<div style="font-size:12px;color:#7a8681;margin-bottom:4px">Ejemplo en ' + esc(d.especialidad||'') + ':</div>' +
        '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #eef1ee"><span>Tarifa de referencia</span><b>' + money(e.tarifa) + '</b></div>' +
        '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #eef1ee;color:#128a4e"><span>El seguro cubre (' + d.porcentaje + '%)</span><b>– ' + money(e.cubre_seguro) + '</b></div>' +
        '<div style="display:flex;justify-content:space-between;padding:7px 0"><span style="font-weight:600">Base a tu cargo</span><b class="sora" style="color:#0f5c5c">' + money(e.base_paciente) + '</b></div>';
    }
    html += '<div style="font-size:11px;color:#a2aca7;margin-top:8px">Los montos exactos por hospital salen de tu póliza (SQL), no del modelo.</div>';
    chatCard(html);
  }

  // #5 · Tarjeta: estimación en OTRA ciudad (no repinta el dashboard de la ciudad actual)
  function cardEstimacionOtraCiudad(d){
    if(d.cubierto === false || !d.recomendado){
      chatCard('<b>En ' + esc(d.ciudad||'') + '</b><div style="color:#7a8681;margin-top:6px">Tu plan no cubre ' + esc(d.especialidad||'esa especialidad') + ' en ' + esc(d.ciudad||'esa ciudad') + '.</div>');
      return;
    }
    const opts = (d.opciones||[]).slice(0,4).map((o,i) => {
      const mejor = o.nombre === d.recomendado.nombre;
      return '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eef1ee">' +
        '<span style="color:' + (mejor?'#0f5c5c':'#334741') + ';font-weight:' + (mejor?'600':'400') + '">' + esc(o.nombre) + (mejor?' ✓':'') + '</span>' +
        '<b style="color:' + (mejor?'#0f5c5c':'#334741') + '">' + money(o.copago) + '</b></div>';
    }).join('');
    chatCard('<div style="font-weight:700;margin-bottom:6px">📍 ' + esc(d.especialidad||'') + ' en ' + esc(d.ciudad) + '</div>' + opts +
      '<div style="font-size:11px;color:#a2aca7;margin-top:8px">Esta consulta no cambió tu ciudad seleccionada (' + esc(ciudadActual) + ').</div>');
  }

  // Nota discreta cuando se activa una defensa (guardrail) del agente.
  // Es evidencia visible del principio anti-alucinación de la tesis.
  function notaGuardrail(codigos){
    const texto = {
      'G1_mensaje_recortado':               'Se recortó tu mensaje por seguridad (largo máximo).',
      'G2_especialidad_invalida':           'La IA propuso una especialidad inexistente y el sistema la rechazó.',
      'G3_monto_alucinado_neutralizado':    'La IA intentó dar un monto y el sistema lo bloqueó: los valores solo salen de tu póliza (SQL).',
    };
    codigos.forEach(c => {
      const n = document.createElement('div');
      n.style.cssText = 'align-self:center;background:#152b25;color:#7fd7a8;border:1px solid #2c463f;font-size:11.5px;line-height:1.45;padding:7px 12px;border-radius:10px;max-width:92%;text-align:center';
      n.innerHTML = '🛡️ ' + esc(texto[c] || ('Guardrail activado: ' + c));
      log.appendChild(n);
    });
    log.scrollTop = log.scrollHeight;
  }

  // #11 · Indicador animado de "Clara está pensando" (puntos que rebotan)
  function typing(){
    const b = document.createElement('div');
    b.setAttribute('aria-label', 'Clara está escribiendo');
    b.style.cssText = 'align-self:flex-start;background:#1c332d;color:#7fa494;font-size:13px;padding:12px 16px;border-radius:14px 14px 14px 4px';
    b.innerHTML = '<span class="dots" aria-hidden="true"><span></span><span></span><span></span></span>';
    log.appendChild(b); log.scrollTop = log.scrollHeight;
    return b;
  }

  // #11 · Skeletons de carga en las tarjetas de resultado (solo en la 1ª estimación,
  //       para no borrar un resultado previo mientras Clara responde una pregunta).
  function mostrarSkeletons(){
    if(ultimoDatos) return;               // ya hay un resultado: no lo tapamos
    document.getElementById('m-hospital').innerHTML = '<span class="sk" style="display:inline-block;width:150px;height:20px"></span>';
    document.getElementById('m-copago').innerHTML   = '<span class="sk" style="display:inline-block;width:110px;height:40px;background:rgba(255,255,255,.25)"></span>';
    const filaSk = '<div class="sk" style="height:16px;margin:12px 0"></div>';
    document.getElementById('desglose').innerHTML   = filaSk + filaSk + filaSk;
    document.getElementById('comparacion').innerHTML = filaSk + filaSk + filaSk;
  }
  function quitarSkeletons(){
    // Si tras responder no hubo estimación nueva y no había una previa, vuelve al vacío.
    if(ultimoDatos) return;
    if(document.querySelector('#m-copago .sk')){ resetResultado(); }
  }

  // #14 · Animación count-up: el número sube suave hasta su valor final.
  function countUp(el, destino, {prefijo='', sufijo='', dec=2, ms=650}={}){
    const inicio = performance.now();
    const paso = (ahora) => {
      const t = Math.min(1, (ahora - inicio) / ms);
      const e = 1 - Math.pow(1 - t, 3);          // easing suave (easeOutCubic)
      el.textContent = prefijo + (destino * e).toFixed(dec) + sufijo;
      if(t < 1) requestAnimationFrame(paso);
      else el.textContent = prefijo + destino.toFixed(dec) + sufijo;
    };
    requestAnimationFrame(paso);
  }

  // ---- Pintar el resultado real en el dashboard ----
  function pintarResultado(d){
    if(!d) return;
    const auth = document.getElementById('authNotice');

    // Triaje de urgencias (#6): siempre que haya estimación, muéstralo arriba.
    pintarTriaje(d.triaje);

    // Caso: el plan NO cubre esta especialidad en la ciudad elegida
    if(d.cubierto === false || !d.recomendado){
      document.getElementById('m-hospital').textContent = 'No cubierto';
      document.getElementById('m-copago').textContent = '—';
      document.getElementById('m-copago-sub').textContent = 'en ' + (d.ciudad || 'tu ciudad');
      document.getElementById('m-ahorro').textContent = '—';
      document.getElementById('m-cobertura').textContent = '—';
      auth.innerHTML = banner('#fdeaea', '#f3c6c6', '#b23c3c', '✕',
        'Tu plan <b>' + esc(d.plan || '') + '</b> no cubre <b>' + esc(d.especialidad || 'esta especialidad') +
        '</b> en <b>' + esc(d.ciudad || '') + '</b>. Prueba otra ciudad o consulta con tu aseguradora.');
      document.getElementById('desglose-sub').textContent = 'Sin cobertura para esta especialidad.';
      document.getElementById('desglose').innerHTML = '<div class="muted" style="font-size:13.5px;line-height:1.6;padding:8px 0">No hay hospitales de tu red con cobertura para ' + esc(d.especialidad || 'esta especialidad') + ' en ' + esc(d.ciudad || 'tu ciudad') + '.</div>';
      document.getElementById('comparacion').innerHTML = '<div class="muted" style="font-size:13.5px;line-height:1.6">Sin hospitales cubiertos en esta ciudad.</div>';
      ultimoDatos = null; pdfBtn(false);
      return;
    }

    const best = d.recomendado;
    const opciones = d.opciones || [];
    const copagos = opciones.map(o => Number(o.copago));
    const maxC = Math.max(...copagos, Number(best.copago));
    const ahorro = maxC - Number(best.copago);
    const reqAuth = d.requiere_autorizacion === true;
    ultimoDatos = d; pdfBtn(true);   // habilita el comprobante PDF

    // Métricas (con animación count-up #14: los números suben suave hasta su valor)
    document.getElementById('m-hospital').textContent = best.nombre;
    countUp(document.getElementById('m-copago'), Number(best.copago), {prefijo:'$', dec:2});
    document.getElementById('m-copago-sub').textContent = 'copago en ' + (d.especialidad || 'tu consulta');
    countUp(document.getElementById('m-ahorro'), Number(ahorro), {prefijo:'$', dec:2});
    if(best.porcentaje_cobertura == null){
      document.getElementById('m-cobertura').textContent = '—';
    } else {
      countUp(document.getElementById('m-cobertura'), Number(best.porcentaje_cobertura), {sufijo:'%', dec:0});
    }

    // Aviso de autorización previa (dato real del SQL, no inventado)
    if(reqAuth){
      auth.innerHTML = banner('#fdf3e6', '#f0d9b5', '#b5730f', '⚠',
        '<b>Requiere autorización previa</b> de tu seguro. Tramítala <b>antes</b> de tu cita para que apliquen estos valores y evitar cobros completos.');
    } else {
      auth.innerHTML = banner('#e9f7ef', '#c6ead5', '#128a4e', '✓',
        '<b>No requiere autorización previa.</b> Puedes agendar tu cita directamente con estos valores.');
    }

    // Desglose paso a paso: de la tarifa base -> lo que cubre el seguro -> tu copago
    document.getElementById('desglose-sub').textContent = 'Especialidad sugerida: ' + (d.especialidad || '—') + ' · así se calcula tu copago';
    const cobPct  = Number(best.porcentaje_cobertura) || 0;
    const tarifa  = Number(d.costo_referencia) || 0;
    const cubre   = tarifa * cobPct / 100;
    const base    = tarifa - cubre;
    const copFin  = Number(best.copago);
    const ajuste  = copFin - base;               // efecto del factor de red del hospital
    let dh = fila('Hospital recomendado', best.nombre + ' · ' + (best.red || ''), '');
    if (tarifa > 0) {
      dh += fila('Tarifa de referencia', money(tarifa), '');
      dh += fila('El seguro cubre (' + cobPct + '%)', '– ' + money(cubre), 'verde');
      dh += filaSubtotal('Base a tu cargo (' + (100 - cobPct) + '%)', money(base));
      if (Math.abs(ajuste) >= 0.005) {
        dh += fila('Ajuste por red (' + (best.red || 'hospital') + ')', (ajuste >= 0 ? '+ ' : '– ') + money(Math.abs(ajuste)), '');
      }
    }
    dh += fila('Autorización previa', reqAuth ? 'Requerida' : 'No requerida', reqAuth ? 'naranja' : 'verde');
    dh += filaTotal('Tu copago a pagar', money(copFin));
    document.getElementById('desglose').innerHTML = dh;

    // Comparación de hospitales (barras)
    if(d.ciudad){ document.getElementById('comparacion-sub').textContent = 'Copago estimado en cada hospital (' + d.ciudad + ')'; }
    const cont = document.getElementById('comparacion');
    cont.innerHTML = '';
    opciones.forEach((o, i) => {
      const pct = maxC > 0 ? Math.max(8, Math.round(Number(o.copago) / maxC * 100)) : 100;
      const esMejor = o.nombre === best.nombre;
      const color = esMejor ? '#2fbf71' : (i % 2 ? '#c9b39a' : '#9ab4ab');
      const nameColor = esMejor ? '#0f5c5c' : '#334741';
      const badge = esMejor ? ' <span style="font-size:11px;background:#e2f6ec;color:#128a4e;padding:2px 7px;border-radius:6px;font-weight:600;margin-left:4px">Mejor</span>' : '';
      cont.innerHTML +=
        '<div><div style="display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:6px">' +
        '<span style="font-weight:' + (esMejor?'600':'400') + ';color:' + nameColor + '">' + esc(o.nombre) + badge + '</span>' +
        '<span style="font-weight:' + (esMejor?'700':'400') + ';color:' + nameColor + '">' + money(o.copago) + '</span></div>' +
        '<div style="height:11px;background:#eef1ee;border-radius:6px;overflow:hidden"><div style="width:' + pct + '%;height:100%;background:' + color + ';border-radius:6px"></div></div></div>';
    });
  }
  // Triaje de urgencias (#6): banner de color según el nivel verde/amarillo/rojo.
  function pintarTriaje(t){
    const cont = document.getElementById('triajeNotice');
    if(!t || !t.nivel){ cont.innerHTML = ''; return; }
    const estilos = {
      rojo:     {bg:'#fdeaea', borde:'#f3c6c6', color:'#b23c3c', icono:'🚨'},
      amarillo: {bg:'#fdf3e6', borde:'#f0d9b5', color:'#b5730f', icono:'⚠️'},
      verde:    {bg:'#e9f7ef', borde:'#c6ead5', color:'#128a4e', icono:'🟢'},
    };
    const s = estilos[t.nivel] || estilos.verde;
    const nota = t.escalado_por_seguridad
      ? '<div style="font-size:11.5px;margin-top:6px;opacity:.85">🛡️ Nivel elevado automáticamente por la red de seguridad clínica (ante la duda, se prioriza tu seguridad).</div>'
      : '';
    cont.innerHTML =
      '<div style="display:flex;gap:13px;align-items:flex-start;background:' + s.bg + ';border:1px solid ' + s.borde +
        ';border-radius:16px;padding:15px 18px' + (t.nivel==='rojo' ? ';box-shadow:0 0 0 3px rgba(178,60,60,.12)' : '') + '">' +
        '<span style="font-size:20px;line-height:1.2">' + s.icono + '</span>' +
        '<div style="color:' + s.color + '">' +
          '<div class="sora" style="font-weight:700;font-size:14.5px;margin-bottom:2px">Triaje: ' + esc(t.etiqueta) + '</div>' +
          '<div style="font-size:13px;line-height:1.5">' + esc(t.mensaje) + '</div>' + nota +
        '</div></div>';
    // Para emergencias, además deja una nota visible en el chat.
    if(t.emergencia){
      const n = document.createElement('div');
      n.style.cssText = 'align-self:stretch;background:#3a1414;color:#ffb4b4;border:1px solid #6b2626;font-size:12.5px;line-height:1.5;padding:10px 13px;border-radius:12px;font-weight:600';
      n.innerHTML = '🚨 ' + esc(t.mensaje);
      log.appendChild(n); log.scrollTop = log.scrollHeight;
    }
  }

  // Banner de aviso (autorización / cobertura)
  function banner(bg, borde, texto, icono, html){
    return '<div style="display:flex;gap:12px;align-items:flex-start;background:' + bg + ';border:1px solid ' + borde +
      ';border-radius:16px;padding:15px 18px">' +
      '<span style="width:24px;height:24px;border-radius:50%;background:#fff;color:' + texto +
      ';display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0">' + icono + '</span>' +
      '<span style="font-size:13.5px;line-height:1.55;color:' + texto + '">' + html + '</span></div>';
  }
  function fila(k, v, tono){
    const col = tono === 'verde' ? '#128a4e' : (tono === 'naranja' ? '#b5730f' : '#10231f');
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #eef1ee">' +
      '<div style="font-size:14px;font-weight:500">' + esc(k) + '</div>' +
      '<span class="sora" style="font-weight:600;font-size:15px;color:' + col + '">' + esc(v) + '</span></div>';
  }
  function filaSubtotal(k, v){
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-top:2px solid #e6e6e2;border-bottom:1px solid #eef1ee">' +
      '<div style="font-size:14px;font-weight:600;color:#334741">' + esc(k) + '</div>' +
      '<span class="sora" style="font-weight:700;font-size:15px;color:#334741">' + esc(v) + '</span></div>';
  }
  function filaTotal(k, v){
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 0 0">' +
      '<span style="font-size:14px;font-weight:600">' + esc(k) + '</span>' +
      '<span class="sora" style="font-weight:800;font-size:20px;color:#0f5c5c">' + esc(v) + '</span></div>';
  }

  // ---- Hablar con la IA real ----
  async function enviar(){
    const msg = input.value.trim();
    if(!msg || busy) return;
    busy = true; sendBtn.disabled = true;
    bubble(msg, true);
    input.value = '';
    const t = typing();
    mostrarSkeletons();          // #11 · esqueletos mientras llega la estimación
    try {
      const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({mensaje: msg})});
      const data = await r.json();
      t.remove();
      if(!data.ok){
        bubble('⚠️ ' + (data.error || 'Ocurrió un error.'), false);
      } else {
        bubble(data.respuesta || '¿Podrías darme un poco más de detalle?', false);
        if(Array.isArray(data.guardrails) && data.guardrails.length){
          notaGuardrail(data.guardrails);
        }
        manejarDatos(data.datos);
      }
    } catch(e){
      t.remove();
      bubble('⚠️ Sin conexión con el servidor. Revisa que el servidor y la base de datos estén activos.', false);
    }
    quitarSkeletons();           // si no hubo estimación nueva, vuelve al estado vacío
    busy = false; sendBtn.disabled = false;
    input.focus({ preventScroll: true });   // mantiene el foco sin cancelar el scroll a los valores
  }
  function preguntar(txt){ input.value = txt; enviar(); }

  async function reiniciar(){
    try { await fetch(API + '?reset=1'); } catch(e){}
    log.innerHTML = '';
    bubble('Listo, empecemos de nuevo. Cuéntame qué síntoma tienes.', false);
  }

  // #13 · Accesibilidad por teclado: los ítems del menú son <div> con onclick.
  // Los hacemos enfocables y activables con Enter/Espacio, como un botón real.
  document.querySelectorAll('.nav-item').forEach(el => {
    if(el.tagName === 'A') return;            // los enlaces ya son accesibles
    el.setAttribute('role', 'button');
    el.setAttribute('tabindex', '0');
    el.addEventListener('keydown', e => {
      if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); el.click(); }
    });
  });

  // Saludo inicial
  bubble('Hola <?= $h($u['nombre']) ?>, soy Clara. Cuéntame qué molestia o síntoma tienes y te diré a qué especialista ir y cuánto pagarías.', false);
  input.focus();
</script>
<script src="theme.js"></script>
</body>
</html>

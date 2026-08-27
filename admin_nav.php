<?php
// admin_nav.php  -  Barra de navegación del ÁREA DE ADMINISTRACIÓN.
// Da identidad propia y separada del área del paciente. Incluir dentro de .wrap.
// Antes de incluirlo, define: $activo = 'metricas' | 'gestion' | 'evaluar'.
$activo = $activo ?? '';
$adm = function_exists('usuarioActual') ? usuarioActual() : null;
$navItems = [
    'metricas' => ['Métricas',      'metricas.php', '▤'],
    'usuarios' => ['Usuarios',      'usuarios.php', '👥'],
    'gestion'  => ['Gestión',       'admin.php',    '⚙'],
    'evaluar'  => ['Evaluación IA',  'evaluar.php',  '◈'],
];
?>
<div style="background:rgba(13,31,27,.72);backdrop-filter:blur(18px) saturate(1.4);-webkit-backdrop-filter:blur(18px) saturate(1.4);border:1px solid rgba(255,255,255,.09);border-radius:16px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;position:sticky;top:12px;z-index:100;box-shadow:0 14px 38px -20px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.10)">
  <div style="display:flex;align-items:center;gap:10px">
    <div style="width:32px;height:32px;border-radius:9px;background:#2fbf71;display:flex;align-items:center;justify-content:center;font-size:16px">🛡️</div>
    <div>
      <div style="font-family:'Sora',sans-serif;font-weight:700;font-size:14.5px;color:#fff">Panel de administración</div>
      <div style="font-size:11.5px;color:#7fa494">Estimador Copago<?= $adm ? ' · ' . htmlspecialchars($adm['nombre'], ENT_QUOTES, 'UTF-8') : '' ?></div>
    </div>
  </div>
  <nav style="display:flex;gap:6px;margin-left:6px;flex-wrap:wrap">
    <?php foreach ($navItems as $k => $it): $on = $k === $activo; ?>
      <a href="<?= $it[1] ?>" style="font-size:13px;padding:8px 13px;border-radius:9px;text-decoration:none;transition:background .2s ease;<?= $on ? 'background:rgba(255,255,255,.16);color:#fff;font-weight:600;box-shadow:inset 0 1px 0 rgba(255,255,255,.18)' : 'color:#9db8ac' ?>"><?= $it[2] ?> <?= htmlspecialchars($it[0], ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </nav>
  <div style="margin-left:auto;display:flex;align-items:center;gap:14px">
    <a href="index.php" style="font-size:12.5px;color:#9db8ac">↗ Ir al estimador</a>
    <a href="logout.php" style="font-size:12.5px;color:#7fa494">Cerrar sesión</a>
  </div>
</div>

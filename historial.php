<?php
// historial.php  -  Muestra el historial de estimaciones del usuario logueado.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
requiereLogin();

$u = usuarioActual();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$consultas = [];
$resumen   = ['total' => 0, 'promedio' => 0, 'top_esp' => '—'];
try {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT sintoma, especialidad, ciudad, hospital, red, copago,
                porcentaje_cobertura, requiere_autorizacion, creado_en
         FROM consultas WHERE usuario_id = ? ORDER BY creado_en DESC, id DESC"
    );
    $stmt->execute([$u['id']]);
    $consultas = $stmt->fetchAll();

    $resumen['total'] = count($consultas);
    if ($resumen['total']) {
        $resumen['promedio'] = array_sum(array_column($consultas, 'copago')) / $resumen['total'];
        $cuenta = array_count_values(array_filter(array_column($consultas, 'especialidad')));
        if ($cuenta) {
            arsort($cuenta);
            $resumen['top_esp'] = array_key_first($cuenta);
        }
    }
} catch (Throwable $e) {
    $errorBD = $e->getMessage();
}

// Formatea "2026-07-20 22:41:03" -> "20 jul 2026, 22:41"
function fechaLinda(?string $ts): string {
    if (!$ts) return '';
    $meses = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',
              7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    $t = strtotime($ts);
    return date('j', $t) . ' ' . $meses[(int)date('n', $t)] . ' ' . date('Y', $t) . ', ' . date('H:i', $t);
}
$money = fn($n) => '$' . number_format((float)$n, 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimador Copago — Historial</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@0,400;0,500;0,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="brand.css">
<script>(function(){try{var t=localStorage.getItem('tema');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);font-family:'IBM Plex Sans',sans-serif;color:var(--text);min-height:100vh;padding:24px 16px}
  a{color:var(--marca);text-decoration:none}
  .sora{font-family:'Sora',sans-serif}
  .wrap{max-width:900px;margin:0 auto}
  .card{background:var(--surface);border:1px solid var(--borde);border-radius:18px;padding:22px 24px;color:var(--text)}
  .chip{display:inline-block;font-size:11.5px;background:var(--surface-2);color:var(--muted);padding:4px 10px;border-radius:8px;font-weight:500}
  .btn-ghost{background:var(--surface);border:1px solid var(--field-border);color:var(--text);font-size:13.5px;font-weight:500;padding:10px 16px;border-radius:11px;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
  .btn-ghost:hover{border-color:var(--marca);color:var(--marca)}
  .badge-auth{font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:8px}
  @media(max-width:560px){body{padding:14px 10px}.card{padding:18px}}
</style>
</head>
<body>
<div class="wrap">

  <!-- Encabezado -->
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:22px">
    <div style="display:flex;align-items:center;gap:11px">
      <div class="sora" style="width:38px;height:38px;border-radius:11px;background:#2fbf71;display:flex;align-items:center;justify-content:center;color:#053023;font-weight:700;font-size:19px">c</div>
      <div>
        <div class="sora" style="font-weight:700;font-size:17px">Estimador Copago</div>
        <div style="font-size:12.5px;color:#7a8681"><?= $h($u['nombre']) ?></div>
      </div>
    </div>
    <a href="index.php" class="btn-ghost">← Volver al estimador</a>
  </div>

  <h1 class="sora" style="font-size:26px;font-weight:700;letter-spacing:-0.02em;margin-bottom:6px">Tu historial de consultas</h1>
  <p style="font-size:14px;color:#7a8681;margin-bottom:20px">Cada estimación que la IA calcula para ti queda guardada aquí.</p>

  <?php if (!empty($errorBD)): ?>
    <div class="card" style="background:#fdeaea;border-color:#f3c6c6;color:#b23c3c">⚠️ No se pudo cargar el historial: <?= $h($errorBD) ?></div>

  <?php elseif ($resumen['total'] === 0): ?>
    <div class="card" style="text-align:center;padding:44px 24px">
      <div style="font-size:34px;margin-bottom:10px">🗒️</div>
      <div class="sora" style="font-size:17px;font-weight:600;margin-bottom:6px">Aún no tienes consultas</div>
      <p style="font-size:13.5px;color:#7a8681;margin-bottom:18px">Haz tu primera estimación y aparecerá en este historial.</p>
      <a href="index.php" class="btn-ghost" style="background:#0f5c5c;color:#fff;border-color:#0f5c5c">Ir al estimador</a>
    </div>

  <?php else: ?>
    <!-- Resumen -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px">
      <div class="card" style="padding:18px"><div style="font-size:12.5px;color:#7a8681">Consultas hechas</div><div class="sora" style="font-size:26px;font-weight:800;margin-top:4px"><?= $resumen['total'] ?></div></div>
      <div class="card" style="padding:18px"><div style="font-size:12.5px;color:#7a8681">Copago promedio</div><div class="sora" style="font-size:26px;font-weight:800;margin-top:4px;color:#0f5c5c"><?= $money($resumen['promedio']) ?></div></div>
      <div class="card" style="padding:18px"><div style="font-size:12.5px;color:#7a8681">Especialidad frecuente</div><div class="sora" style="font-size:16px;font-weight:700;margin-top:8px"><?= $h($resumen['top_esp']) ?></div></div>
    </div>

    <!-- Lista de consultas -->
    <div style="display:flex;flex-direction:column;gap:14px">
      <?php foreach ($consultas as $c): ?>
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
            <div style="min-width:0;flex:1">
              <div style="font-size:12px;color:#a2aca7;margin-bottom:6px"><?= $h(fechaLinda($c['creado_en'])) ?></div>
              <div style="font-size:15px;font-weight:600;line-height:1.4;margin-bottom:10px">“<?= $h($c['sintoma'] ?: 'Consulta') ?>”</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <span class="chip"><?= $h($c['especialidad'] ?: '—') ?></span>
                <span class="chip"><?= $h($c['ciudad'] ?: '—') ?></span>
                <span class="chip">Cubre <?= (int)$c['porcentaje_cobertura'] ?>%</span>
                <?php if ($c['requiere_autorizacion']): ?>
                  <span class="badge-auth" style="background:#fdf3e6;color:#b5730f">⚠ Requiere autorización</span>
                <?php else: ?>
                  <span class="badge-auth" style="background:#e9f7ef;color:#128a4e">✓ Sin autorización</span>
                <?php endif; ?>
              </div>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <div class="sora" style="font-size:24px;font-weight:800;color:#0f5c5c"><?= $money($c['copago']) ?></div>
              <div style="font-size:12.5px;color:#7a8681;margin-top:2px"><?= $h($c['hospital'] ?: '') ?></div>
              <div style="font-size:11.5px;color:#a2aca7"><?= $h($c['red'] ?: '') ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
<script src="theme.js"></script>
</body>
</html>

<?php
// metricas.php  -  Dashboard de métricas del sistema (#10). Solo administradores.
// Lee la tabla `consultas` (historial de TODOS los usuarios) y muestra
// indicadores agregados: total, especialidad más pedida, ahorro promedio, etc.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
requiereAdmin();

$u = usuarioActual();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$money = fn($n) => '$' . number_format((float) $n, 2);

$db = getDB();
$err = null;
$m = [
    'total' => 0, 'usuarios' => 0, 'copago_prom' => 0, 'ahorro_prom' => 0, 'ahorro_total' => 0,
    'pct_auth' => 0, 'top_esp' => '—',
];
$porEsp = $porCiudad = $porDia = $porHospital = [];

try {
    $m['total']    = (int) $db->query("SELECT COUNT(*) FROM consultas")->fetchColumn();
    $m['usuarios'] = (int) $db->query("SELECT COUNT(DISTINCT usuario_id) FROM consultas")->fetchColumn();

    if ($m['total'] > 0) {
        $m['copago_prom'] = (float) $db->query("SELECT AVG(copago) FROM consultas WHERE copago IS NOT NULL")->fetchColumn();
        $m['pct_auth']    = (float) $db->query("SELECT AVG(requiere_autorizacion)*100 FROM consultas")->fetchColumn();

        // Distribución por especialidad (para "más pedida" + gráfico de barras)
        $porEsp = $db->query(
            "SELECT especialidad, COUNT(*) n FROM consultas
             WHERE especialidad IS NOT NULL AND especialidad <> ''
             GROUP BY especialidad ORDER BY n DESC"
        )->fetchAll();
        if ($porEsp) { $m['top_esp'] = $porEsp[0]['especialidad']; }

        // Distribución por ciudad
        $porCiudad = $db->query(
            "SELECT ciudad, COUNT(*) n FROM consultas
             WHERE ciudad IS NOT NULL AND ciudad <> ''
             GROUP BY ciudad ORDER BY n DESC"
        )->fetchAll();

        // Hospitales más recomendados
        $porHospital = $db->query(
            "SELECT hospital, COUNT(*) n FROM consultas
             WHERE hospital IS NOT NULL AND hospital <> ''
             GROUP BY hospital ORDER BY n DESC LIMIT 5"
        )->fetchAll();

        // Consultas por día (últimos 14 días con actividad)
        $porDia = $db->query(
            "SELECT DATE(creado_en) d, COUNT(*) n FROM consultas
             GROUP BY DATE(creado_en) ORDER BY d DESC LIMIT 14"
        )->fetchAll();
        $porDia = array_reverse($porDia);   // cronológico ascendente

        // Ahorro promedio GENERADO: por cada consulta, cuánto se ahorró el paciente
        // eligiendo el hospital recomendado (más barato) frente al más caro de su
        // red, con su plan y ciudad. Se recalcula desde las coberturas reales (SQL).
        $ahorro = $db->query(
            "SELECT AVG(sub.ahorro) prom, SUM(sub.ahorro) total FROM (
                SELECT (
                    (SELECT MAX(cob.copago) FROM coberturas cob
                       JOIN hospitales hh ON hh.id = cob.hospital_id
                      WHERE cob.plan_id = u.plan_id
                        AND cob.especialidad_id = e.id
                        AND hh.ciudad = c.ciudad) - c.copago
                ) AS ahorro
                FROM consultas c
                JOIN usuarios u       ON u.id = c.usuario_id
                JOIN especialidades e ON e.nombre = c.especialidad
                WHERE c.copago IS NOT NULL
            ) sub
            WHERE sub.ahorro IS NOT NULL"
        )->fetch();
        $m['ahorro_prom']  = (float) ($ahorro['prom'] ?? 0);
        $m['ahorro_total'] = (float) ($ahorro['total'] ?? 0);
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Máximos para escalar las barras
$maxEsp  = $porEsp ? max(array_column($porEsp, 'n')) : 1;
$maxCiu  = $porCiudad ? max(array_column($porCiudad, 'n')) : 1;
$maxDia  = $porDia ? max(array_column($porDia, 'n')) : 1;
$maxHosp = $porHospital ? max(array_column($porHospital, 'n')) : 1;

$mesEs = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
$diaLindo = function ($ymd) use ($mesEs) {
    $t = strtotime($ymd);
    return (int) date('j', $t) . ' ' . $mesEs[(int) date('n', $t)];
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimador Copago — Métricas</title>
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
  .wrap{max-width:1000px;margin:0 auto}
  .card{background:var(--surface);border:1px solid var(--borde);border-radius:16px;padding:20px 22px;color:var(--text)}
  .btn-ghost{background:var(--surface);border:1px solid var(--field-border);color:var(--text);font-size:13.5px;font-weight:500;padding:10px 16px;border-radius:11px;cursor:pointer;display:inline-flex;gap:7px}
  .btn-ghost:hover{border-color:var(--marca);color:var(--marca)}
  .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
  .kpi .n{font-family:'Sora',sans-serif;font-weight:800;font-size:30px;margin-top:6px}
  .muted{color:var(--muted);font-size:12.5px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
  .row{display:flex;align-items:center;gap:10px;margin:9px 0}
  .row .lbl{width:130px;font-size:13px;flex-shrink:0}
  .row .bar{flex:1;height:12px;background:var(--surface-2);border-radius:6px;overflow:hidden}
  .row .bar>div{height:100%;border-radius:6px}
  .row .val{width:34px;text-align:right;font-weight:600;font-size:13px}
  h3.t{font-size:15px;font-weight:600;margin-bottom:14px}
  .chip{display:inline-block;font-size:11.5px;background:var(--surface-2);color:var(--muted);padding:4px 10px;border-radius:8px}
  @media(max-width:820px){.kpis{grid-template-columns:1fr 1fr}.grid2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

  <?php $activo = 'metricas'; include __DIR__ . '/admin_nav.php'; ?>

  <div style="margin-bottom:20px">
    <h1 class="sora" style="font-size:25px;font-weight:700;letter-spacing:-.02em">Métricas del sistema</h1>
    <p class="muted" style="margin-top:4px">Datos agregados de todas las consultas del sistema.</p>
  </div>

  <?php if ($err): ?>
    <div class="card" style="background:#fdeaea;border-color:#f3c6c6;color:#b23c3c">⚠️ No se pudieron cargar las métricas: <?= $h($err) ?></div>

  <?php elseif ($m['total'] === 0): ?>
    <div class="card" style="text-align:center;padding:44px 24px">
      <div style="font-size:34px;margin-bottom:10px">📊</div>
      <div class="sora" style="font-size:17px;font-weight:600;margin-bottom:6px">Aún no hay consultas registradas</div>
      <p class="muted">Cuando los usuarios hagan estimaciones, aquí verás las métricas del sistema.</p>
    </div>

  <?php else: ?>
    <!-- KPIs principales -->
    <div class="kpis">
      <div class="card kpi"><div class="muted">Consultas totales</div><div class="n" style="color:#0f5c5c"><?= $m['total'] ?></div></div>
      <div class="card kpi"><div class="muted">Especialidad más pedida</div><div class="n" style="font-size:19px;margin-top:10px"><?= $h($m['top_esp']) ?></div></div>
      <div class="card kpi"><div class="muted">Ahorro promedio generado</div><div class="n" style="color:#128a4e"><?= $money($m['ahorro_prom']) ?></div></div>
      <div class="card kpi"><div class="muted">Usuarios activos</div><div class="n"><?= $m['usuarios'] ?></div></div>
    </div>

    <!-- KPIs secundarios -->
    <div class="kpis" style="margin-top:14px">
      <div class="card kpi"><div class="muted">Copago promedio</div><div class="n" style="font-size:24px"><?= $money($m['copago_prom']) ?></div></div>
      <div class="card kpi"><div class="muted">Ahorro total generado</div><div class="n" style="font-size:24px;color:#128a4e"><?= $money($m['ahorro_total']) ?></div></div>
      <div class="card kpi"><div class="muted">Requieren autorización</div><div class="n" style="font-size:24px"><?= number_format($m['pct_auth'], 0) ?>%</div></div>
      <div class="card kpi"><div class="muted">Promedio consultas/usuario</div><div class="n" style="font-size:24px"><?= number_format($m['total'] / max(1, $m['usuarios']), 1) ?></div></div>
    </div>

    <div class="grid2">
      <!-- Consultas por especialidad -->
      <div class="card">
        <h3 class="t sora">Consultas por especialidad</h3>
        <?php foreach ($porEsp as $r): ?>
          <div class="row">
            <div class="lbl"><?= $h($r['especialidad']) ?></div>
            <div class="bar"><div style="width:<?= max(6, round($r['n'] / $maxEsp * 100)) ?>%;background:#0f5c5c"></div></div>
            <div class="val"><?= $r['n'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Consultas por ciudad -->
      <div class="card">
        <h3 class="t sora">Consultas por ciudad</h3>
        <?php foreach ($porCiudad as $r): ?>
          <div class="row">
            <div class="lbl"><?= $h($r['ciudad']) ?></div>
            <div class="bar"><div style="width:<?= max(6, round($r['n'] / $maxCiu * 100)) ?>%;background:#2fbf71"></div></div>
            <div class="val"><?= $r['n'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="grid2">
      <!-- Hospitales más recomendados -->
      <div class="card">
        <h3 class="t sora">Hospitales más recomendados</h3>
        <?php foreach ($porHospital as $r): ?>
          <div class="row">
            <div class="lbl" style="width:170px"><?= $h($r['hospital']) ?></div>
            <div class="bar"><div style="width:<?= max(6, round($r['n'] / $maxHosp * 100)) ?>%;background:#c9b39a"></div></div>
            <div class="val"><?= $r['n'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Consultas por día -->
      <div class="card">
        <h3 class="t sora">Consultas por día</h3>
        <div style="display:flex;align-items:flex-end;gap:8px;height:150px;padding-top:8px">
          <?php foreach ($porDia as $r): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end">
              <div style="font-size:11px;font-weight:600;color:#0f5c5c"><?= $r['n'] ?></div>
              <div style="width:100%;max-width:34px;background:#0f5c5c;border-radius:6px 6px 0 0;height:<?= max(6, round($r['n'] / $maxDia * 100)) ?>%"></div>
              <div style="font-size:10.5px;color:#7a8681;white-space:nowrap"><?= $h($diaLindo($r['d'])) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <p class="muted" style="margin-top:18px;text-align:center">
      El <b>ahorro promedio</b> se recalcula desde las coberturas reales (SQL): diferencia entre el hospital más caro y el recomendado por consulta.
    </p>
  <?php endif; ?>

</div>
<script src="theme.js"></script>
</body>
</html>

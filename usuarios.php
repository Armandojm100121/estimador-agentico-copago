<?php
// usuarios.php  -  Lista de Usuarios + Reporte de usuarios (panel admin). Solo administradores.
// Muestra todas las cuentas registradas con su plan, estado de verificación,
// rol y cantidad de consultas. Permite exportar la lista a CSV e imprimir/PDF.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
requiereAdmin();

$db = getDB();

// Consulta base: usuarios + su plan + cuántas consultas ha hecho cada uno.
$sql = "SELECT u.id, u.nombre, u.email, u.creado_en, u.es_admin, u.email_verificado,
               p.aseguradora, p.nombre AS plan_nombre,
               (SELECT COUNT(*) FROM consultas c WHERE c.usuario_id = u.id) AS n_consultas
        FROM usuarios u
        LEFT JOIN planes p ON p.id = u.plan_id
        ORDER BY u.creado_en DESC, u.id DESC";

$err = null;
$usuarios = [];
try {
    $usuarios = $db->query($sql)->fetchAll();
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$planTexto = function (array $r): string {
    if (!$r['plan_nombre']) { return 'Sin plan'; }
    return trim(($r['aseguradora'] ? $r['aseguradora'] . ' · ' : '') . $r['plan_nombre']);
};

// ---- Exportación a CSV (Reporte descargable) --------------------------------
// Debe ir ANTES de imprimir cualquier HTML.
if (($_GET['export'] ?? '') === 'csv' && !$err) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_usuarios.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");  // BOM para que Excel muestre bien las tildes
    fputcsv($out, ['ID', 'Nombre', 'Correo', 'Plan', 'Correo verificado', 'Rol', 'Consultas', 'Registrado']);
    foreach ($usuarios as $r) {
        fputcsv($out, [
            $r['id'],
            $r['nombre'],
            $r['email'],
            $planTexto($r),
            $r['email_verificado'] ? 'Sí' : 'No',
            $r['es_admin'] ? 'Administrador' : 'Paciente',
            $r['n_consultas'],
            $r['creado_en'],
        ]);
    }
    fclose($out);
    exit;
}

// ---- Totales para el Reporte ------------------------------------------------
$total       = count($usuarios);
$verificados = 0;
$admins      = 0;
$conPlan     = 0;
$conConsulta = 0;
$porPlan     = [];   // plan => cantidad de usuarios
foreach ($usuarios as $r) {
    if ($r['email_verificado']) { $verificados++; }
    if ($r['es_admin'])         { $admins++; }
    if ($r['plan_nombre'])      { $conPlan++; }
    if ((int) $r['n_consultas'] > 0) { $conConsulta++; }
    $k = $planTexto($r);
    $porPlan[$k] = ($porPlan[$k] ?? 0) + 1;
}
arsort($porPlan);
$maxPlan = $porPlan ? max($porPlan) : 1;

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fecha = function (?string $ymd): string {
    if (!$ymd) { return '—'; }
    $t = strtotime($ymd);
    return $t ? date('d/m/Y', $t) : '—';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimador Copago — Usuarios</title>
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
  .muted{color:var(--muted);font-size:12.5px}
  .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
  .kpi .n{font-family:'Sora',sans-serif;font-weight:800;font-size:30px;margin-top:6px}
  h3.t{font-size:15px;font-weight:600;margin-bottom:14px}
  .row{display:flex;align-items:center;gap:10px;margin:9px 0}
  .row .lbl{width:200px;font-size:13px;flex-shrink:0}
  .row .bar{flex:1;height:12px;background:var(--surface-2);border-radius:6px;overflow:hidden}
  .row .bar>div{height:100%;border-radius:6px;background:#0f5c5c}
  .row .val{width:34px;text-align:right;font-weight:600;font-size:13px}
  .btn{background:var(--marca);color:#fff;font-size:13.5px;font-weight:600;padding:10px 16px;border-radius:11px;border:none;cursor:pointer;display:inline-flex;gap:7px;align-items:center;text-decoration:none}
  .btn-ghost{background:var(--surface);border:1px solid var(--field-border);color:var(--text);font-size:13.5px;font-weight:500;padding:10px 16px;border-radius:11px;cursor:pointer;display:inline-flex;gap:7px;align-items:center;text-decoration:none}
  .btn-ghost:hover{border-color:var(--marca);color:var(--marca)}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--borde)}
  th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
  tbody tr:hover{background:var(--surface-2)}
  .pill{display:inline-block;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:999px}
  .pill-ok{background:#e3f6ec;color:#128a4e}
  .pill-no{background:#fdeaea;color:#b23c3c}
  .pill-adm{background:#eae4fb;color:#6b3fd4}
  .pill-pac{background:var(--surface-2);color:var(--muted)}
  @media(max-width:820px){.kpis{grid-template-columns:1fr 1fr}.tabla-scroll{overflow-x:auto}}
  @media print{
    body{background:#fff;padding:0}
    .no-print{display:none !important}
    .card{border:1px solid #ccc;break-inside:avoid}
    tbody tr:hover{background:transparent}
  }
</style>
</head>
<body>
<div class="wrap">

  <div class="no-print">
    <?php $activo = 'usuarios'; include __DIR__ . '/admin_nav.php'; ?>
  </div>

  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:20px;flex-wrap:wrap">
    <div>
      <h1 class="sora" style="font-size:25px;font-weight:700;letter-spacing:-.02em">Usuarios registrados</h1>
      <p class="muted" style="margin-top:4px">Lista y reporte de todas las cuentas del sistema.</p>
    </div>
    <div class="no-print" style="display:flex;gap:8px">
      <a class="btn-ghost" href="usuarios.php?export=csv">⬇ Exportar CSV</a>
      <button class="btn" onclick="window.print()">🖨 Descargar PDF</button>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="card" style="background:#fdeaea;border-color:#f3c6c6;color:#b23c3c">⚠️ No se pudieron cargar los usuarios: <?= $h($err) ?></div>

  <?php elseif ($total === 0): ?>
    <div class="card" style="text-align:center;padding:44px 24px">
      <div style="font-size:34px;margin-bottom:10px">👥</div>
      <div class="sora" style="font-size:17px;font-weight:600;margin-bottom:6px">Aún no hay usuarios registrados</div>
      <p class="muted">Cuando alguien cree una cuenta, aparecerá aquí.</p>
    </div>

  <?php else: ?>

    <!-- ===== Reporte de usuarios (totales) ===== -->
    <div class="kpis">
      <div class="card kpi"><div class="muted">Usuarios totales</div><div class="n" style="color:#0f5c5c"><?= $total ?></div></div>
      <div class="card kpi"><div class="muted">Correo verificado</div><div class="n" style="color:#128a4e"><?= $verificados ?></div><div class="muted" style="margin-top:2px"><?= $total ? round($verificados / $total * 100) : 0 ?>% del total</div></div>
      <div class="card kpi"><div class="muted">Con plan asignado</div><div class="n"><?= $conPlan ?></div></div>
      <div class="card kpi"><div class="muted">Administradores</div><div class="n" style="color:#6b3fd4"><?= $admins ?></div></div>
    </div>

    <!-- Distribución por plan -->
    <div class="card" style="margin-top:16px">
      <h3 class="t sora">Usuarios por plan contratado</h3>
      <?php foreach ($porPlan as $plan => $n): ?>
        <div class="row">
          <div class="lbl"><?= $h($plan) ?></div>
          <div class="bar"><div style="width:<?= max(6, round($n / $maxPlan * 100)) ?>%"></div></div>
          <div class="val"><?= $n ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ===== Lista de Usuarios ===== -->
    <div class="card" style="margin-top:16px">
      <h3 class="t sora">Lista de usuarios (<?= $total ?>)</h3>
      <div class="tabla-scroll">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Nombre</th>
              <th>Correo</th>
              <th>Plan</th>
              <th>Verificado</th>
              <th>Rol</th>
              <th style="text-align:right">Consultas</th>
              <th>Registrado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($usuarios as $i => $r): ?>
              <tr>
                <td class="muted"><?= $i + 1 ?></td>
                <td style="font-weight:600"><?= $h($r['nombre']) ?></td>
                <td><?= $h($r['email']) ?></td>
                <td><?= $h($planTexto($r)) ?></td>
                <td><?= $r['email_verificado']
                        ? '<span class="pill pill-ok">Sí</span>'
                        : '<span class="pill pill-no">No</span>' ?></td>
                <td><?= $r['es_admin']
                        ? '<span class="pill pill-adm">Administrador</span>'
                        : '<span class="pill pill-pac">Paciente</span>' ?></td>
                <td style="text-align:right;font-weight:600"><?= (int) $r['n_consultas'] ?></td>
                <td class="muted"><?= $h($fecha($r['creado_en'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <p class="muted" style="margin-top:18px;text-align:center">
      Reporte generado desde la tabla <b>usuarios</b>. Usa <b>Exportar CSV</b> para abrirlo en Excel o <b>Descargar PDF</b> para el documento.
    </p>

  <?php endif; ?>

</div>
<script src="theme.js"></script>
</body>
</html>

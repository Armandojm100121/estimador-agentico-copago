<?php
// evaluar.php  -  Panel de evaluación del agente (#8).
// Corre el dataset de casos contra uno o más modelos de Groq y reporta
// precisión, latencia y costo, con matriz de confusión y exportación a CSV.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
requiereAdmin();   // la evaluación es una herramienta de administración/tesis

$u        = usuarioActual();
$h        = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$modelos  = require __DIR__ . '/eval_modelos.php';
$casos    = require __DIR__ . '/casos_evaluacion.php';
$esp      = getDB()->query("SELECT nombre FROM especialidades ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimador Copago — Evaluación del agente</title>
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
  .card{background:var(--surface);border:1px solid var(--borde);border-radius:16px;padding:20px 22px;color:var(--text);box-shadow:0 18px 44px -34px rgba(16,35,31,.4);transition:box-shadow .3s var(--ease-spring)}
  .card:hover{box-shadow:0 26px 60px -38px rgba(16,35,31,.5)}
  @keyframes rise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
  .card{animation:rise .55s var(--ease-spring) both}
  .card:nth-child(1){animation-delay:.03s}
  .card:nth-child(2){animation-delay:.08s}
  .card:nth-child(3){animation-delay:.13s}
  .card:nth-child(4){animation-delay:.18s}
  .btn{background:linear-gradient(180deg,var(--marca-2),var(--marca));border:none;color:#fff;font-family:'Sora',sans-serif;font-weight:600;font-size:14px;padding:12px 22px;border-radius:11px;cursor:pointer;box-shadow:0 10px 22px -14px rgba(15,92,92,.7),inset 0 1px 0 rgba(255,255,255,.14)}
  .btn:hover:not(:disabled){filter:brightness(1.06);transform:translateY(-1px)}
  .btn:active:not(:disabled){transform:scale(.97)}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  .btn-ghost{background:var(--surface);border:1px solid var(--field-border);color:var(--text);font-size:13.5px;font-weight:500;padding:10px 16px;border-radius:11px;cursor:pointer}
  .btn-ghost:hover{border-color:var(--marca);color:var(--marca);transform:translateY(-1px)}
  .btn-ghost:active{transform:scale(.97)}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--borde)}
  th{font-weight:600;color:var(--muted)}
  .ok{color:#2eb872;font-weight:600}.bad{color:#e0736f;font-weight:600}
  .muted{color:var(--muted)}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .metric{font-family:'Sora',sans-serif;font-weight:800;font-size:30px}
  .bar{height:9px;background:var(--surface-2);border-radius:6px;overflow:hidden}
  .bar>div{height:100%;background:var(--acento);border-radius:6px;transition:width .3s}
  input[type=checkbox]{width:16px;height:16px;accent-color:var(--marca)}
  @media(max-width:720px){.grid2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

  <?php $activo = 'evaluar'; include __DIR__ . '/admin_nav.php'; ?>

  <div style="margin-bottom:20px">
    <h1 class="sora" style="font-size:25px;font-weight:700;letter-spacing:-.02em">Evaluación del agente</h1>
    <p class="muted" style="font-size:13.5px;margin-top:4px">Precisión, latencia y costo sobre <b><?= count($casos) ?> casos</b> de prueba (síntoma → especialidad esperada).</p>
  </div>

  <!-- Controles -->
  <div class="card" style="margin-bottom:18px">
    <div class="sora" style="font-weight:600;font-size:14.5px;margin-bottom:12px">Modelos de Groq a comparar</div>
    <div style="display:flex;flex-direction:column;gap:9px">
      <?php foreach ($modelos as $slug => $m): ?>
        <label style="display:flex;align-items:center;gap:10px;font-size:14px;cursor:pointer">
          <input type="checkbox" class="mdl" value="<?= $h($slug) ?>" checked>
          <span><b><?= $h($m['label']) ?></b> <span class="muted" style="font-size:12.5px">· $<?= $m['in'] ?>/$<?= $m['out'] ?> por 1M tok (in/out)</span></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;align-items:center;gap:12px;margin-top:16px;flex-wrap:wrap">
      <button id="btnRun" class="btn" onclick="correr()">▶ Ejecutar evaluación</button>
      <button id="btnCsv" class="btn-ghost" onclick="descargarCSV()" disabled>⬇ Exportar CSV</button>
      <span id="estado" class="muted" style="font-size:13px"></span>
    </div>
    <div class="bar" style="margin-top:14px"><div id="prog" style="width:0%"></div></div>
  </div>

  <!-- Resumen por modelo -->
  <div id="resumen" class="grid2" style="margin-bottom:18px"></div>

  <!-- Matriz de confusión -->
  <div id="matrices"></div>

  <!-- Tabla por caso -->
  <div id="tablaWrap" class="card" style="margin-top:18px;display:none">
    <div class="sora" style="font-weight:600;font-size:14.5px;margin-bottom:12px">Detalle por caso</div>
    <div style="overflow-x:auto"><table id="tabla"><thead></thead><tbody></tbody></table></div>
  </div>

</div>

<script>
  const CASOS = <?= json_encode($casos, JSON_UNESCAPED_UNICODE) ?>;
  const MODELOS = <?= json_encode($modelos, JSON_UNESCAPED_UNICODE) ?>;
  const ESPECIALIDADES = <?= json_encode($esp, JSON_UNESCAPED_UNICODE) ?>;
  const N = CASOS.length;
  const money = n => '$' + Number(n).toFixed(n < 1 ? 4 : 2);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  let RESULTADOS = {};   // { slug: [ {i,esperada,obtenida,correcto,...} ] }

  async function correr(){
    const seleccion = [...document.querySelectorAll('.mdl:checked')].map(c => c.value);
    if(!seleccion.length){ alert('Elige al menos un modelo.'); return; }
    RESULTADOS = {};
    document.getElementById('btnRun').disabled = true;
    document.getElementById('btnCsv').disabled = true;
    document.getElementById('resumen').innerHTML = '';
    document.getElementById('matrices').innerHTML = '';
    document.getElementById('tablaWrap').style.display = 'none';

    const total = seleccion.length * N;
    let hechos = 0, fallos = 0;
    for(const model of seleccion){
      RESULTADOS[model] = [];
      for(let i = 0; i < N; i++){
        document.getElementById('estado').textContent =
          'Evaluando ' + (MODELOS[model].label) + ' · caso ' + (i+1) + '/' + N + '…';
        const r = await evaluarCaso(i, model);
        RESULTADOS[model].push(r);
        if(r.error) fallos++;
        hechos++;
        document.getElementById('prog').style.width = Math.round(hechos/total*100) + '%';
        render();   // actualización en vivo
      }
    }
    document.getElementById('estado').textContent =
      '✓ Listo: ' + total + ' evaluaciones' + (fallos ? ' (' + fallos + ' con error)' : '') + '.';
    document.getElementById('btnRun').disabled = false;
    document.getElementById('btnCsv').disabled = false;
  }

  // Llama al endpoint para un caso; reintenta una vez ante error transitorio.
  async function evaluarCaso(i, model){
    for(let intento = 0; intento < 2; intento++){
      try {
        const res = await fetch('eval_api.php?i=' + i + '&model=' + encodeURIComponent(model));
        const d = await res.json();
        if(!d.ok) throw new Error(d.error || 'error');
        return d;
      } catch(e){
        if(intento === 0){ await new Promise(r => setTimeout(r, 1500)); continue; }
        return {i, sintoma: CASOS[i].sintoma, esperada: CASOS[i].esperada,
                obtenida: null, correcto: false, error: String(e.message || e),
                latencia_ms: 0, tokens_in: 0, tokens_out: 0, costo: 0};
      }
    }
  }

  function agregados(rows){
    const n = rows.length || 1;
    const ok = rows.filter(r => r.correcto).length;
    const lat = rows.reduce((s,r) => s + (r.latencia_ms||0), 0) / n;
    const costo = rows.reduce((s,r) => s + (r.costo||0), 0);
    const errs = rows.filter(r => r.error).length;
    return {ok, n: rows.length, acc: ok/n*100, lat, costo, costoProm: costo/n, errs};
  }

  function render(){
    const cont = document.getElementById('resumen');
    cont.innerHTML = '';
    const modelos = Object.keys(RESULTADOS);
    // Mejor precisión para resaltar al ganador
    let mejorAcc = -1;
    modelos.forEach(m => { const a = agregados(RESULTADOS[m]).acc; if(a > mejorAcc) mejorAcc = a; });

    modelos.forEach(m => {
      const a = agregados(RESULTADOS[m]);
      const ganador = a.acc === mejorAcc && modelos.length > 1;
      cont.innerHTML +=
        '<div class="card"' + (ganador ? ' style="border-color:#2fbf71;box-shadow:0 0 0 3px rgba(47,191,113,.15)"' : '') + '>' +
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">' +
            '<div class="sora" style="font-weight:700;font-size:15px">' + esc(MODELOS[m].label) + '</div>' +
            (ganador ? '<span style="font-size:11px;background:#e2f6ec;color:#128a4e;padding:3px 9px;border-radius:7px;font-weight:600">Mejor precisión</span>' : '') +
          '</div>' +
          '<div style="display:flex;align-items:baseline;gap:8px"><span class="metric" style="color:#0f5c5c">' + a.acc.toFixed(1) + '%</span><span class="muted" style="font-size:13px">precisión (' + a.ok + '/' + a.n + ')</span></div>' +
          '<div class="bar" style="margin:10px 0 16px"><div style="width:' + a.acc + '%"></div></div>' +
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">' +
            metricMini('Latencia media', (a.lat).toFixed(0) + ' ms') +
            metricMini('Costo / consulta', money(a.costoProm)) +
            metricMini('Costo del set', money(a.costo)) +
            metricMini('Proyección 1.000', money(a.costoProm * 1000)) +
          '</div>' +
          (a.errs ? '<div class="bad" style="font-size:12px;margin-top:10px">⚠ ' + a.errs + ' caso(s) con error</div>' : '') +
        '</div>';
    });

    renderMatrices();
    renderTabla();
  }
  function metricMini(k,v){
    return '<div><div class="muted" style="font-size:12px">' + k + '</div><div class="sora" style="font-weight:700;font-size:16px;margin-top:2px">' + v + '</div></div>';
  }

  // Matriz de confusión por modelo (fila = esperada, columna = obtenida)
  function renderMatrices(){
    const cont = document.getElementById('matrices');
    cont.innerHTML = '';
    const cats = ESPECIALIDADES.slice();
    Object.keys(RESULTADOS).forEach(m => {
      const rows = RESULTADOS[m];
      if(!rows.length) return;
      // etiquetas cortas para columnas
      const corta = s => s.length > 6 ? s.slice(0,6) + '…' : s;
      let thead = '<th>esperada ↓ / predicha →</th>' + cats.map(c => '<th style="text-align:center" title="'+esc(c)+'">' + esc(corta(c)) + '</th>').join('') + '<th style="text-align:center">otro</th>';
      let body = '';
      cats.forEach(exp => {
        let fila = '<td style="font-weight:600">' + esc(exp) + '</td>';
        cats.forEach(pred => {
          const n = rows.filter(r => r.esperada === exp && r.obtenida === pred).length;
          const diag = exp === pred;
          fila += '<td style="text-align:center;' + (n ? (diag ? 'background:#e2f6ec;color:#128a4e;font-weight:700' : 'background:#fdeaea;color:#b23c3c;font-weight:600') : 'color:#ccc') + '">' + (n||'·') + '</td>';
        });
        const otro = rows.filter(r => r.esperada === exp && !cats.includes(r.obtenida)).length;
        fila += '<td style="text-align:center;' + (otro ? 'background:#fdeaea;color:#b23c3c;font-weight:600' : 'color:#ccc') + '">' + (otro||'·') + '</td>';
        body += '<tr>' + fila + '</tr>';
      });
      cont.innerHTML +=
        '<div class="card" style="margin-bottom:16px"><div class="sora" style="font-weight:600;font-size:14px;margin-bottom:4px">Matriz de confusión · ' + esc(MODELOS[m].label) + '</div>' +
        '<div class="muted" style="font-size:12px;margin-bottom:12px">Verde = acierto (diagonal); rojo = confusión.</div>' +
        '<div style="overflow-x:auto"><table><thead><tr>' + thead + '</tr></thead><tbody>' + body + '</tbody></table></div></div>';
    });
  }

  function renderTabla(){
    const modelos = Object.keys(RESULTADOS);
    if(!modelos.length) return;
    document.getElementById('tablaWrap').style.display = 'block';
    const thead = document.querySelector('#tabla thead');
    const tbody = document.querySelector('#tabla tbody');
    thead.innerHTML = '<tr><th>#</th><th>Síntoma</th><th>Esperada</th>' +
      modelos.map(m => '<th>' + esc(MODELOS[m].label) + '</th>').join('') + '</tr>';
    let html = '';
    for(let i = 0; i < N; i++){
      html += '<tr><td>' + (i+1) + '</td><td style="max-width:280px">' + esc(CASOS[i].sintoma) + '</td>' +
        '<td class="muted">' + esc(CASOS[i].esperada) + '</td>';
      modelos.forEach(m => {
        const r = RESULTADOS[m][i];
        if(!r){ html += '<td class="muted">—</td>'; return; }
        if(r.error){ html += '<td class="bad" title="'+esc(r.error)+'">error</td>'; return; }
        const cls = r.correcto ? 'ok' : 'bad';
        const icon = r.correcto ? '✓' : '✕';
        html += '<td class="' + cls + '">' + icon + ' ' + esc(r.obtenida || '—') +
          '<div class="muted" style="font-size:11px;font-weight:400">' + Math.round(r.latencia_ms) + ' ms' + (r.nivel_urgencia ? ' · ' + esc(r.nivel_urgencia) : '') + '</div></td>';
      });
      html += '</tr>';
    }
    tbody.innerHTML = html;
  }

  function descargarCSV(){
    const modelos = Object.keys(RESULTADOS);
    if(!modelos.length){ return; }
    let filas = [['caso','sintoma','esperada','modelo','obtenida','correcto','nivel_urgencia','latencia_ms','tokens_in','tokens_out','costo_usd']];
    modelos.forEach(m => RESULTADOS[m].forEach((r,i) => {
      filas.push([i+1, CASOS[i].sintoma, CASOS[i].esperada, MODELOS[m].label,
        r.obtenida||'', r.correcto?1:0, r.nivel_urgencia||'', Math.round(r.latencia_ms||0),
        r.tokens_in||0, r.tokens_out||0, (r.costo||0).toFixed(6)]);
    }));
    const csv = filas.map(f => f.map(c => '"' + String(c).replace(/"/g,'""') + '"').join(',')).join('\n');
    const blob = new Blob(["﻿" + csv], {type:'text/csv;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'evaluacion_agente.csv';
    a.click();
  }
</script>
<script src="theme.js"></script>
</body>
</html>

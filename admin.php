<?php
// admin.php  -  Panel de administración / CRUD (#9). Solo administradores.
// Gestiona planes, hospitales y coberturas desde la web, sin tocar SQL.
// Usa patrón POST-Redirect-GET, token CSRF y validación por campo.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
requiereAdmin();

$db = getDB();
$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// --- Token CSRF (protege las operaciones que modifican datos) ---
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// --- Opciones para los <select> de coberturas (se leen de la BD) ---
$planesOpt = [];
foreach ($db->query("SELECT id, aseguradora, nombre FROM planes ORDER BY aseguradora, nombre") as $p) {
    $planesOpt[$p['id']] = $p['aseguradora'] . ' - ' . $p['nombre'];
}
$espOpt = [];
foreach ($db->query("SELECT id, nombre FROM especialidades ORDER BY nombre") as $e) {
    $espOpt[$e['id']] = $e['nombre'];
}
$hospOpt = [];
foreach ($db->query("SELECT id, nombre, ciudad FROM hospitales ORDER BY ciudad, nombre") as $hh) {
    $hospOpt[$hh['id']] = $hh['nombre'] . ' (' . $hh['ciudad'] . ')';
}

// --- Configuración de las entidades: campos, etiquetas y validación ---
$ENT = [
    'planes' => [
        'titulo' => 'Planes', 'singular' => 'plan', 'tabla' => 'planes',
        'campos' => [
            'aseguradora'   => ['label' => 'Aseguradora',       'tipo' => 'text',   'req' => true],
            'nombre'        => ['label' => 'Nombre del plan',    'tipo' => 'text',   'req' => true],
            'deducible'     => ['label' => 'Deducible ($)',      'tipo' => 'number', 'req' => true, 'step' => '0.01', 'min' => 0],
            'factor_copago' => ['label' => 'Factor copago',      'tipo' => 'number', 'req' => true, 'step' => '0.01', 'min' => 0],
            'porcentaje'    => ['label' => '% que cubre',        'tipo' => 'number', 'req' => true, 'step' => '1', 'min' => 0, 'max' => 100],
        ],
    ],
    'hospitales' => [
        'titulo' => 'Hospitales', 'singular' => 'hospital', 'tabla' => 'hospitales',
        'campos' => [
            'nombre'     => ['label' => 'Nombre',        'tipo' => 'text',   'req' => true],
            'ciudad'     => ['label' => 'Ciudad',        'tipo' => 'text',   'req' => true],
            'red'        => ['label' => 'Red',           'tipo' => 'text',   'req' => true],
            'factor_red' => ['label' => 'Factor de red', 'tipo' => 'number', 'req' => true, 'step' => '0.01', 'min' => 0],
        ],
    ],
    'coberturas' => [
        'titulo' => 'Coberturas', 'singular' => 'cobertura', 'tabla' => 'coberturas',
        'campos' => [
            'plan_id'              => ['label' => 'Plan',         'tipo' => 'select', 'req' => true, 'opciones' => $planesOpt],
            'especialidad_id'      => ['label' => 'Especialidad', 'tipo' => 'select', 'req' => true, 'opciones' => $espOpt],
            'hospital_id'          => ['label' => 'Hospital',     'tipo' => 'select', 'req' => true, 'opciones' => $hospOpt],
            'copago'               => ['label' => 'Copago ($)',   'tipo' => 'number', 'req' => true, 'step' => '0.01', 'min' => 0],
            'porcentaje_cobertura' => ['label' => '% cobertura',  'tipo' => 'number', 'req' => true, 'step' => '1', 'min' => 0, 'max' => 100],
        ],
    ],
];

// Pestaña activa
$tab = $_GET['tab'] ?? 'planes';
if (!isset($ENT[$tab])) { $tab = 'planes'; }

// Mensaje flash (se muestra y se limpia)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
function flash(string $tipo, string $msg): void { $_SESSION['flash'] = ['tipo' => $tipo, 'msg' => $msg]; }

// =====================================================================
//  POST: guardar (crear/editar) o borrar
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificación CSRF
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Token de seguridad inválido. Recarga la página.');
    }
    $entidad = $_POST['entidad'] ?? '';
    $accion  = $_POST['accion'] ?? '';
    if (!isset($ENT[$entidad])) { http_response_code(400); exit('Entidad no válida.'); }
    $cfg = $ENT[$entidad];
    $id  = (int) ($_POST['id'] ?? 0);

    // ---- Borrar ----
    if ($accion === 'borrar') {
        try {
            $st = $db->prepare("DELETE FROM `{$cfg['tabla']}` WHERE id = ?");
            $st->execute([$id]);
            flash('ok', ucfirst($cfg['singular']) . " #$id eliminado.");
        } catch (Throwable $e) {
            // Típicamente una restricción de llave foránea (tiene coberturas asociadas)
            flash('err', "No se puede eliminar este {$cfg['singular']}: tiene registros asociados "
                . "(por ejemplo, coberturas). Elimina primero esos registros.");
        }
        header("Location: admin.php?tab=$entidad");
        exit;
    }

    // ---- Guardar (crear o editar) ----
    if ($accion === 'guardar') {
        $vals = [];
        $errores = [];
        foreach ($cfg['campos'] as $col => $f) {
            $v = trim((string) ($_POST[$col] ?? ''));
            if ($f['req'] && $v === '') {
                $errores[] = "El campo «{$f['label']}» es obligatorio.";
                continue;
            }
            if ($f['tipo'] === 'number') {
                if (!is_numeric($v)) { $errores[] = "«{$f['label']}» debe ser un número."; continue; }
                $v = (float) $v;
                if (isset($f['min']) && $v < $f['min']) { $errores[] = "«{$f['label']}» no puede ser menor que {$f['min']}."; }
                if (isset($f['max']) && $v > $f['max']) { $errores[] = "«{$f['label']}» no puede ser mayor que {$f['max']}."; }
            }
            if ($f['tipo'] === 'select' && !isset($f['opciones'][(int) $v])) {
                $errores[] = "Selecciona un valor válido para «{$f['label']}».";
            }
            $vals[$col] = $v;
        }

        // Coberturas: evita duplicar la misma combinación plan+especialidad+hospital
        if (!$errores && $entidad === 'coberturas') {
            $q = $db->prepare("SELECT id FROM coberturas
                               WHERE plan_id=? AND especialidad_id=? AND hospital_id=? AND id<>?");
            $q->execute([$vals['plan_id'], $vals['especialidad_id'], $vals['hospital_id'], $id]);
            if ($q->fetch()) {
                $errores[] = 'Ya existe una cobertura para ese plan, especialidad y hospital.';
            }
        }

        if ($errores) {
            flash('err', implode(' ', $errores));
            header("Location: admin.php?tab=$entidad&accion=" . ($id ? "editar&id=$id" : 'nuevo'));
            exit;
        }

        try {
            $cols = array_keys($vals);
            if ($id > 0) {
                $set = implode(', ', array_map(fn($c) => "`$c`=?", $cols));
                $st = $db->prepare("UPDATE `{$cfg['tabla']}` SET $set WHERE id=?");
                $st->execute([...array_values($vals), $id]);
                flash('ok', ucfirst($cfg['singular']) . " #$id actualizado.");
            } else {
                $ph = implode(', ', array_fill(0, count($cols), '?'));
                $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
                $st = $db->prepare("INSERT INTO `{$cfg['tabla']}` ($colList) VALUES ($ph)");
                $st->execute(array_values($vals));
                flash('ok', ucfirst($cfg['singular']) . ' creado correctamente.');
            }
        } catch (Throwable $e) {
            flash('err', 'No se pudo guardar: ' . $e->getMessage());
        }
        header("Location: admin.php?tab=$entidad");
        exit;
    }
}

// =====================================================================
//  GET: preparar datos para la vista (lista o formulario)
// =====================================================================
$cfg    = $ENT[$tab];
$accion = $_GET['accion'] ?? 'lista';           // lista | nuevo | editar
$editId = (int) ($_GET['id'] ?? 0);
$fila   = null;

if ($accion === 'editar' && $editId) {
    $st = $db->prepare("SELECT * FROM `{$cfg['tabla']}` WHERE id = ?");
    $st->execute([$editId]);
    $fila = $st->fetch();
    if (!$fila) { $accion = 'lista'; }
}

// Filtros para coberturas (son muchas filas)
$fPlan = (int) ($_GET['f_plan'] ?? 0);
$fEsp  = (int) ($_GET['f_esp'] ?? 0);

// Cargar la lista de la pestaña actual
$listaRows = [];
if ($accion === 'lista') {
    if ($tab === 'coberturas') {
        $sql = "SELECT c.id, p.aseguradora, p.nombre pnombre, e.nombre esp,
                       h.nombre hosp, h.ciudad, c.copago, c.porcentaje_cobertura
                FROM coberturas c
                JOIN planes p        ON p.id = c.plan_id
                JOIN especialidades e ON e.id = c.especialidad_id
                JOIN hospitales h    ON h.id = c.hospital_id
                WHERE 1=1";
        $args = [];
        if ($fPlan) { $sql .= " AND c.plan_id = ?";        $args[] = $fPlan; }
        if ($fEsp)  { $sql .= " AND c.especialidad_id = ?"; $args[] = $fEsp; }
        $sql .= " ORDER BY p.aseguradora, p.nombre, e.nombre, h.ciudad LIMIT 200";
        $st = $db->prepare($sql);
        $st->execute($args);
        $listaRows = $st->fetchAll();
    } else {
        $listaRows = $db->query("SELECT * FROM `{$cfg['tabla']}` ORDER BY id")->fetchAll();
    }
}
$money = fn($n) => '$' . number_format((float) $n, 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estimador Copago — Administración</title>
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
  .btn{background:var(--marca);border:none;color:#fff;font-family:'Sora',sans-serif;font-weight:600;font-size:13.5px;padding:10px 16px;border-radius:10px;cursor:pointer}
  .btn:hover{background:var(--marca-2)}
  .btn-ghost{background:var(--surface);border:1px solid var(--field-border);color:var(--text);font-size:13px;font-weight:500;padding:8px 13px;border-radius:9px;cursor:pointer;display:inline-block}
  .btn-ghost:hover{border-color:var(--marca);color:var(--marca)}
  .btn-danger{background:var(--surface);border:1px solid #f0c8c8;color:#d9605f;font-size:13px;font-weight:500;padding:8px 13px;border-radius:9px;cursor:pointer}
  .btn-danger:hover{background:rgba(178,60,60,.12)}
  .tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
  .tab{padding:9px 16px;border-radius:10px;background:var(--surface);border:1px solid var(--borde);font-size:13.5px;font-weight:500;color:var(--text)}
  .tab.on{background:var(--marca);color:#fff;border-color:var(--marca)}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{padding:9px 10px;text-align:left;border-bottom:1px solid var(--borde)}
  th{font-weight:600;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.03em}
  label{display:block;font-size:12.5px;font-weight:500;color:var(--text);margin-bottom:5px}
  input,select{width:100%;padding:10px 12px;border:1px solid var(--field-border);border-radius:10px;font-size:14px;font-family:'IBM Plex Sans',sans-serif;outline:none;background:var(--field);color:var(--text)}
  input:focus,select:focus{border-color:var(--marca)}
  .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .flash{padding:13px 16px;border-radius:12px;margin-bottom:16px;font-size:13.5px}
  .flash.ok{background:#e9f7ef;border:1px solid #c6ead5;color:#128a4e}
  .flash.err{background:#fdeaea;border:1px solid #f3c6c6;color:#b23c3c}
  .muted{color:var(--muted);font-size:12.5px}
  @media(max-width:680px){.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

  <?php $activo = 'gestion'; include __DIR__ . '/admin_nav.php'; ?>

  <div style="margin-bottom:18px">
    <h1 class="sora" style="font-size:25px;font-weight:700;letter-spacing:-.02em">Gestión de datos</h1>
    <p class="muted" style="margin-top:4px">Crea, edita o elimina planes, hospitales y coberturas sin tocar SQL.</p>
  </div>

  <?php if ($flash): ?>
    <div class="flash <?= $h($flash['tipo']) ?>"><?= $h($flash['msg']) ?></div>
  <?php endif; ?>

  <!-- Pestañas -->
  <div class="tabs">
    <?php foreach ($ENT as $k => $e): ?>
      <a href="admin.php?tab=<?= $k ?>" class="tab <?= $k === $tab ? 'on' : '' ?>"><?= $h($e['titulo']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($accion === 'nuevo' || $accion === 'editar'): ?>
    <!-- ============ FORMULARIO ============ -->
    <div class="card">
      <h2 class="sora" style="font-size:17px;font-weight:600;margin-bottom:16px">
        <?= $accion === 'editar' ? 'Editar' : 'Nuevo' ?> <?= $h($cfg['singular']) ?>
      </h2>
      <form method="post" action="admin.php?tab=<?= $tab ?>">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="entidad" value="<?= $tab ?>">
        <input type="hidden" name="accion" value="guardar">
        <?php if ($accion === 'editar'): ?><input type="hidden" name="id" value="<?= (int) $fila['id'] ?>"><?php endif; ?>
        <div class="form-grid">
          <?php foreach ($cfg['campos'] as $col => $f): ?>
            <?php $val = $fila[$col] ?? ''; ?>
            <div>
              <label><?= $h($f['label']) ?></label>
              <?php if ($f['tipo'] === 'select'): ?>
                <select name="<?= $col ?>">
                  <option value="">— elegir —</option>
                  <?php foreach ($f['opciones'] as $ov => $ol): ?>
                    <option value="<?= $ov ?>" <?= (string) $val === (string) $ov ? 'selected' : '' ?>><?= $h($ol) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="<?= $f['tipo'] === 'number' ? 'number' : 'text' ?>"
                       name="<?= $col ?>" value="<?= $h($val) ?>"
                       <?= isset($f['step']) ? 'step="' . $h($f['step']) . '"' : '' ?>
                       <?= isset($f['min']) ? 'min="' . $h($f['min']) . '"' : '' ?>
                       <?= isset($f['max']) ? 'max="' . $h($f['max']) . '"' : '' ?>
                       <?= $f['req'] ? 'required' : '' ?>>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:10px;margin-top:20px">
          <button type="submit" class="btn"><?= $accion === 'editar' ? 'Guardar cambios' : 'Crear' ?></button>
          <a href="admin.php?tab=<?= $tab ?>" class="btn-ghost" style="padding:10px 16px">Cancelar</a>
        </div>
      </form>
    </div>

  <?php else: ?>
    <!-- ============ LISTA ============ -->
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
        <h2 class="sora" style="font-size:17px;font-weight:600"><?= $h($cfg['titulo']) ?> <span class="muted">(<?= count($listaRows) ?><?= $tab === 'coberturas' ? ' mostradas' : '' ?>)</span></h2>
        <a href="admin.php?tab=<?= $tab ?>&accion=nuevo" class="btn">+ Nuevo <?= $h($cfg['singular']) ?></a>
      </div>

      <?php if ($tab === 'coberturas'): ?>
        <!-- Filtros de coberturas -->
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #eef1ee">
          <input type="hidden" name="tab" value="coberturas">
          <div style="min-width:200px"><label>Filtrar por plan</label>
            <select name="f_plan"><option value="0">Todos</option>
              <?php foreach ($planesOpt as $ov => $ol): ?><option value="<?= $ov ?>" <?= $fPlan === $ov ? 'selected' : '' ?>><?= $h($ol) ?></option><?php endforeach; ?>
            </select></div>
          <div style="min-width:170px"><label>Filtrar por especialidad</label>
            <select name="f_esp"><option value="0">Todas</option>
              <?php foreach ($espOpt as $ov => $ol): ?><option value="<?= $ov ?>" <?= $fEsp === $ov ? 'selected' : '' ?>><?= $h($ol) ?></option><?php endforeach; ?>
            </select></div>
          <button type="submit" class="btn-ghost" style="padding:10px 16px">Filtrar</button>
        </form>
      <?php endif; ?>

      <?php if (!$listaRows): ?>
        <p class="muted" style="padding:14px 0">No hay registros<?= $tab === 'coberturas' ? ' con ese filtro' : '' ?>.</p>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table>
          <thead>
            <?php if ($tab === 'coberturas'): ?>
              <tr><th>Plan</th><th>Especialidad</th><th>Hospital</th><th>Copago</th><th>% cobertura</th><th></th></tr>
            <?php else: ?>
              <tr>
                <?php foreach ($cfg['campos'] as $col => $f): ?><th><?= $h($f['label']) ?></th><?php endforeach; ?>
                <th></th>
              </tr>
            <?php endif; ?>
          </thead>
          <tbody>
            <?php foreach ($listaRows as $r): ?>
              <tr>
                <?php if ($tab === 'coberturas'): ?>
                  <td><?= $h($r['aseguradora'] . ' - ' . $r['pnombre']) ?></td>
                  <td><?= $h($r['esp']) ?></td>
                  <td><?= $h($r['hosp'] . ' (' . $r['ciudad'] . ')') ?></td>
                  <td><b><?= $money($r['copago']) ?></b></td>
                  <td><?= (int) $r['porcentaje_cobertura'] ?>%</td>
                <?php else: ?>
                  <?php foreach ($cfg['campos'] as $col => $f): ?>
                    <td><?= $h($r[$col]) ?></td>
                  <?php endforeach; ?>
                <?php endif; ?>
                <td style="white-space:nowrap;text-align:right">
                  <a href="admin.php?tab=<?= $tab ?>&accion=editar&id=<?= (int) $r['id'] ?>" class="btn-ghost">Editar</a>
                  <form method="post" action="admin.php?tab=<?= $tab ?>" style="display:inline"
                        onsubmit="return confirm('¿Eliminar este <?= $h($cfg['singular']) ?>? Esta acción no se puede deshacer.')">
                    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="entidad" value="<?= $tab ?>">
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="btn-danger">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php if ($tab === 'coberturas' && count($listaRows) >= 200): ?>
          <p class="muted" style="margin-top:12px">Mostrando las primeras 200. Usa los filtros para acotar.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
<script src="theme.js"></script>
</body>
</html>

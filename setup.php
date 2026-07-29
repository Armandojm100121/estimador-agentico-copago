<?php
// setup.php  -  Instalador de la base de datos (idempotente).
// Crea/actualiza las tablas y datos que NO vienen en el backup base:
//   - tabla `usuarios`         (login)
//   - tabla `consultas`        (historial)
//   - columna `requiere_autorizacion` en `especialidades`  (+ valores)
//   - usuario de prueba maria@correo.com / 123456
//
// Cómo usarlo:
//   1) Primero importa el backup base (copago_backup_2026-05-21.sql) en tu base `copago`.
//   2) Abre este archivo en el navegador:  .../setup.php
//   Es seguro ejecutarlo varias veces (usa IF NOT EXISTS / comprobaciones).
//   Funciona tanto en MySQL como en MariaDB.

require __DIR__ . '/db.php';
header('Content-Type: text/html; charset=utf-8');

$pasos = [];
$add = function (bool $ok, string $msg) use (&$pasos) { $pasos[] = [$ok, $msg]; };

try {
    $db = getDB();

    // 0) Datos base (planes, hospitales, especialidades, coberturas).
    //    Si la base está vacía, los importa desde el backup del repositorio.
    $tieneBase = false;
    try {
        $tieneBase = ((int) $db->query("SELECT COUNT(*) FROM planes")->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $tieneBase = false;  // la tabla aún no existe
    }
    if (!$tieneBase) {
        $backup = __DIR__ . '/copago_backup_2026-05-21.sql';
        if (is_file($backup)) {
            $sql = str_replace("\r\n", "\n", file_get_contents($backup));
            $sql = preg_replace('/^--.*$/m', '', $sql);           // quita comentarios --
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                if ($stmt !== '') { $db->exec($stmt); }
            }
            $add(true, "Datos base importados desde el backup (planes, hospitales, coberturas)");
        } else {
            $add(false, "No se encontró el backup base (copago_backup_2026-05-21.sql)");
        }
    } else {
        $add(true, "Datos base ya presentes");
    }

    // 1) Tabla usuarios
    $db->exec("CREATE TABLE IF NOT EXISTS `usuarios` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `nombre` VARCHAR(120) NOT NULL,
        `email` VARCHAR(160) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `plan_id` INT DEFAULT NULL,
        `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `email` (`email`),
        KEY `plan_id` (`plan_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $add(true, "Tabla 'usuarios' lista");

    // 2) Tabla consultas (historial)
    $db->exec("CREATE TABLE IF NOT EXISTS `consultas` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `usuario_id` INT NOT NULL,
        `sintoma` VARCHAR(500) DEFAULT NULL,
        `especialidad` VARCHAR(100) DEFAULT NULL,
        `ciudad` VARCHAR(80) DEFAULT NULL,
        `hospital` VARCHAR(150) DEFAULT NULL,
        `red` VARCHAR(80) DEFAULT NULL,
        `copago` DECIMAL(10,2) DEFAULT NULL,
        `porcentaje_cobertura` INT DEFAULT NULL,
        `requiere_autorizacion` TINYINT(1) NOT NULL DEFAULT 0,
        `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `usuario_id` (`usuario_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $add(true, "Tabla 'consultas' lista");

    // 3) Columna requiere_autorizacion en especialidades (compatible MySQL y MariaDB)
    $existe = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'especialidades'
           AND COLUMN_NAME = 'requiere_autorizacion'"
    )->fetchColumn();
    if ($existe === 0) {
        $db->exec("ALTER TABLE `especialidades`
                   ADD COLUMN `requiere_autorizacion` TINYINT(1) NOT NULL DEFAULT 0");
        $add(true, "Columna 'requiere_autorizacion' agregada");
    } else {
        $add(true, "Columna 'requiere_autorizacion' ya existía");
    }

    // Reglas de autorización (por complejidad de la especialidad)
    $db->exec("UPDATE `especialidades` SET `requiere_autorizacion` = 0
               WHERE `nombre` IN ('Medicina General','Pediatría','Dermatología')");
    $db->exec("UPDATE `especialidades` SET `requiere_autorizacion` = 1
               WHERE `nombre` IN ('Cardiología','Traumatología','Ginecología')");
    $add(true, "Reglas de autorización aplicadas");

    // 3b) Columna es_admin en usuarios + designar administradores (panel #9/#10)
    $existeAdm = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'usuarios'
           AND COLUMN_NAME = 'es_admin'"
    )->fetchColumn();
    if ($existeAdm === 0) {
        $db->exec("ALTER TABLE `usuarios` ADD COLUMN `es_admin` TINYINT(1) NOT NULL DEFAULT 0");
        $add(true, "Columna 'es_admin' agregada");
    } else {
        $add(true, "Columna 'es_admin' ya existía");
    }
    // Cuentas con acceso de administrador (edítalas según tu despliegue).
    $admins = ['maria@correo.com', 'steven23matute@gmail.com'];
    $ph = implode(',', array_fill(0, count($admins), '?'));
    $db->prepare("UPDATE `usuarios` SET `es_admin` = 1 WHERE `email` IN ($ph)")->execute($admins);
    $add(true, "Administradores designados: " . implode(', ', $admins));

    // 3c) Columna email_verificado en usuarios (verificación de correo #15)
    $existeVer = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'usuarios'
           AND COLUMN_NAME = 'email_verificado'"
    )->fetchColumn();
    if ($existeVer === 0) {
        $db->exec("ALTER TABLE `usuarios` ADD COLUMN `email_verificado` TINYINT(1) NOT NULL DEFAULT 0");
        $add(true, "Columna 'email_verificado' agregada");
    } else {
        $add(true, "Columna 'email_verificado' ya existía");
    }

    // 3d) Tabla de tokens de correo: sirve para recuperación ('reset') y
    //     verificación ('verify'). El token viaja en el enlace; en la BD se
    //     guarda solo su hash SHA-256 (si alguien ve la tabla, no puede usarlo).
    $db->exec("CREATE TABLE IF NOT EXISTS `tokens_correo` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `usuario_id` INT NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `tipo` VARCHAR(10) NOT NULL DEFAULT 'reset',
        `expira` DATETIME NOT NULL,
        `usado` TINYINT(1) NOT NULL DEFAULT 0,
        `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `token_hash` (`token_hash`),
        KEY `usuario_id` (`usuario_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $add(true, "Tabla 'tokens_correo' lista");

    // 4) Usuario de prueba (solo si no existe)
    $st = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
    $st->execute(['maria@correo.com']);
    if (!$st->fetch()) {
        // plan BMI - Cobertura Nacional si existe; si no, el primer plan disponible
        $planId = $db->query("SELECT id FROM planes WHERE aseguradora='BMI' AND nombre='Cobertura Nacional' LIMIT 1")->fetchColumn();
        if (!$planId) { $planId = $db->query("SELECT id FROM planes ORDER BY id LIMIT 1")->fetchColumn(); }
        $ins = $db->prepare("INSERT INTO usuarios (nombre,email,password_hash,plan_id) VALUES (?,?,?,?)");
        $ins->execute(['María Cedeño', 'maria@correo.com', password_hash('123456', PASSWORD_DEFAULT), $planId ?: null]);
        $add(true, "Usuario de prueba creado: maria@correo.com / 123456");
    } else {
        $add(true, "Usuario de prueba ya existía");
    }

    $ok = true;
} catch (Throwable $e) {
    $ok = false;
    $errorMsg = $e->getMessage();
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Instalación · Estimador Copago</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;background:#e9e6dd;margin:0;padding:40px 16px;color:#10231f}
  .box{max-width:620px;margin:0 auto;background:#fff;border-radius:16px;padding:30px 34px;box-shadow:0 20px 50px -30px rgba(0,0,0,.4)}
  h1{font-size:22px;margin:0 0 6px}
  .ok{color:#128a4e}.bad{color:#b23c3c}
  li{margin:7px 0;font-size:14.5px;line-height:1.5}
  a.btn{display:inline-block;margin-top:20px;background:#0f5c5c;color:#fff;text-decoration:none;padding:12px 20px;border-radius:11px;font-weight:600}
</style></head><body>
<div class="box">
  <h1>Instalación de la base de datos</h1>
  <?php if (!empty($ok)): ?>
    <p style="color:#555">Todo listo. Puedes ejecutar esto de nuevo sin problema.</p>
    <ul>
      <?php foreach ($pasos as [$s, $m]): ?>
        <li class="<?= $s ? 'ok' : 'bad' ?>"><?= $s ? '✓' : '✕' ?> <?= $h($m) ?></li>
      <?php endforeach; ?>
    </ul>
    <a class="btn" href="login.php">Ir a iniciar sesión →</a>
    <p style="font-size:12.5px;color:#999;margin-top:18px">Por seguridad, puedes borrar este archivo (setup.php) cuando termines de instalar.</p>
  <?php else: ?>
    <p class="bad">✕ Error durante la instalación:</p>
    <pre style="background:#fdeaea;padding:14px;border-radius:10px;white-space:pre-wrap"><?= $h($errorMsg ?? 'desconocido') ?></pre>
    <p style="font-size:13.5px;color:#555">Asegúrate de haber importado primero el backup base (copago_backup_2026-05-21.sql) y de que la base de datos esté accesible.</p>
  <?php endif; ?>
</div>
</body></html>

-- autorizacion.sql  -  Agrega la detección de "autorización previa" del seguro.
-- Regla de negocio (defendible): las especialidades de mayor complejidad
-- (costo de referencia alto) requieren autorización previa de la aseguradora;
-- la atención primaria no. Ejecuta este archivo UNA vez en la base `copago`.

ALTER TABLE `especialidades`
  ADD COLUMN IF NOT EXISTS `requiere_autorizacion` TINYINT(1) NOT NULL DEFAULT 0;

-- Atención primaria / baja complejidad -> NO requiere autorización
UPDATE `especialidades` SET `requiere_autorizacion` = 0
  WHERE `nombre` IN ('Medicina General', 'Pediatría', 'Dermatología');

-- Especialidades de mayor complejidad -> SÍ requieren autorización previa
UPDATE `especialidades` SET `requiere_autorizacion` = 1
  WHERE `nombre` IN ('Cardiología', 'Traumatología', 'Ginecología');

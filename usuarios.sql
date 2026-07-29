-- usuarios.sql  -  Tabla de usuarios para el login real.
-- Ejecuta este archivo UNA vez en tu base de datos `copago`
-- (en phpMyAdmin: selecciona la base `copago` -> pestaña "Importar" -> elige este archivo).

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `plan_id` (`plan_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `planes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Usuario de prueba (para que puedas entrar de una vez):
--   correo:      maria@correo.com
--   contraseña:  123456
--   plan:        BMI - Cobertura Nacional (id 13)
INSERT INTO `usuarios` (`nombre`, `email`, `password_hash`, `plan_id`)
SELECT 'María Cedeño', 'maria@correo.com',
       '$2y$10$nwl8fnTDBpJq7kjuVLxj1uD.0mz52KDLer6HQjWUJp/9wYaGAwwNC', 13
WHERE NOT EXISTS (SELECT 1 FROM `usuarios` WHERE `email` = 'maria@correo.com');

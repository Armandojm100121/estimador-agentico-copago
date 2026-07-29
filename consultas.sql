-- consultas.sql  -  Historial de estimaciones por usuario.
-- Cada vez que la IA calcula un copago para un usuario logueado, se guarda aquí.
-- Ejecuta este archivo UNA vez en la base `copago`.

CREATE TABLE IF NOT EXISTS `consultas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `sintoma` varchar(500) DEFAULT NULL,
  `especialidad` varchar(100) DEFAULT NULL,
  `ciudad` varchar(80) DEFAULT NULL,
  `hospital` varchar(150) DEFAULT NULL,
  `red` varchar(80) DEFAULT NULL,
  `copago` decimal(10,2) DEFAULT NULL,
  `porcentaje_cobertura` int(11) DEFAULT NULL,
  `requiere_autorizacion` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `consultas_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backup DB: reserva_empresa
-- Generated: 2026-02-16 06:06:10

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;


-- ----------------------------
-- Table: campo
-- ----------------------------
DROP TABLE IF EXISTS `campo`;
CREATE TABLE `campo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) DEFAULT NULL,
  `tipo` varchar(255) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `precio_hora` varchar(255) DEFAULT NULL,
  `imagen` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `campo` (`id`, `nombre`, `tipo`, `descripcion`, `precio_hora`, `imagen`) VALUES ('1', 'Campo Fútbol 7 - A', 'futbol7', 'Césped sintético, ideal para pachangas y ligas.', '70', 'campo.png');
INSERT INTO `campo` (`id`, `nombre`, `tipo`, `descripcion`, `precio_hora`, `imagen`) VALUES ('2', 'Campo Fútbol 7 - B', 'futbol7', 'Césped sintético, segundo campo de fútbol 7.', '70', 'campo.png');
INSERT INTO `campo` (`id`, `nombre`, `tipo`, `descripcion`, `precio_hora`, `imagen`) VALUES ('3', 'Campo Fútbol Sala - 1', 'futbol_sala', 'Pista de fútbol sala, rápida y técnica.', '60', 'campo_sala.png');
INSERT INTO `campo` (`id`, `nombre`, `tipo`, `descripcion`, `precio_hora`, `imagen`) VALUES ('4', 'Campo Fútbol Sala - 2', 'futbol_sala', 'Segunda pista de fútbol sala para más disponibilidad.', '60', 'campo_sala.png');


-- ----------------------------
-- Table: cliente
-- ----------------------------
DROP TABLE IF EXISTS `cliente`;
CREATE TABLE `cliente` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) DEFAULT NULL,
  `apellidos` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `telefono` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('1', 'Piero', 'Olivares', 'piero@email.com', '600111222');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('2', 'Ana', 'García', 'ana@email.com', '600222333');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('3', 'Luis', 'Pérez', 'luis@email.com', '600333444');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('4', 'Marta', 'Ruiz', 'marta@email.com', '600444555');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('5', 'Jorge', 'Sánchez', 'jorge@email.com', '600555666');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('6', 'Piero', 'Osan', 'piero7ov@gmail.com', '1234332223');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('7', 'Paco', 'Perales', 'paco@gmail.com', '123455432');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('8', 'Borja', 'Casas', 'borjcas10@gmail.com', '345765332');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('9', 'Miguel', 'Hernandez', 'miguel@gmail.com', '3245253254');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('10', 'Marcos', 'Gonzales', 'marcos17@gmail.com', '45563243344');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('11', 'Juan Alberto', 'Garcia', 'juanalberto@gmail.com', '654678543');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('12', 'Piero', 'Ilovan', 'piero7ov@gmail.com', '567543123');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('13', 'Piero', 'Illovan', 'piero7ov@gmail.com', '345678987');
INSERT INTO `cliente` (`id`, `nombre`, `apellidos`, `email`, `telefono`) VALUES ('14', 'Piero', 'Ilovan', 'piero7ov@gmail.com', '612539341');


-- ----------------------------
-- Table: lineareserva
-- ----------------------------
DROP TABLE IF EXISTS `lineareserva`;
CREATE TABLE `lineareserva` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reserva_id` int(11) DEFAULT NULL,
  `campo_id` int(11) DEFAULT NULL,
  `dia` varchar(255) DEFAULT NULL,
  `hora` varchar(255) DEFAULT NULL,
  `duracion` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_lineareserva_reserva` (`reserva_id`),
  KEY `fk_lineareserva_campo` (`campo_id`),
  CONSTRAINT `fk_lineareserva_campo` FOREIGN KEY (`campo_id`) REFERENCES `campo` (`id`),
  CONSTRAINT `fk_lineareserva_reserva` FOREIGN KEY (`reserva_id`) REFERENCES `reserva` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('1', '1', '1', '2026-02-18', '09:00', '1');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('2', '2', '3', '2026-02-18', '11:00', '2');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('3', '3', '2', '2026-02-19', '17:00', '1');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('4', '4', '4', '2026-02-19', '19:00', '2');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('5', '5', '1', '2026-02-20', '20:00', '1');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('12', '9', '1', '2026-02-18', '12:00', '2');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('13', '10', '1', '2026-02-14', '19:00', '2');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('14', '11', '3', '2026-02-18', '15:00', '1');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('15', '12', '4', '2026-02-19', '15:00', '1');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('16', '13', '2', '2026-02-20', '10:00', '1');
INSERT INTO `lineareserva` (`id`, `reserva_id`, `campo_id`, `dia`, `hora`, `duracion`) VALUES ('19', '16', '4', '2026-02-17', '15:00', '1');


-- ----------------------------
-- Table: mantenimiento_log
-- ----------------------------
DROP TABLE IF EXISTS `mantenimiento_log`;
CREATE TABLE `mantenimiento_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `action` varchar(40) NOT NULL,
  `level` varchar(10) NOT NULL DEFAULT 'info',
  `message` text NOT NULL,
  `meta_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `mantenimiento_log` (`id`, `created_at`, `action`, `level`, `message`, `meta_json`) VALUES ('1', '2026-02-16 05:52:48', 'backup_create', 'info', 'Backup creado', '{\"file\":\"backup_20260216_055248_reserva_empresa.sql\",\"size\":9128}');


-- ----------------------------
-- Table: reserva
-- ----------------------------
DROP TABLE IF EXISTS `reserva`;
CREATE TABLE `reserva` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` varchar(255) DEFAULT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  PRIMARY KEY (`id`),
  KEY `fk_reserva_cliente` (`cliente_id`),
  CONSTRAINT `fk_reserva_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `cliente` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('1', '2026-02-14', '1', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('2', '2026-02-15', '2', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('3', '2026-02-15', '3', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('4', '2026-02-16', '4', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('5', '2026-02-16', '5', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('9', '2026-02-14', '8', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('10', '2026-02-14 07:14:12', '9', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('11', '2026-02-15 01:25:27', '10', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('12', '2026-02-16 03:55:00', '5', 'pendiente');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('13', '2026-02-16 04:05:00', '11', 'confirmada');
INSERT INTO `reserva` (`id`, `fecha`, `cliente_id`, `estado`) VALUES ('16', '2026-02-16 05:38:00', '14', 'confirmada');


-- ----------------------------
-- Table: usuarios
-- ----------------------------
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `usuarios` (`id`, `usuario`, `password_hash`, `creado_en`) VALUES ('1', 'piero7ov', '$2y$10$ELvgdyw/S2FQgQfkVO9xPeicWskaUTPlEO1nkcdS7.1JPmsC4kBoe', '2026-02-16 01:58:02');

SET FOREIGN_KEY_CHECKS=1;

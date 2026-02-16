-- Tabla de usuarios (MySQL)
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuario principal: piero7ov / piero7ov
-- (password_hash generado tipo bcrypt compatible con PHP password_verify)
INSERT INTO usuarios (usuario, password_hash)
VALUES (
  'piero7ov',
  '$2y$10$ELvgdyw/S2FQgQfkVO9xPeicWskaUTPlEO1nkcdS7.1JPmsC4kBoe'
);

-- Comprobación de creación de usuario
SELECT id, usuario, creado_en FROM usuarios;

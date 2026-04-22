-- ============================================================
-- Schema recomendado para la tabla calculadora_sesiones
-- BellaNick Clinic - depilasermexico.com
-- Ejecutar en phpMyAdmin (Hostinger) si la tabla aun no existe
-- o si necesitas agregar las columnas opcionales ip/user_agent/created_at.
-- ============================================================

CREATE TABLE IF NOT EXISTS `calculadora_sesiones` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `zona`           VARCHAR(40)  NOT NULL,
  `vello`          VARCHAR(20)  NOT NULL,
  `rasurado`       VARCHAR(20)  NOT NULL,
  `atencion`       VARCHAR(20)  NOT NULL,
  `sesiones_min`   TINYINT UNSIGNED NULL,
  `sesiones_max`   TINYINT UNSIGNED NULL,
  `nombre`         VARCHAR(120) NULL,
  `telefono`       VARCHAR(30)  NULL,
  `url`            VARCHAR(500) NULL,
  `ip`             VARCHAR(45)  NULL,
  `user_agent`     VARCHAR(255) NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_zona` (`zona`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si la tabla ya existe sin las columnas de auditoria, ejecuta:
-- ALTER TABLE calculadora_sesiones
--   ADD COLUMN ip VARCHAR(45) NULL AFTER url,
--   ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip,
--   ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER user_agent;

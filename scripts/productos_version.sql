-- MariaDB 10.4. Migración aditiva para el esquema actual con id_producto e id_area.
ALTER TABLE productos ADD COLUMN IF NOT EXISTS version INT UNSIGNED NOT NULL DEFAULT 1;

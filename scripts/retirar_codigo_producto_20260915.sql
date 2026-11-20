-- Ejecutar una sola vez después de adaptar el módulo al historial de códigos.
-- Respaldo: respaldo_codigos_antes_retiro_20260915.sql.
-- Antes de ejecutar, comprobar que cada productos.codigo coincide con su
-- código vigente en producto_codigos / codigos_inventario.
ALTER TABLE productos DROP COLUMN codigo;

<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
// Reutiliza fixtures privados y verifica que el guardado anterior siga funcionando.
require __DIR__ . '/probar_guardado_codigos.php';
foreach (['producto_asignaciones', 'inventario_movimientos'] as $tabla) {
    $ddl = $c->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
    $c->exec($ddl);
}
$c->exec("INSERT INTO usuarios (id_usuario,cedula,nombre,apellido,correo,id_area,rol,estado)
    VALUES (2,'2000','Ana','Prueba','ana@example.test',1,'FUNCIONARIO','ACTIVO'),
           (3,'3000','Luis','Prueba','luis@example.test',1,'FUNCIONARIO','ACTIVO')");
$equipo = consultarProducto($c, $id2);
$version = (int)$equipo['version'];
$codigoLiberado = (int)$equipo['id_codigo'];
$c->exec("INSERT INTO producto_asignaciones (id_producto,id_usuario) VALUES ($id2,2),($id2,3)");
$fecha = (string)$c->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
rechazar(fn () => darDeBajaProducto($c, $id2, $version, $fecha, '', 1));
rechazar(fn () => darDeBajaProducto($c, $id2, $version, '2099-01-01T12:00:00', 'Prueba', 1));
rechazar(fn () => darDeBajaProducto($c, $id2, $version, '2000-01-01T12:00:00', 'Prueba', 1));
rechazar(fn () => darDeBajaProducto($c, $id2, $version + 1, $fecha, 'Prueba', 1));
rechazar(fn () => darDeBajaProducto($c, $id2, $version, $fecha, 'Prueba', 2));
rechazar(fn () => guardarPrueba($c, array_replace($conservar, ['estado' => 'INACTIVO', 'version' => $version]), $id2));
verificar((int)$c->query('SELECT COUNT(*) FROM inventario_movimientos')->fetchColumn() === 0, 'Validaciones y rutas antiguas no registran bajas');
// Fuerza un fallo al final: movimiento y cierres previos deben revertirse.
$c->exec("ALTER TABLE productos ADD CONSTRAINT prueba_fallo_baja CHECK (estado='ACTIVO')");
try {
    darDeBajaProducto($c, $id2, $version, $fecha, 'Prueba', 1); throw new RuntimeException('Faltó el fallo esperado');
} catch (PDOException $e) {
    verificar((int)$e->errorInfo[1] === 4025, 'Fallo controlado al actualizar estado');
}
verificar((int)$c->query('SELECT COUNT(*) FROM inventario_movimientos')->fetchColumn() === 0, 'Rollback elimina movimiento incompleto');
verificar((int)$c->query('SELECT COUNT(*) FROM producto_asignaciones WHERE fecha_devolucion IS NULL')->fetchColumn() === 2 && consultarProducto($c, $id2)['id_codigo'] === $codigoLiberado, 'Rollback conserva responsables y código');
$c->exec('ALTER TABLE productos DROP CONSTRAINT prueba_fallo_baja');
$antes = (string)$c->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
darDeBajaProducto($c, $id2, $version, null, 'Equipo averiado', 1);
$despues = (string)$c->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
$retirado = consultarProducto($c, $id2);
verificar($retirado['estado'] === 'INACTIVO' && $retirado['id_codigo'] === null, 'Baja cambia estado y libera código');
verificar((int)$c->query('SELECT COUNT(*) FROM producto_asignaciones WHERE fecha_devolucion IS NULL')->fetchColumn() === 0, 'Cierra todos los responsables');
$movimiento = $c->query('SELECT * FROM inventario_movimientos')->fetch();
$fecha = $movimiento['fecha_movimiento'];
verificar($fecha >= $antes && $fecha <= $despues, 'Fecha automática corresponde al momento de confirmar la baja');
verificar(
    (int)$c->query('SELECT COUNT(*) FROM producto_asignaciones WHERE fecha_devolucion <> ' . $c->quote($fecha))->fetchColumn() === 0
    && (int)$c->query('SELECT COUNT(*) FROM producto_codigos WHERE id_producto = ' . $id2 . ' AND fecha_fin <> ' . $c->quote($fecha))->fetchColumn() === 0,
    'Movimiento, asignaciones y código se cierran con la misma fecha automática'
);
verificar($movimiento['motivo'] === 'Equipo averiado' && (int)$movimiento['id_usuario_registro'] === 1 && $movimiento['fecha_movimiento'] === $fecha, 'Conserva motivo, fecha y administrador');
rechazar(fn () => darDeBajaProducto($c, $id2, $version + 1, $fecha, 'Repetida', 1));
rechazar(fn () => guardarPrueba($c, $base + ['version' => $version + 1, 'atributos' => [1 => '8']], $id2));
verificar((int)$c->query('SELECT COUNT(*) FROM inventario_movimientos')->fetchColumn() === 1, 'No duplica baja ni permite nuevas asignaciones de código al equipo');
$reuso = guardarPrueba($c, ['id_categoria' => 1, 'id_area' => 1, 'descripcion' => 'Reemplazo', 'id_codigo' => $codigoLiberado, 'atributos' => [1 => '8']]);
verificar(consultarProducto($c, $reuso)['id_codigo'] === $codigoLiberado, 'Otro equipo puede utilizar el código liberado');
echo "Bajas verificadas sin cambios en registros reales.\n";

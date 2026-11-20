<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
// Todas las mutaciones se realizan en tablas temporales de esta conexión.
require __DIR__ . '/probar_guardado_codigos.php';
require_once __DIR__ . '/../admin/inventario/servicio.php';
foreach (['producto_asignaciones', 'inventario_movimientos'] as $tabla) {
    $ddl = $c->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
    $c->exec($ddl);
}
$c->exec('CREATE TEMPORARY TABLE usuarios (id_usuario INT PRIMARY KEY, rol VARCHAR(20), estado VARCHAR(20), nombre VARCHAR(80), apellido VARCHAR(80), cedula VARCHAR(20), id_area INT)');
$c->exec("INSERT INTO usuarios VALUES (1,'ADMINISTRADOR','ACTIVO','Admin','Prueba','111',1),(2,'FUNCIONARIO','ACTIVO','Persona','Prueba','222',1)");
modificarAsignacionInventario($c, 'asignar', $id2, 2);
$equipo = consultarProducto($c, $id2);
try {
    darDeBajaProducto($c, $id2, (int)$equipo['version'], (string)$c->query('SELECT CURRENT_TIMESTAMP')->fetchColumn(), 'Prueba aislada de baja', 1);
    echo "BAJA: completada\n";
} catch (PDOException $e) {
    echo 'BAJA: SQLSTATE=' . $e->getCode() . ' detalle=' . $e->getMessage() . "\n";
    verificar((int)$c->query('SELECT COUNT(*) FROM inventario_movimientos')->fetchColumn() === 0, 'Baja fallida no conserva movimiento parcial');
    verificar(consultarProducto($c, $id2)['estado'] === 'ACTIVO', 'Baja fallida conserva equipo activo');
    verificar((int)$c->query('SELECT COUNT(*) FROM producto_asignaciones WHERE fecha_devolucion IS NULL')->fetchColumn() === 1, 'Baja fallida conserva asignación');
}
// Simula únicamente el UPDATE de estado observado en el controlador de usuarios.
$c->exec("UPDATE usuarios SET estado='INACTIVO' WHERE id_usuario=2");
verificar(consultarInventario($c, filtrosInventario(['usuario' => '222']))['total'] === 1, 'Usuario inactivo sigue como responsable actual');
verificar(buscarResponsablesInventario($c, '222', $id) === [], 'Usuario inactivo no recibe nuevas asignaciones');
$asignacion = (int)$c->query('SELECT id_producto_asignacion FROM producto_asignaciones WHERE fecha_devolucion IS NULL')->fetchColumn();
modificarAsignacionInventario($c, 'devolver', $id2, $asignacion);
verificar((int)consultarProducto($c, $id2)['disponible'] === 1, 'Es posible registrar devolución de usuario inactivo');
foreach (['areas', 'categorias'] as $tabla) {
    // Simulación del efecto de desactivar un catálogo; no ejecuta el controlador HTTP.
    $c->exec("UPDATE $tabla SET estado='INACTIVO'");
    rechazar(fn () => guardarPrueba($c, $base + ['atributos' => [1 => '8']]));
    $actual = consultarProducto($c, $id2);
    guardarPrueba($c, array_replace($base, ['generar_codigo' => '0', 'id_codigo' => $actual['id_codigo'], 'version' => $actual['version'], 'atributos' => [1 => '8']]), $id2);
    verificar(true, "$tabla inactivas: rechaza nuevos productos y permite conservar vínculos existentes");
    modificarAsignacionInventario($c, 'asignar', $id2, 1);
    verificar(true, "Inventario permite asignar equipo activo con $tabla inactivas");
    $asignacion = (int)$c->query('SELECT id_producto_asignacion FROM producto_asignaciones WHERE fecha_devolucion IS NULL')->fetchColumn();
    modificarAsignacionInventario($c, 'devolver', $id2, $asignacion);
    $c->exec("UPDATE $tabla SET estado='ACTIVO'");
}
echo "Revisión finalizada. Sin cambios en registros reales.\n";

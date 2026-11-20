<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Reutiliza las tablas temporales privadas de la conexión.
require __DIR__ . '/probar_inventario.php';

$historial = consultarHistorialProducto($db, 1);
comprobarInventario($historial['total'] === 3, 'Conserva las tres asignaciones, incluidas devolución y reasignación');
comprobarInventario(count(array_filter($historial['asignaciones'], fn ($fila) => $fila['fecha_devolucion'] !== null)) === 3, 'Historial incluye todas las asignaciones finalizadas');
comprobarInventario(consultarHistorialProducto($db, 2)['total'] === 0, 'Producto sin asignaciones devuelve historial vacío');
comprobarInventario(consultarHistorialProducto($db, 999) === null, 'Producto inexistente no devuelve historial de otro producto');

modificarAsignacionInventario($db, 'asignar', 1, 2, $idAdministradorPrueba);
$historial = consultarHistorialProducto($db, 1);
comprobarInventario($historial['total'] === 4 && $historial['asignaciones'][0]['fecha_devolucion'] === null, 'Historial incluye asignación vigente y ordena empates por identificador');
comprobarInventario($historial['asignaciones'][0]['cedula'] === '654321', 'Incluye la cédula del responsable');
$db->exec('UPDATE producto_asignaciones SET fecha_devolucion=CURRENT_TIMESTAMP WHERE fecha_devolucion IS NULL');
$db->exec('UPDATE productos SET estado="INACTIVO" WHERE id_producto=1');
comprobarInventario(consultarHistorialProducto($db, 1)['total'] === 4, 'Producto dado de baja conserva historial consultable');

for ($i = 0; $i < 12; $i++) {
    $db->exec('INSERT INTO producto_asignaciones (id_producto,id_usuario,fecha_devolucion) VALUES (1,1,CURRENT_TIMESTAMP)');
}
$primera = consultarHistorialProducto($db, 1);
$ultima = consultarHistorialProducto($db, 1, PHP_INT_MAX);
comprobarInventario($primera['total'] === 16 && count($primera['asignaciones']) === 10, 'Limita la primera página a diez registros');
comprobarInventario($ultima['pagina'] === 2 && count($ultima['asignaciones']) === 6, 'Página fuera de rango se ajusta a la última');
comprobarInventario(array_intersect(array_column($primera['asignaciones'], 'id_producto_asignacion'), array_column($ultima['asignaciones'], 'id_producto_asignacion')) === [], 'Páginas sin registros duplicados');
echo "Historial verificado sin modificar registros reales.\n";

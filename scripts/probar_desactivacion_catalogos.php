<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
require __DIR__ . '/probar_inventario.php';
require_once __DIR__ . '/../admin/inventario/desactivacion.php';

foreach (['area' => ['areas', 'id_area'], 'categoria' => ['categorias', 'id_categoria']] as $tipo => [$tabla,$columna]) {
    modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba);
    modificarAsignacionInventario($db, 'asignar', 1, 2, $idAdministradorPrueba);
    $db->beginTransaction();
    try {
        exigirCatalogoSinAsignaciones($db, $tipo, 1);
        throw new RuntimeException('Se permitió desactivar con asignaciones pendientes');
    } catch (DomainException $error) {
        comprobarInventario(str_contains($error->getMessage(), '1 producto con asignaciones'), "$tipo: cuenta productos, no responsables");
    } finally {
        $db->rollBack();
    }
    $asignaciones = $db->query('SELECT id_producto_asignacion FROM producto_asignaciones WHERE fecha_devolucion IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    modificarAsignacionInventario($db, 'devolver', 1, (int)$asignaciones[0], $idAdministradorPrueba);
    $db->beginTransaction();
    try {
        rechazarInventario(fn () => exigirCatalogoSinAsignaciones($db, $tipo, 1));
    } finally {
        $db->rollBack();
    }
    modificarAsignacionInventario($db, 'devolver', 1, (int)$asignaciones[1], $idAdministradorPrueba);
    $db->beginTransaction();
    exigirCatalogoSinAsignaciones($db, $tipo, 1);
    $db->exec("UPDATE $tabla SET estado='INACTIVO' WHERE $columna=1");
    $db->commit();
    rechazarInventario(fn () => modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba));
    comprobarInventario(true, "$tipo: permite desactivar tras devolver todo y rechaza nuevas asignaciones");
    $db->exec("UPDATE $tabla SET estado='ACTIVO' WHERE $columna=1");
}
echo "Pruebas de catálogos completadas en tablas temporales.\n";

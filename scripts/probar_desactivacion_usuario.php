<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
require __DIR__ . '/probar_inventario.php';
require_once __DIR__ . '/../admin/usuarios/servicio.php';

// probar_inventario deja tablas temporales y todas las asignaciones devueltas.
function intentarDesactivarPrueba(PDO $db, int $id): void
{
    $db->beginTransaction();
    try {
        exigirUsuarioSinAsignaciones($db, $id);
        $db->prepare('UPDATE usuarios SET estado = "INACTIVO" WHERE id_usuario = ?')->execute([$id]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba);
rechazarInventario(fn () => intentarDesactivarPrueba($db, 1));
comprobarInventario(
    $db->query('SELECT estado FROM usuarios WHERE id_usuario=1')->fetchColumn() === 'ACTIVO',
    'Usuario con producto asignado permanece activo'
);
modificarAsignacionInventario($db, 'asignar', 2, 1, $idAdministradorPrueba);
try {
    intentarDesactivarPrueba($db, 1);
    throw new RuntimeException('Se permitió desactivar con asignaciones');
} catch (DomainException $error) {
    comprobarInventario(
        str_contains($error->getMessage(), '2 productos asignados') && str_contains($error->getMessage(), 'desasignarlos'),
        'Mensaje informa cantidad y solicita desasignar'
    );
}
$pendientes = $db->query('SELECT id_producto_asignacion, id_producto FROM producto_asignaciones WHERE id_usuario=1 AND fecha_devolucion IS NULL')->fetchAll();
modificarAsignacionInventario($db, 'devolver', (int)$pendientes[0]['id_producto'], (int)$pendientes[0]['id_producto_asignacion'], $idAdministradorPrueba);
rechazarInventario(fn () => intentarDesactivarPrueba($db, 1));
modificarAsignacionInventario($db, 'devolver', (int)$pendientes[1]['id_producto'], (int)$pendientes[1]['id_producto_asignacion'], $idAdministradorPrueba);
intentarDesactivarPrueba($db, 1);
comprobarInventario(
    $db->query('SELECT estado FROM usuarios WHERE id_usuario=1')->fetchColumn() === 'INACTIVO',
    'Permite desactivar después de devolver todos los productos, conservando historial'
);
rechazarInventario(fn () => modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba));
rechazarInventario(fn () => intentarDesactivarPrueba($db, 99999));
echo "Desactivación verificada sin modificar registros reales.\n";

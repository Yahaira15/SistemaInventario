<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}

// Reutiliza tablas temporales privadas; no altera registros reales.
require __DIR__ . '/probar_inventario.php';

foreach ([['asignar', 0, 1], ['asignar', -1, 1], ['asignar', 1, 999999],
    ['devolver', 1, 999999], ['eliminar', 1, 1]] as [$accion, $producto, $destino]) {
    rechazarInventario(fn () => modificarAsignacionInventario($db, $accion, $producto, $destino, $idAdministradorPrueba));
    comprobarInventario(!$db->inTransaction(), 'Entrada inválida rechazada y transacción cerrada');
}

$antes = (int)$db->query('SELECT COUNT(*) FROM producto_asignaciones')->fetchColumn();
$db->exec('ALTER TABLE productos ADD CONSTRAINT auditoria_disponible CHECK (disponible = 1)');
try {
    modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba);
    throw new RuntimeException('No se produjo el fallo de disponibilidad esperado');
} catch (PDOException $error) {
    comprobarInventario(!$db->inTransaction(), 'Fallo de base de datos cierra la transacción');
    comprobarInventario(
        (int)$db->query('SELECT COUNT(*) FROM producto_asignaciones')->fetchColumn() === $antes,
        'Fallo posterior al INSERT revierte la asignación'
    );
} finally {
    $db->exec('ALTER TABLE productos DROP CONSTRAINT auditoria_disponible');
}

modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba);
try {
    $db->exec('INSERT INTO producto_asignaciones (id_producto,id_usuario) VALUES (1,1)');
    throw new RuntimeException('La base de datos aceptó una asignación activa duplicada');
} catch (PDOException $error) {
    comprobarInventario($error->getCode() === '23000', 'Restricción SQL rechaza duplicados sin depender del servicio');
}

foreach ([['usuario' => str_repeat('a', 500)], ['usuario' => ['x']], ['categoria' => [['x'], -1, '1 OR 1=1']],
    ['pagina' => PHP_INT_MAX], ['area' => ['x']], ['estado' => ['ACTIVO']]] as $entrada) {
    $resultado = consultarInventario($db, filtrosInventario($entrada));
    comprobarInventario(is_array($resultado['productos']), 'Parámetros extremos o malformados no rompen el listado');
}

// Solo metadatos de la conexión y del esquema real: no imprime credenciales ni personas.
$real = require __DIR__ . '/../config/conexion.php';
$grants = $real->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
$global = false;
foreach ($grants as $grant) {
    if (str_contains($grant, 'ALL PRIVILEGES ON *.*')) {
        $global = true;
    }
}
echo 'HALLAZGO: permisos globales ALL PRIVILEGES = ' . ($global ? 'SI' : 'NO') . "\n";
echo 'METADATO: versión de BD = ' . $real->query('SELECT VERSION()')->fetchColumn() . "\n";
$columnas = $real->query('SHOW COLUMNS FROM producto_asignaciones')->fetchAll(PDO::FETCH_COLUMN);
echo 'METADATO: columnas de asignaciones = ' . implode(', ', $columnas) . "\n";
$triggers = $real->query("SHOW TRIGGERS WHERE `Table` = 'producto_asignaciones'")->fetchAll();
echo 'METADATO: triggers de asignaciones = ' . count($triggers) . "\n";
echo "Auditoría adicional finalizada sin modificar registros reales.\n";

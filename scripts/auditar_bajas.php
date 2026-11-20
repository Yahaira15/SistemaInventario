<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$conexion = require __DIR__ . '/../config/conexion.php';
// Solo lectura y conteos: no muestra datos personales ni modifica registros.
$consultas = [
    'Equipos inactivos' => 'SELECT COUNT(*) FROM productos WHERE estado="INACTIVO"',
    'Inactivos sin movimiento de baja' => 'SELECT COUNT(*) FROM productos p WHERE p.estado="INACTIVO" AND NOT EXISTS (SELECT 1 FROM inventario_movimientos m WHERE m.id_producto=p.id_producto AND m.tipo_movimiento="BAJA")',
    'Inactivos con responsables vigentes' => 'SELECT COUNT(*) FROM productos p WHERE p.estado="INACTIVO" AND EXISTS (SELECT 1 FROM producto_asignaciones a WHERE a.id_producto=p.id_producto AND a.fecha_devolucion IS NULL)',
    'Inactivos con código vigente' => 'SELECT COUNT(*) FROM productos p WHERE p.estado="INACTIVO" AND EXISTS (SELECT 1 FROM producto_codigos c WHERE c.id_producto=p.id_producto AND c.fecha_fin IS NULL)',
    'Inactivos marcados disponibles' => 'SELECT COUNT(*) FROM productos WHERE estado="INACTIVO" AND disponible<>0',
    'Activos con movimiento de baja' => 'SELECT COUNT(*) FROM productos p WHERE p.estado="ACTIVO" AND EXISTS (SELECT 1 FROM inventario_movimientos m WHERE m.id_producto=p.id_producto AND m.tipo_movimiento="BAJA")',
    'Equipos con bajas duplicadas' => 'SELECT COUNT(*) FROM (SELECT id_producto FROM inventario_movimientos WHERE tipo_movimiento="BAJA" GROUP BY id_producto HAVING COUNT(*)>1) d',
    'Bajas anteriores al historial' => 'SELECT COUNT(*) FROM inventario_movimientos m JOIN productos p ON p.id_producto=m.id_producto WHERE m.tipo_movimiento="BAJA" AND (m.fecha_movimiento<p.fecha_creacion OR EXISTS (SELECT 1 FROM producto_asignaciones a WHERE a.id_producto=p.id_producto AND (a.fecha_asignacion>m.fecha_movimiento OR a.fecha_devolucion>m.fecha_movimiento)) OR EXISTS (SELECT 1 FROM producto_codigos c WHERE c.id_producto=p.id_producto AND (c.fecha_inicio>m.fecha_movimiento OR c.fecha_fin>m.fecha_movimiento)) OR EXISTS (SELECT 1 FROM inventario_movimientos e WHERE e.id_producto=p.id_producto AND e.tipo_movimiento="ENTRADA" AND e.fecha_movimiento>m.fecha_movimiento))',
];
foreach ($consultas as $etiqueta => $sql) {
    echo $etiqueta . ': ' . (int)$conexion->query($sql)->fetchColumn() . PHP_EOL;
}
$sql = 'SELECT p.id_producto, m.fecha_movimiento AS fecha_baja, p.fecha_creacion,
    (SELECT MAX(GREATEST(a.fecha_asignacion, COALESCE(a.fecha_devolucion,a.fecha_asignacion))) FROM producto_asignaciones a WHERE a.id_producto=p.id_producto) AS ultimo_evento_asignacion,
    (SELECT MAX(GREATEST(c.fecha_inicio, COALESCE(c.fecha_fin,c.fecha_inicio))) FROM producto_codigos c WHERE c.id_producto=p.id_producto) AS ultimo_evento_codigo,
    (SELECT MAX(e.fecha_movimiento) FROM inventario_movimientos e WHERE e.id_producto=p.id_producto AND e.tipo_movimiento="ENTRADA") AS ultima_entrada
    FROM productos p JOIN inventario_movimientos m ON m.id_producto=p.id_producto AND m.tipo_movimiento="BAJA"';
foreach ($conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $registro) {
    foreach (['fecha_creacion','ultimo_evento_asignacion','ultimo_evento_codigo','ultima_entrada'] as $campo) {
        if ($registro[$campo] !== null && $registro[$campo] > $registro['fecha_baja']) {
            echo 'Cronología inconsistente: equipo #' . $registro['id_producto'] . ', baja ' . $registro['fecha_baja'] . ', ' . $campo . ' ' . $registro[$campo] . PHP_EOL;
        }
    }
}

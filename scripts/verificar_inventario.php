<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Verifica restricciones con datos temporales; siempre revierte las filas.
$db = require __DIR__ . '/../config/conexion.php';
function rechaza(PDO $db, string $sql): void
{
    try {
        $db->exec($sql);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return;
        }
        throw $e;
    }
    throw new RuntimeException('La base de datos acepto una operacion invalida.');
}
$db->beginTransaction();
try {
    $p = $db->query('SELECT id_producto, id_categoria FROM productos ORDER BY id_producto LIMIT 1')->fetch();
    $pid = (int)$p['id_producto'];
    $cat = (int)$p['id_categoria'];
    $users = $db->query('SELECT id_usuario FROM usuarios ORDER BY id_usuario LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    if (count($users) < 2) {
        throw new RuntimeException('Se necesitan dos usuarios para la verificacion.');
    }
    [$u1, $u2] = array_map('intval', $users);
    $db->exec("INSERT INTO producto_asignaciones (id_producto,id_usuario) VALUES ($pid,$u1),($pid,$u2)");
    rechaza($db, "INSERT INTO producto_asignaciones (id_producto,id_usuario) VALUES ($pid,$u1)");
    $db->exec("UPDATE producto_asignaciones SET fecha_devolucion=NOW() WHERE id_producto=$pid AND id_usuario=$u1");
    $db->exec("INSERT INTO producto_asignaciones (id_producto,id_usuario) VALUES ($pid,$u1)");
    rechaza($db, "UPDATE producto_asignaciones SET fecha_devolucion='2000-01-01' WHERE id_producto=$pid");
    $c = (int)$db->query("SELECT id_codigo FROM producto_codigos WHERE producto_vigente=$pid")->fetchColumn();
    rechaza($db, "INSERT INTO producto_codigos (id_producto,id_codigo,id_categoria) VALUES ($pid,$c,$cat)");
    $other = $db->query("SELECT id_producto,id_categoria FROM productos WHERE id_categoria<>$cat LIMIT 1")->fetch();
    if (!$other) {
        throw new RuntimeException('Falta una segunda categoria para verificar incompatibilidad.');
    }
    $op = (int)$other['id_producto'];
    $oc = (int)$other['id_categoria'];
    $db->exec("UPDATE producto_codigos SET fecha_fin=NOW() WHERE producto_vigente IN ($pid,$op)");
    rechaza($db, "INSERT INTO producto_codigos (id_producto,id_codigo,id_categoria) VALUES ($op,$c,$oc)");
    $db->exec("INSERT INTO producto_codigos (id_producto,id_codigo,id_categoria) VALUES ($pid,$c,$cat)");
    $db->exec("INSERT INTO inventario_movimientos (id_producto,tipo_movimiento,motivo,id_usuario_registro) VALUES ($pid,'BAJA','Prueba temporal',$u1)");
    rechaza($db, "INSERT INTO inventario_movimientos (id_producto,tipo_movimiento,motivo,id_usuario_registro) VALUES ($pid,'ENTRADA','',$u1)");
    echo "OK: multiples responsables, duplicados, reasignacion, fechas, categoria de codigos y movimientos.\n";
} finally {
    $db->rollBack();
}

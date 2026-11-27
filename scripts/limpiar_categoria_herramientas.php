<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('--ejecutar', $argv, true)) {
    exit("Requiere --ejecutar y un respaldo previo mediante mysqldump.\n");
}
$respaldo = $argv[2] ?? '';
if (!is_file($respaldo) || filesize($respaldo) < 1000) {
    throw new RuntimeException('No existe un respaldo válido.');
}
$db = require __DIR__ . '/../config/conexion.php';
$db->beginTransaction();
try {
    $cat = $db->query("SELECT * FROM categorias WHERE id_categoria=15 AND nombre='HERRAMIENTAS' FOR UPDATE")->fetch();
    if (!$cat) throw new RuntimeException('La categoría esperada no existe.');
    $productos = $db->query('SELECT id_producto FROM productos WHERE id_categoria=15 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
    $relaciones = $db->query('SELECT id_categoria_atributo FROM categoria_atributos WHERE id_categoria=15 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
    $atributos = $db->query('SELECT DISTINCT ca.id_atributo FROM categoria_atributos ca WHERE ca.id_categoria=15 AND NOT EXISTS (SELECT 1 FROM categoria_atributos otra WHERE otra.id_atributo=ca.id_atributo AND otra.id_categoria<>15)')->fetchAll(PDO::FETCH_COLUMN);
    $otrosAntes = $db->query('SELECT * FROM productos WHERE id_categoria<>15 ORDER BY id_producto')->fetchAll();
    $categoriasAntes = $db->query('SELECT * FROM categorias WHERE id_categoria<>15 ORDER BY id_categoria')->fetchAll();
    $modeloAntes = $db->query('SELECT * FROM atributos WHERE id_atributo=47')->fetch();
    $lista = static fn(array $ids): string => $ids ? implode(',', array_map('intval', $ids)) : 'NULL';
    $p = $lista($productos); $r = $lista($relaciones); $a = $lista($atributos);
    // Impide borrar valores o referencias que pertenezcan a equipos ajenos.
    $conflictos = $db->query("SELECT COUNT(*) FROM producto_atributo_valores v JOIN productos p USING(id_producto) LEFT JOIN atributo_opciones o ON o.id_atributo_opcion=v.id_atributo_opcion LEFT JOIN atributo_unidades u ON u.id_atributo_unidad=v.id_atributo_unidad WHERE p.id_categoria<>15 AND (v.id_categoria_atributo IN ($r) OR o.id_atributo IN ($a) OR u.id_atributo IN ($a))")->fetchColumn();
    if ((int)$conflictos) throw new RuntimeException('Existen referencias compartidas inesperadas. Se cancela.');
    $cuentas = [];
    $sentencias = [
        'auditoria_eventos' => "DELETE FROM auditoria_eventos WHERE (entidad='categorias' AND id_registro=15) OR (entidad='productos' AND id_registro IN ($p)) OR (entidad='categoria_atributos' AND id_registro IN ($r)) OR (entidad='atributos' AND id_registro IN ($a)) OR (entidad='producto_asignaciones' AND id_registro IN (SELECT id_producto_asignacion FROM producto_asignaciones WHERE id_producto IN ($p))) OR (entidad='atributo_opciones' AND id_registro IN (SELECT id_atributo_opcion FROM atributo_opciones WHERE id_atributo IN ($a)))",
        'producto_atributo_valores' => "DELETE FROM producto_atributo_valores WHERE id_producto IN ($p)",
        'producto_asignaciones' => "DELETE FROM producto_asignaciones WHERE id_producto IN ($p)",
        'inventario_movimientos' => "DELETE FROM inventario_movimientos WHERE id_producto IN ($p)",
        'producto_codigos' => 'DELETE FROM producto_codigos WHERE id_categoria=15',
        'productos' => 'DELETE FROM productos WHERE id_categoria=15',
        'codigos_inventario' => 'DELETE FROM codigos_inventario WHERE id_categoria=15',
        'categoria_atributos' => 'DELETE FROM categoria_atributos WHERE id_categoria=15',
        'atributo_opciones' => "DELETE FROM atributo_opciones WHERE id_atributo IN ($a)",
        'atributo_unidades' => "DELETE FROM atributo_unidades WHERE id_atributo IN ($a)",
        'atributos' => "DELETE FROM atributos WHERE id_atributo IN ($a)",
        'categorias' => 'DELETE FROM categorias WHERE id_categoria=15',
    ];
    foreach ($sentencias as $tabla => $sql) $cuentas[$tabla] = $db->exec($sql);
    if ($otrosAntes !== $db->query('SELECT * FROM productos WHERE id_categoria<>15 ORDER BY id_producto')->fetchAll()
        || $categoriasAntes !== $db->query('SELECT * FROM categorias WHERE id_categoria<>15 ORDER BY id_categoria')->fetchAll()
        || $modeloAntes !== $db->query('SELECT * FROM atributos WHERE id_atributo=47')->fetch()) {
        throw new RuntimeException('La verificación de registros ajenos falló.');
    }
    foreach (['categorias','productos','codigos_inventario','categoria_atributos','producto_codigos'] as $tabla) {
        if ((int)$db->query("SELECT COUNT(*) FROM $tabla WHERE id_categoria=15")->fetchColumn()) throw new RuntimeException("Quedan registros en $tabla");
    }
    $db->commit();
    echo json_encode(['eliminados'=>$cuentas,'productos_ajenos_conservados'=>count($otrosAntes),'respaldo'=>$respaldo], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

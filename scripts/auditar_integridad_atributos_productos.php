<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
$db = require __DIR__ . '/../config/conexion.php';
// Auditoría exclusivamente de lectura. Solo imprime conteos, no datos personales.
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    foreach (['productos', 'atributos', 'categoria_atributos', 'atributo_opciones', 'atributo_unidades', 'producto_atributo_valores', 'codigos_inventario', 'producto_codigos'] as $tabla) {
        echo 'TOTAL ', $tabla, ': ', $db->query('SELECT COUNT(*) FROM ' . $tabla)->fetchColumn(), PHP_EOL;
    }
    $consultas = [
        'Productos sin categoría o área' => 'SELECT COUNT(*) FROM productos p LEFT JOIN categorias c ON c.id_categoria=p.id_categoria LEFT JOIN areas a ON a.id_area=p.id_area WHERE c.id_categoria IS NULL OR a.id_area IS NULL',
        'Asignaciones de atributo huérfanas' => 'SELECT COUNT(*) FROM categoria_atributos ca LEFT JOIN categorias c ON c.id_categoria=ca.id_categoria LEFT JOIN atributos a ON a.id_atributo=ca.id_atributo WHERE c.id_categoria IS NULL OR a.id_atributo IS NULL',
        'Valores sin producto o asignación' => 'SELECT COUNT(*) FROM producto_atributo_valores v LEFT JOIN productos p ON p.id_producto=v.id_producto LEFT JOIN categoria_atributos ca ON ca.id_categoria_atributo=v.id_categoria_atributo WHERE p.id_producto IS NULL OR ca.id_categoria_atributo IS NULL',
        'Opciones inexistentes o ajenas' => 'SELECT COUNT(*) FROM producto_atributo_valores v JOIN categoria_atributos ca ON ca.id_categoria_atributo=v.id_categoria_atributo LEFT JOIN atributo_opciones o ON o.id_atributo_opcion=v.id_atributo_opcion WHERE v.id_atributo_opcion IS NOT NULL AND (o.id_atributo_opcion IS NULL OR o.id_atributo<>ca.id_atributo)',
        'Unidades inexistentes o ajenas' => 'SELECT COUNT(*) FROM producto_atributo_valores v JOIN categoria_atributos ca ON ca.id_categoria_atributo=v.id_categoria_atributo LEFT JOIN atributo_unidades u ON u.id_atributo_unidad=v.id_atributo_unidad WHERE v.id_atributo_unidad IS NOT NULL AND (u.id_atributo_unidad IS NULL OR u.id_atributo<>ca.id_atributo)',
        'Unidades sin cantidad' => 'SELECT COUNT(*) FROM producto_atributo_valores WHERE id_atributo_unidad IS NOT NULL AND valor_numero IS NULL',
        'Valores con cero o múltiples tipos' => 'SELECT COUNT(*) FROM producto_atributo_valores WHERE ((valor_texto IS NOT NULL)+(valor_numero IS NOT NULL)+(valor_fecha IS NOT NULL)+(valor_booleano IS NOT NULL)+(id_atributo_opcion IS NOT NULL))<>1',
        'Textos por encima del límite actual' => 'SELECT COUNT(*) FROM producto_atributo_valores v JOIN categoria_atributos ca ON ca.id_categoria_atributo=v.id_categoria_atributo JOIN atributos a ON a.id_atributo=ca.id_atributo WHERE a.tipo_dato="TEXTO" AND CHAR_LENGTH(v.valor_texto)>LEAST(255,COALESCE(a.longitud_maxima,255))',
        'Obligatorios activos sin valor en productos activos' => 'SELECT COUNT(*) FROM productos p JOIN categoria_atributos ca ON ca.id_categoria=p.id_categoria JOIN atributos a ON a.id_atributo=ca.id_atributo LEFT JOIN producto_atributo_valores v ON v.id_producto=p.id_producto AND v.id_categoria_atributo=ca.id_categoria_atributo WHERE p.estado="ACTIVO" AND ca.estado="ACTIVO" AND a.estado="ACTIVO" AND ca.requerido=1 AND v.id_producto IS NULL',
        'Productos activos sin código vigente' => 'SELECT COUNT(*) FROM productos p LEFT JOIN producto_codigos pc ON pc.producto_vigente=p.id_producto WHERE p.estado="ACTIVO" AND pc.id_producto IS NULL',
        'Productos inactivos con código vigente' => 'SELECT COUNT(*) FROM productos p JOIN producto_codigos pc ON pc.producto_vigente=p.id_producto WHERE p.estado="INACTIVO"',
        'Códigos asociados a categoría incompatible' => 'SELECT COUNT(*) FROM producto_codigos pc JOIN productos p ON p.id_producto=pc.id_producto JOIN codigos_inventario c ON c.id_codigo=pc.id_codigo WHERE pc.id_categoria<>p.id_categoria OR pc.id_categoria<>c.id_categoria',
        'Códigos vigentes duplicados' => 'SELECT COUNT(*) FROM (SELECT codigo_vigente FROM producto_codigos WHERE codigo_vigente IS NOT NULL GROUP BY codigo_vigente HAVING COUNT(*)>1) d',
        'Productos con múltiples códigos vigentes' => 'SELECT COUNT(*) FROM (SELECT producto_vigente FROM producto_codigos WHERE producto_vigente IS NOT NULL GROUP BY producto_vigente HAVING COUNT(*)>1) d',
    ];
    foreach ($consultas as $nombre => $sql) {
        echo 'INTEGRIDAD ', $nombre, ': ', $db->query($sql)->fetchColumn(),PHP_EOL;
    }
    echo 'FK relevantes:',PHP_EOL;
    $sql = "SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('productos','producto_atributo_valores','categoria_atributos','atributo_opciones','atributo_unidades','producto_codigos') AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,COLUMN_NAME";
    foreach ($db->query($sql) as $fila) {
        echo implode(' | ', array_values(array_intersect_key($fila, array_flip(['TABLE_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME'])))),PHP_EOL;
    }
} finally {
    $db->rollBack();
}

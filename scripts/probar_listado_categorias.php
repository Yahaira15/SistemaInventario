<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../admin/productos/servicio.php';
$db = require __DIR__ . '/../config/conexion.php';
// Tablas temporales: no modifica los registros del inventario.
$db->exec('CREATE TEMPORARY TABLE categorias (id_categoria INT, nombre VARCHAR(100));
CREATE TEMPORARY TABLE areas (id_area INT, nombre VARCHAR(100));
CREATE TEMPORARY TABLE productos (id_producto INT, id_categoria INT, id_area INT, descripcion VARCHAR(255), estado VARCHAR(10), fecha_creacion DATETIME);
CREATE TEMPORARY TABLE producto_codigos (id_producto_codigo INT AUTO_INCREMENT PRIMARY KEY, id_producto INT, id_codigo INT, producto_vigente INT, fecha_fin DATETIME);
CREATE TEMPORARY TABLE codigos_inventario (id_codigo INT, codigo VARCHAR(50));
INSERT INTO categorias VALUES (1,"Equipos"),(2,"Muebles");
INSERT INTO areas VALUES (1,"Oficina");');
$insertar = $db->prepare('INSERT INTO productos VALUES (?, ?, 1, ?, ?, ?)');
for ($id = 1; $id <= 13; $id++) {
    $insertar->execute([$id, $id <= 12 ? 1 : 2, 'Producto ' . $id, $id === 1 ? 'INACTIVO' : 'ACTIVO', '2026-01-01 00:00:00']);
}
function comprobarListado(bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
    echo 'OK: ', $mensaje, PHP_EOL;
}
$lista = consultarListadoProductos($db, '', 1, '', 1, 8);
comprobarListado(count($lista['productos']) === 8 && $lista['total'] === 12, 'Vista previa limitada a ocho productos de su categoría');
comprobarListado(array_column($lista['productos'], 'id_producto') === [12,11,10,9,8,7,6,5], 'Orden reciente estable');
$lista = consultarListadoProductos($db, '', 1, '', 2);
comprobarListado(count($lista['productos']) === 2 && $lista['totalPaginas'] === 2, 'Lista completa paginada');
$lista = consultarListadoProductos($db, 'Producto 1', 1, 'ACTIVO', 1, 8);
comprobarListado($lista['total'] === 3, 'Búsqueda y estado aplicados dentro de la categoría');
$lista = consultarListadoProductos($db, '', 2, '', 50, 8);
comprobarListado($lista['total'] === 1 && $lista['pagina'] === 1, 'Categorías aisladas y página fuera de rango ajustada');
$lista = consultarListadoProductos($db, 'Sin coincidencias', 2, '', 1, 8);
comprobarListado($lista['total'] === 0 && $lista['productos'] === [], 'Sin resultados');

$totales = consultarTotalesProductosPorCategoria($db, '', null, '');
comprobarListado($totales === [1 => 12, 2 => 1], 'Totales de todas las categorías en lote');
comprobarListado(
    consultarTotalesProductosPorCategoria($db, 'Producto 1', 1, 'ACTIVO') === [1 => 3],
    'Conteo agrupado conserva búsqueda, categoría y estado'
);
comprobarListado(
    consultarTotalesProductosPorCategoria($db, 'Sin coincidencias', null, '') === [],
    'Conteo sin resultados omite categorías vacías'
);
comprobarListado(
    consultarListadoProductos($db, '', 1, '', 2, 8, $totales[1])
        === consultarListadoProductos($db, '', 1, '', 2, 8),
    'Total precalculado conserva filas y paginación'
);

$db->exec('CREATE TEMPORARY TABLE atributos (id_atributo INT, nombre VARCHAR(100), estado VARCHAR(10));
CREATE TEMPORARY TABLE categoria_atributos (id_categoria_atributo INT, id_categoria INT, id_atributo INT, orden_visual INT, estado VARCHAR(10));
CREATE TEMPORARY TABLE producto_atributo_valores (id_producto INT, id_categoria_atributo INT);
INSERT INTO atributos VALUES (1,"Compartido","ACTIVO"),(2,"Histórico","INACTIVO"),(3,"Sin valores","INACTIVO"),(4,"Propio","ACTIVO");
INSERT INTO categoria_atributos VALUES (1,1,1,2,"ACTIVO"),(2,2,1,1,"ACTIVO"),(3,1,2,1,"INACTIVO"),(4,1,3,3,"INACTIVO"),(5,2,4,2,"ACTIVO");
INSERT INTO producto_atributo_valores VALUES (1,3);');
$columnas = consultarColumnasProductosPorCategoria($db, [1, 2]);
comprobarListado(
    array_column($columnas[1], 'id_atributo') === [2, 1]
        && array_column($columnas[2], 'id_atributo') === [1, 4],
    'Columnas separadas por categoría conservan orden e históricos y omiten inactivos sin valores'
);
comprobarListado(consultarColumnasProductosPorCategoria($db, []) === [], 'Columnas sin categorías');

$db->exec('INSERT INTO codigos_inventario VALUES (1,"ZZ-ANTIGUO"),(2,"AA-ANTERIOR"),(3,"VIGENTE"),(4,"ULTIMO");
INSERT INTO producto_codigos (id_producto,id_codigo,producto_vigente,fecha_fin) VALUES
(13,1,NULL,"2026-01-01 00:00:00"),
(13,2,NULL,"2026-02-01 00:00:00"),
(13,4,NULL,"2026-02-01 00:00:00"),
(13,3,13,NULL);');
$lista = consultarListadoProductos($db, '', 2, '', 1);
comprobarListado(
    $lista['productos'][0]['codigo'] === 'VIGENTE'
        && $lista['productos'][0]['codigo_anterior'] === 'ULTIMO',
    'Solo el último código cerrado, con desempate estable, separado del vigente'
);
$lista = consultarListadoProductos($db, '', 1, '', 1, 8);
comprobarListado($lista['productos'][0]['codigo_anterior'] === null, 'Producto sin códigos anteriores');

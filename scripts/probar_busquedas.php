<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
require __DIR__ . '/probar_bajas.php';
require_once __DIR__ . '/../admin/inventario/servicio.php';
require_once __DIR__ . '/../admin/inventario/bajas/servicio.php';
require_once __DIR__ . '/../auth/formularios.php';
// Los fixtures y consultas usan tablas temporales de esta conexión.
$c->exec("UPDATE productos SET descripcion='Literal 50% A_B !' WHERE id_producto=$id");
foreach (['%', '_', '!'] as $literal) {
    verificar(consultarListadoProductos($c, $literal, null, '', 1)['total'] === 1, 'Productos interpreta literalmente ' . $literal);
}
$listado = consultarListadoBajas($c, filtrosBajas([]));
verificar($listado['total'] === 1 && $listado['productos'][0]['codigo'] === 'TEST-PC001', 'Listado de bajas conserva el último código liberado');
$listado = consultarInventario($c, filtrosInventario(['area' => 999999, 'categoria' => 999999]));
verificar($listado['filtros']['area'] === 0 && $listado['filtros']['categoria'] === [] && $listado['total'] > 0, 'Filtros inexistentes se eliminan de consulta y formulario');
$_SESSION = [];
$_POST = ['accion' => 'actualizar', 'id_producto' => '1', 'descripcion' => 'Conservar texto', 'volver' => 'buscar=ADP%25&estado=ACTIVO&pagina=3&editar=999&redirect=https://example.org'];
$url = urlRetornoFormulario('productos', '/sistemaInventario/administracion/productos');
parse_str(parse_url($url, PHP_URL_QUERY), $params);
verificar($params === ['buscar' => 'ADP%', 'estado' => 'ACTIVO', 'pagina' => '3'], 'Retorno conserva solo parámetros del listado');
$_SESSION['mensajeError'] = 'Error de prueba';
$url = urlRetornoFormulario('productos', '/sistemaInventario/administracion/productos');
parse_str(parse_url($url, PHP_URL_QUERY), $_GET);
verificar($_GET['pagina'] === '3' && recuperarFormulario('productos', 1)['descripcion'] === 'Conservar texto', 'Error conserva filtros y recupera borrador');
echo "Búsquedas verificadas sin modificar datos reales.\n";

<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Ejecutar con php -d extension=zip scripts/probar_flujo_bajas.php si ZIP no está habilitado.
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('Habilita la extensión ZIP para verificar la lectura del XLSX.');
}
require __DIR__ . '/probar_bajas.php';
require_once __DIR__ . '/../admin/inventario/bajas/servicio.php';
require_once __DIR__ . '/../admin/inventario/bajas/excel.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Solo fixtures en tablas temporales privadas de esta conexión.
$c->exec("UPDATE usuarios SET nombre='Admin', apellido='Prueba' WHERE id_usuario=1");
$c->exec("UPDATE productos SET fecha_creacion='2000-01-01 00:00:00' WHERE id_producto=$reuso");
$c->exec("UPDATE producto_codigos SET fecha_inicio='2000-01-01 00:00:00' WHERE id_producto=$reuso");
$c->exec("INSERT INTO producto_asignaciones (id_producto,id_usuario,fecha_asignacion,fecha_devolucion) VALUES ($reuso,2,'2000-01-02 00:00:00','2000-01-04 00:00:00')");
$versionReuso = (int) consultarProducto($c, $reuso)['version'];
rechazar(fn () => darDeBajaProducto($c, $reuso, $versionReuso, '2000-01-03T00:00:00', 'Retroactiva', 1));
verificar(!$c->inTransaction() && consultarProducto($c, $reuso)['estado'] === 'ACTIVO', 'Rechaza baja anterior a devolución histórica sin dejar cambios');
$c->exec("DELETE FROM producto_asignaciones WHERE id_producto=$reuso");
$c->exec("UPDATE producto_codigos SET fecha_fin='2000-01-04 00:00:00' WHERE id_producto=$reuso");
rechazar(fn () => darDeBajaProducto($c, $reuso, $versionReuso, '2000-01-03T00:00:00', 'Retroactiva', 1));
verificar(!$c->inTransaction() && consultarProducto($c, $reuso)['estado'] === 'ACTIVO', 'Rechaza baja anterior al cierre de un código histórico');
$c->exec("INSERT INTO inventario_movimientos (id_producto,tipo_movimiento,fecha_movimiento,motivo,id_usuario_registro) VALUES ($reuso,'ENTRADA','2000-01-05 00:00:00','Ingreso',1)");
rechazar(fn () => darDeBajaProducto($c, $reuso, $versionReuso, '2000-01-04T00:00:00', 'Retroactiva', 1));
verificar(!$c->inTransaction() && consultarProducto($c, $reuso)['estado'] === 'ACTIVO', 'Rechaza baja anterior a una entrada registrada');
$c->exec("DELETE FROM inventario_movimientos WHERE id_producto=$reuso");
darDeBajaProducto($c, $reuso, $versionReuso, '2000-01-04T00:00:00', '=Motivo %_!', 1);
verificar((int) consultarProducto($c, $reuso)['disponible'] === 0 && (int) consultarProducto($c, $reuso)['version'] === $versionReuso + 1, 'Baja no disponible e incrementa versión una sola vez');

$normal = consultarListadoBajas($c, filtrosBajas(['pagina' => PHP_INT_MAX]));
verificar($normal['total'] === 2 && $normal['pagina'] === 1 && $normal['productos'][0]['id_producto'] === $id2, 'Listado sin duplicados, orden por fecha y página ajustada');
$filtrado = consultarListadoBajas($c, filtrosBajas(['buscar' => 'Reemplazo', 'categoria' => 1, 'area' => 1]));
verificar($filtrado['total'] === 1 && (int)$filtrado['productos'][0]['id_producto'] === $reuso, 'Filtros combinados de descripción, categoría y área');
verificar(consultarListadoBajas($c, filtrosBajas(['buscar' => 'TEST-PC001']))['total'] === 2, 'Código reutilizado conserva ambos equipos retirados');
foreach (['%', '_', '!'] as $literal) {
    verificar(consultarListadoBajas($c, filtrosBajas(['buscar' => $literal]))['total'] === 0, 'Búsqueda de bajas trata literalmente ' . $literal);
}
$malformados = filtrosBajas(['buscar' => ['x'], 'categoria' => ['x'], 'area' => -1, 'pagina' => 0]);
verificar($malformados === ['buscar' => '', 'categoria' => 0, 'area' => 0, 'pagina' => 1, 'desde' => '', 'hasta' => ''], 'Filtros malformados no rompen la consulta');
$exportado = consultarListadoBajas($c, filtrosBajas([]), true);
verificar($exportado['productos'] === $normal['productos'], 'Excel utiliza los mismos equipos y orden que el listado');
$excel = crearExcelBajas($exportado['productos']);
$hoja = $excel->getActiveSheet();
verificar($hoja->getCell('F3')->getValue() === '=Motivo %_!' && $hoja->getCell('F3')->getDataType() === 's', 'Excel conserva motivos como texto sin ejecutar fórmulas');
$archivo = tempnam(sys_get_temp_dir(), 'prueba_bajas_');
try {
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($excel))->save($archivo);
    $leido = PhpOffice\PhpSpreadsheet\IOFactory::load($archivo);
    verificar($leido->getActiveSheet()->getCell('A3')->getValue() === 'TEST-PC001', 'Archivo XLSX generado y leído conserva código histórico');
    $leido->disconnectWorksheets();
} finally {
    if (is_string($archivo) && is_file($archivo)) unlink($archivo);
    $excel->disconnectWorksheets();
}
echo "Flujo de bajas verificado sin modificar registros reales.\n";

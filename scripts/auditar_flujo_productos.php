<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/probar_guardado_codigos.php';
require_once __DIR__ . '/../auth/formularios.php';

// Todas las escrituras usan las tablas temporales de los fixtures.
$original = consultarProducto($c, $corregido);
$edicion = array_replace($base, [
    'generar_codigo' => '0', 'id_codigo' => $original['id_codigo'],
    'version' => $original['version'], 'atributos' => [1 => '8'],
]);
guardarPrueba($c, array_replace($edicion, ['descripcion' => 'Cambio de administrador A']), $corregido);
$_SESSION = [];
$_POST = array_replace($edicion, [
    'accion' => 'actualizar', 'id_producto' => $corregido,
    'descripcion' => 'Borrador anterior del administrador B',
]);
try {
    guardarProducto($c, $_POST, $corregido);
    throw new RuntimeException('No se detectó la versión obsoleta');
} catch (DomainException $e) { $_SESSION['mensajeError'] = $e->getMessage(); }
$url = urlRetornoFormulario('productos', '/sistemaInventario/administracion/productos');
parse_str(parse_url($url, PHP_URL_QUERY), $_GET);
$borrador = recuperarFormulario('productos', $corregido);
$actual = consultarProducto($c, $corregido);
// Reproduce la combinación de index.php y su campo oculto version.
$reenvio = array_replace($actual, $borrador, ['version' => $actual['version']]);
guardarProducto($c, $reenvio, $corregido);
verificar(consultarProducto($c, $corregido)['descripcion'] === $_POST['descripcion'],
    'HALLAZGO: reenvío del borrador con versión renovada sobrescribe el cambio anterior');

foreach (['producto_asignaciones', 'inventario_movimientos'] as $tabla) {
    $ddl = $c->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
    $c->exec($ddl);
}
$c->exec('CREATE TEMPORARY TABLE usuarios (id_usuario INT PRIMARY KEY, rol VARCHAR(20), estado VARCHAR(20))');
$c->exec("INSERT INTO usuarios VALUES (1,'ADMINISTRADOR','ACTIVO'),(2,'FUNCIONARIO','ACTIVO')");
$c->exec("UPDATE productos SET fecha_creacion=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id_producto=$corregido");
$c->exec("UPDATE producto_codigos SET fecha_inicio=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id_producto=$corregido");
$c->exec("INSERT INTO producto_asignaciones (id_producto,id_usuario,fecha_asignacion,fecha_devolucion)
    VALUES ($corregido,2,DATE_SUB(NOW(), INTERVAL 1 HOUR),DATE_SUB(NOW(), INTERVAL 10 MINUTE))");
$fechaBaja = (string)$c->query('SELECT DATE_SUB(NOW(), INTERVAL 30 MINUTE)')->fetchColumn();
$actual = consultarProducto($c, $corregido);
darDeBajaProducto($c, $corregido, (int)$actual['version'], $fechaBaja, 'Prueba temporal de cronología', 1);
verificar((int)$c->query('SELECT COUNT(*) FROM inventario_movimientos m JOIN producto_asignaciones pa ON pa.id_producto=m.id_producto WHERE pa.fecha_devolucion>m.fecha_movimiento')->fetchColumn() === 1,
    'HALLAZGO: acepta una baja anterior a una devolución ya registrada');
echo "Reproducciones finalizadas; no se modificaron registros reales.\n";

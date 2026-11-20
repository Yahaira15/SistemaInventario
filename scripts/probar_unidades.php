<?php

// Pruebas aisladas sobre copias temporales del esquema real de MariaDB.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
require __DIR__ . '/probar_productos_mysql.php';
require_once __DIR__ . '/../admin/atributos/unidades.php';
require_once __DIR__ . '/../auth/formularios.php';
function verificarUnidad(bool $ok, string $mensaje): void
{
    if (!$ok) {
        throw new RuntimeException($mensaje);
    }
    echo 'OK: ', $mensaje, PHP_EOL;
}
function rechazarUnidad(callable $accion, string $mensaje): void
{
    try {
        $accion();
    } catch (DomainException $e) {
        verificarUnidad(true, $mensaje); return;
    }
    throw new RuntimeException($mensaje);
}
function configurarUnidad(PDO $c, array $simbolos): void
{
    $c->beginTransaction();
    try {
        guardarUnidadesAtributo($c, 1, normalizarUnidades($simbolos)); $c->commit();
    } catch (Throwable $e) {
        $c->rollBack(); throw $e;
    }
}
configurarUnidad($c, ['GB', 'TB']);
$unidades = $c->query('SELECT simbolo, id_atributo_unidad FROM atributo_unidades WHERE id_atributo=1')->fetchAll(PDO::FETCH_KEY_PAIR);
$gb = (int) $unidades['GB']; $tb = (int) $unidades['TB'];
$c->exec('UPDATE atributos SET valor_minimo=0, valor_maximo=NULL, decimales=4 WHERE id_atributo=1');
$base = ['id_categoria' => 1, 'id_area' => 1, 'codigo' => 'DISCO-GB', 'descripcion' => 'Disco', 'atributos' => [1 => '500'], 'unidades_producto' => [1 => $gb]];
$disco = guardarMysqlPrueba($c, $base);
$fila = valoresProducto($c, $disco)[0];
verificarUnidad($fila['valor_numero'] === '500.0000' && (int)$fila['id_atributo_unidad'] === $gb, 'Guarda 500 GB sin conversión');
$base['codigo'] = 'DISCO-TB'; $base['atributos'][1] = '1.125'; $base['unidades_producto'][1] = $tb;
$otro = guardarMysqlPrueba($c, $base);
verificarUnidad(valoresProducto($c, $otro)[0]['valor_numero'] === '1.1250', 'Guarda fracciones con hasta cuatro decimales');
$base['version'] = 1;
configurarUnidad($c, ['TB']);
$editar = ['id_categoria' => 1, 'id_area' => 1, 'codigo' => 'DISCO-GB', 'descripcion' => 'Disco editado', 'version' => 1, 'atributos' => [1 => '500'], 'unidades_producto' => [1 => $gb]];
guardarMysqlPrueba($c, $editar, $disco);
verificarUnidad((int)valoresProducto($c, $disco)[0]['id_atributo_unidad'] === $gb, 'Conserva unidad inactiva al editar');
$base['unidades_producto'][1] = $gb;
rechazarUnidad(fn () => guardarMysqlPrueba($c, $base, $otro), 'Rechaza elegir otra unidad inactiva');
$c->prepare('INSERT INTO atributo_unidades (id_atributo, simbolo, estado) VALUES (2, ?, "ACTIVO")')->execute(['GB']);
$ajena = (int)$c->lastInsertId();
$base['unidades_producto'][1] = $ajena;
rechazarUnidad(fn () => guardarMysqlPrueba($c, $base, $otro), 'Rechaza unidades de otro atributo aunque compartan símbolo');
$base['unidades_producto'] = [];
rechazarUnidad(fn () => guardarMysqlPrueba($c, $base, $otro), 'Exige unidad cuando hay cantidad');
$base['unidades_producto'][1] = $tb;
foreach (['-1', '1.12345'] as $valor) {
    $base['atributos'][1] = $valor;
    rechazarUnidad(fn () => guardarMysqlPrueba($c, $base, $otro), 'Rechaza cantidad inválida ' . $valor);
}
verificarUnidad(consultarProducto($c, $otro)['version'] === 1 && valoresProducto($c, $otro)[0]['valor_numero'] === '1.1250', 'Los errores revierten el producto y sus valores');
rechazarUnidad(fn () => configurarUnidad($c, []), 'Impide quitar la última unidad');
rechazarUnidad(fn () => configurarUnidad($c, ['TB', 'tb']), 'Rechaza símbolos duplicados');
configurarUnidad($c, ['GB', 'TB']);
verificarUnidad((int)$c->query('SELECT COUNT(*) FROM atributo_unidades WHERE id_atributo=1')->fetchColumn() === 2, 'Reactivar no duplica ni cambia identificadores');
$base['atributos'][1] = ''; $base['unidades_producto'][1] = '';
guardarMysqlPrueba($c, $base, $otro);
verificarUnidad(valoresProducto($c, $otro) === [], 'Vaciar un opcional elimina cantidad y unidad juntas');
$_SESSION = ['mensajeError' => 'Prueba']; $_POST = ['accion' => 'crear', 'atributos' => [1 => '1.5'], 'unidades_producto' => [1 => (string)$tb]];
parse_str(parse_url(urlRetornoFormulario('productos', '/productos'), PHP_URL_QUERY), $_GET);
$r = recuperarFormulario('productos');
verificarUnidad($r['atributos'][1] === '1.5' && $r['unidades_producto'][1] === (string)$tb, 'Recupera cantidad y unidad tras error');
$_POST = ['accion' => 'crear', 'unidades' => ['GB', 'TB']];
parse_str(parse_url(urlRetornoFormulario('atributos', '/atributos'), PHP_URL_QUERY), $_GET);
verificarUnidad(recuperarFormulario('atributos')['unidades'] === ['GB', 'TB'], 'Recupera las unidades del atributo');
echo 'Pruebas de unidades completadas sin modificar datos reales.', PHP_EOL;


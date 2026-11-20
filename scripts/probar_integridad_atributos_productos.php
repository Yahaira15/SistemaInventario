<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
require_once __DIR__ . '/../admin/productos/servicio.php';
require_once __DIR__ . '/../admin/atributos/unidades.php';
require_once __DIR__ . '/../auth/formularios.php';
$db = require __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/soporte_auditoria_pruebas.php';
prepararAuditoriaPruebas($db);
// Copias vacías de TODAS las tablas escritas por estos escenarios. No se escribe
// en tablas reales. Las FK se auditan por separado, porque MariaDB no las admite aquí.
foreach (['productos', 'categorias', 'areas', 'codigos_inventario', 'producto_codigos', 'atributos', 'categoria_atributos', 'atributo_opciones', 'atributo_unidades', 'producto_atributo_valores'] as $tabla) {
    $ddl = $db->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
    $db->exec($ddl);
}
$db->exec(
    "INSERT INTO categorias 
        (id_categoria,nombre,descripcion,prefijo_codigo,estado) 
     VALUES (1,'Prueba','Prueba','AUD','ACTIVO'); 
     INSERT INTO areas (id_area,nombre,estado) 
     VALUES (1,'Prueba','ACTIVO')"
);
class FinProcesadorPrueba extends Exception
{
}
function volverAAtributos(): never
{
    throw new FinProcesadorPrueba();
}
function volverAOpciones(int $id): never
{
    throw new FinProcesadorPrueba();
}
function entradaPrueba(int $tipo, string $campo, int $filtro): mixed
{
    return filter_var($_POST[$campo] ?? null, $filtro);
}
function procesarAtributoPrueba(PDO $conexion, array $post): array
{
    // Ejecuta el cuerpo del procesador existente: adapta únicamente entrada CLI,
    // conexión y redirección. No simula autenticación HTTP ni prueba el navegador.
    $codigo = file_get_contents(__DIR__ . '/../admin/atributos/procesar.php');
    $inicio = strpos($codigo, '$csrfToken =');
    if ($inicio === false) {
        throw new RuntimeException('Cambió el procesador; revisar adaptador');
    }
    $codigo = substr($codigo, $inicio);
    $codigo = str_replace("\$conexion = require __DIR__ . '/../../config/conexion.php';", '', $codigo);
    $codigo = str_replace('filter_input(', 'entradaPrueba(', $codigo);
    $_SESSION = ['csrf_atributos' => 'prueba', 'administrador_id' => 1]; $_POST = $post + ['csrf_token' => 'prueba'];
    try {
        eval($codigo);
    } catch (FinProcesadorPrueba $fin) {
    }
    return $_SESSION;
}
function procesarOpcionPrueba(PDO $conexion, array $post): array
{
    $codigo = file_get_contents(__DIR__ . '/../admin/atributos/opciones/procesar.php');
    if (!function_exists('impedirDesactivarUltimaOpcionActiva')) {
        $inicio = strpos($codigo, 'function impedirDesactivarUltimaOpcionActiva(');
        $fin = strpos($codigo, "if (\$_SERVER['REQUEST_METHOD']", $inicio);
        if ($inicio === false || $fin === false) {
            throw new RuntimeException('Cambió el procesador de opciones');
        }
        eval(substr($codigo, $inicio, $fin - $inicio));
    }
    $codigo = substr($codigo, strpos($codigo, '$csrfToken ='));
    $codigo = str_replace("\$conexion = require __DIR__ . '/../../../config/conexion.php';", '', $codigo);
    $codigo = str_replace('filter_input(', 'entradaPrueba(', $codigo);
    $_SESSION = ['csrf_atributo_opciones' => 'prueba', 'administrador_id' => 1]; $_POST = $post + ['csrf_token' => 'prueba'];
    try {
        eval($codigo);
    } catch (FinProcesadorPrueba $fin) {
    }
    return $_SESSION;
}
$resultados = [];
function caso(string $nombre, callable $prueba): void
{
    global $resultados, $db;
    try {
        if (!$prueba()) {
            throw new RuntimeException('No se cumplió la condición esperada');
        } $estado = 'OK'; $detalle = '';
    } catch (Throwable $e) {
        $estado = 'FALLO'; $detalle = $e->getMessage(); if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    $resultados[] = compact('nombre', 'estado', 'detalle');
    echo $estado, ': ', $nombre, $detalle ? ' — ' . $detalle : '', PHP_EOL;
}
function nuevoAtributo(PDO $db, string $nombre, string $tipo, array $extra = []): int
{
    $r = procesarAtributoPrueba($db, ['accion' => 'crear', 'nombre' => $nombre, 'tipoDato' => $tipo] + $extra);
    if (isset($r['mensajeError'])) {
        throw new RuntimeException($r['mensajeError']);
    }
    return (int)$db->query('SELECT MAX(id_atributo) FROM atributos')->fetchColumn();
}
function asociarPrueba(PDO $db, int $atributo, bool $requerido = false): int
{
    $orden = (int)$db->query('SELECT COALESCE(MAX(orden_visual),0)+1 FROM categoria_atributos')->fetchColumn();
    $db->prepare('INSERT INTO categoria_atributos (id_categoria,id_atributo,requerido,orden_visual) VALUES (1,?,?,?)')->execute([$atributo, (int)$requerido, $orden]);
    return (int)$db->lastInsertId();
}
function productoPrueba(PDO $db, array $valores = [], array $unidades = []): int
{
    return guardarProducto($db, ['token_creacion' => bin2hex(random_bytes(32)), 'generar_codigo' => '1', 'id_categoria' => 1, 'id_area' => 1, 'descripcion' => 'Auditoría', 'atributos' => $valores, 'unidades_producto' => $unidades], null, 1);
}
function debeRechazar(callable $operacion): bool
{
    try {
        $operacion();
    } catch (DomainException $e) {
        return true;
    } return false;
}
$ids = [];
foreach (['TEXTO', 'NUMERO', 'FECHA', 'BOOLEANO', 'SELECCION'] as $tipo) {
    caso('Crear atributo ' . $tipo, function () use ($db, $tipo, &$ids) {
        $ids[$tipo] = nuevoAtributo($db, 'Prueba ' . $tipo, $tipo, ['longitud_maxima' => '20', 'unidades' => ['GB', 'TB'], 'opciones' => ['Rojo', 'Azul']]);
        return $ids[$tipo] > 0;
    });
}
caso('Rechaza selección sin opciones', fn () => isset(procesarAtributoPrueba($db, ['accion' => 'crear', 'nombre' => 'Vacía', 'tipoDato' => 'SELECCION'])['mensajeError']));
caso('Rechaza opciones duplicadas', fn () => isset(procesarAtributoPrueba($db, ['accion' => 'crear', 'nombre' => 'Duplicada', 'tipoDato' => 'SELECCION', 'opciones' => ['Rojo', 'rojo']])['mensajeError']));
caso('Rechaza unidades duplicadas', fn () => isset(procesarAtributoPrueba($db, ['accion' => 'crear', 'nombre' => 'Duplicada num', 'tipoDato' => 'NUMERO', 'unidades' => ['GB', 'gb']])['mensajeError']));
$relaciones = []; foreach ($ids as $tipo => $id) {
    $relaciones[$tipo] = asociarPrueba($db, $id);
}
caso('Impide cambiar tipo de atributo asignado', fn () => isset(procesarAtributoPrueba($db, ['accion' => 'actualizar', 'id_atributo' => $ids['TEXTO'], 'nombre' => 'Otro', 'tipoDato' => 'FECHA', 'estado' => 'ACTIVO'])['mensajeError']));
$opciones = $db->query('SELECT id_atributo_opcion FROM atributo_opciones ORDER BY id_atributo_opcion')->fetchAll(PDO::FETCH_COLUMN);
$unidades = $db->query('SELECT simbolo,id_atributo_unidad FROM atributo_unidades')->fetchAll(PDO::FETCH_KEY_PAIR);
$valores = [$relaciones['TEXTO'] => 'Texto válido', $relaciones['NUMERO'] => '1.1250', $relaciones['FECHA'] => '2026-09-28', $relaciones['BOOLEANO'] => '0', $relaciones['SELECCION'] => $opciones[0]];
$unidadesEnviadas = [$relaciones['NUMERO'] => $unidades['GB']];
$producto = productoPrueba($db, $valores, $unidadesEnviadas);
caso('Guarda los cinco tipos y sus unidades', fn () => count(valoresProducto($db, $producto)) === 5);
foreach (['FECHA' => '2026-02-30', 'BOOLEANO' => '2', 'NUMERO' => '1.12345', 'TEXTO' => str_repeat('x', 21), 'SELECCION' => '999999'] as $tipo => $valor) {
    caso('Rechaza valor inválido ' . $tipo, fn () => debeRechazar(fn () => productoPrueba($db, array_replace($valores, [$relaciones[$tipo] => $valor]), $unidadesEnviadas)));
}
caso('Fallos revierten productos y códigos', fn () => (int)$db->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM codigos_inventario')->fetchColumn() === 1);
caso('Cantidad exige unidad', fn () => debeRechazar(fn () => productoPrueba($db, $valores, [])));
caso('Conserva unidades retiradas sin borrarlas', function () use ($db, $ids, $unidades) {$db->beginTransaction();guardarUnidadesAtributo($db, $ids['NUMERO'], ['TB']);$db->commit();return $db->query('SELECT estado FROM atributo_unidades WHERE id_atributo_unidad=' . (int)$unidades['GB'])->fetchColumn() === 'INACTIVO';});
$actual = consultarProducto($db, $producto);
$editar = ['id_categoria' => 1, 'id_area' => 1, 'descripcion' => 'Edición', 'id_codigo' => $actual['id_codigo'], 'version' => $actual['version'], 'atributos' => $valores, 'unidades_producto' => $unidadesEnviadas];
caso('Editar conserva la unidad actual inactiva', function () use ($db, $editar, $producto) {guardarProducto($db, $editar, $producto, 1);return true;});
caso('Nuevo producto no adopta unidad inactiva', fn () => debeRechazar(fn () => productoPrueba($db, $valores, $unidadesEnviadas)));
caso('No permite quitar la última unidad', function () use ($db, $ids) {
    $db->beginTransaction();try {
        return debeRechazar(fn () => guardarUnidadesAtributo($db, $ids['NUMERO'], []));
    } finally {
        $db->rollBack();
    }
});
caso('Desactivar atributo conserva valores del producto', function () use ($db, $ids, $producto) {$antes = valoresProducto($db, $producto);$r = procesarAtributoPrueba($db, ['accion' => 'cambiar_estado', 'id_atributo' => $ids['TEXTO'], 'estado' => 'INACTIVO']);return !isset($r['mensajeError']) && valoresProducto($db, $producto) === $antes;});
caso('Crear opción adicional', fn () => !isset(procesarOpcionPrueba($db, ['accion' => 'crear', 'id_atributo' => $ids['SELECCION'], 'valor' => 'Verde'])['mensajeError']));
caso('Rechaza opción duplicada sin alterar las existentes', function () use ($db, $ids) {$antes = $db->query('SELECT COUNT(*) FROM atributo_opciones')->fetchColumn();$r = procesarOpcionPrueba($db, ['accion' => 'crear', 'id_atributo' => $ids['SELECCION'], 'valor' => 'Verde']);return isset($r['mensajeError']) && $antes === $db->query('SELECT COUNT(*) FROM atributo_opciones')->fetchColumn();});
caso('Editar opción mantiene su identificador', function () use ($db, $ids, $opciones) {$r = procesarOpcionPrueba($db, ['accion' => 'actualizar', 'id_atributo' => $ids['SELECCION'], 'id_atributo_opcion' => $opciones[0], 'valor' => 'Rojo actualizado', 'estado' => 'ACTIVO']);return !isset($r['mensajeError']) && $db->query('SELECT valor FROM atributo_opciones WHERE id_atributo_opcion=' . (int)$opciones[0])->fetchColumn() === 'Rojo actualizado';});
caso('Impide desactivar la última opción activa asignada', function () use ($db, $ids) {
    $todas = $db->query('SELECT id_atributo_opcion FROM atributo_opciones ORDER BY id_atributo_opcion')->fetchAll(PDO::FETCH_COLUMN);$ultima = array_pop($todas);foreach ($todas as $opcion) {
        procesarOpcionPrueba($db, ['accion' => 'cambiar_estado', 'id_atributo' => $ids['SELECCION'], 'id_atributo_opcion' => $opcion, 'estado' => 'INACTIVO']);
    }$r = procesarOpcionPrueba($db, ['accion' => 'cambiar_estado', 'id_atributo' => $ids['SELECCION'], 'id_atributo_opcion' => $ultima, 'estado' => 'INACTIVO']);return isset($r['mensajeError']) && $db->query('SELECT estado FROM atributo_opciones WHERE id_atributo_opcion=' . (int)$ultima)->fetchColumn() === 'ACTIVO';
});
// Casos frontera: se reportan, no se cambian las reglas de producción.
caso('Configuración no acepta longitud superior al almacenamiento validado', fn () => isset(procesarAtributoPrueba($db, ['accion' => 'crear', 'nombre' => 'Texto largo', 'tipoDato' => 'TEXTO', 'longitud_maxima' => '300'])['mensajeError']));
caso('Rechaza estructuras anidadas en opciones', function () use ($db) {
    set_error_handler(static fn () => true);try {
        $r = procesarAtributoPrueba($db, ['accion' => 'crear', 'nombre' => 'Opción malformada', 'tipoDato' => 'SELECCION', 'opciones' => [['valor' => 'Rojo']]]);
    } finally {
        restore_error_handler();
    }if (!isset($r['mensajeError'])) {
        throw new RuntimeException('Se guardó la opción literal: ' . $db->query('SELECT valor FROM atributo_opciones ORDER BY id_atributo_opcion DESC LIMIT 1')->fetchColumn());
    }return true;
});
caso('Reducir longitud no deja valores existentes fuera de regla', fn () => isset(procesarAtributoPrueba($db, ['accion' => 'actualizar', 'id_atributo' => $ids['TEXTO'], 'nombre' => 'Prueba TEXTO', 'tipoDato' => 'TEXTO', 'longitud_maxima' => '3', 'estado' => 'ACTIVO'])['mensajeError']));
$fallos = count(array_filter($resultados, fn ($r) => $r['estado'] === 'FALLO'));
echo 'RESUMEN: ',count($resultados) - $fallos,' correctas; ',$fallos,' fallos.',PHP_EOL;
exit($fallos ? 1 : 0);

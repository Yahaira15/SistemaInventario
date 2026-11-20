<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
require_once __DIR__ . '/../admin/productos/servicio.php';
$c = require __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/soporte_auditoria_pruebas.php';
prepararAuditoriaPruebas($c);
// Crea copias temporales vacías del esquema, locales a esta conexión.
// Todas las escrituras siguientes quedan en esas tablas, que desaparecen al salir.
foreach (['productos', 'categorias', 'areas', 'codigos_inventario', 'producto_codigos', 'atributos', 'categoria_atributos', 'atributo_opciones', 'atributo_unidades', 'producto_atributo_valores'] as $tabla) {
    $ddl = $c->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    // MariaDB no admite claves foráneas en tablas temporales.
    $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
    $c->exec($ddl);
}
$c->exec("INSERT INTO categorias (id_categoria,nombre,descripcion,prefijo_codigo) VALUES (1,'Equipos','Prueba','PRUEBA');
INSERT INTO areas (id_area,nombre) VALUES (1,'Oficina');
INSERT INTO atributos (id_atributo,nombre,tipo_dato,decimales) VALUES (1,'Capacidad','NUMERO',0),(2,'Color','SELECCION',NULL);
INSERT INTO categoria_atributos (id_categoria_atributo,id_categoria,id_atributo,orden_visual) VALUES (1,1,1,1),(2,1,2,2);
INSERT INTO atributo_opciones (id_atributo_opcion,id_atributo,valor) VALUES (1,2,'Rojo');");
function verificarMysql(bool $ok, string $mensaje): void
{
    if (!$ok) {
        throw new RuntimeException($mensaje);
    } echo 'OK: ', $mensaje, PHP_EOL;
}
function guardarMysqlPrueba(PDO $c, array $datos, ?int $id = null): int
{
    if ($id === null) {
        $datos['token_creacion'] ??= bin2hex(random_bytes(32));
        if (!isset($datos['id_codigo'])) $datos['generar_codigo'] ??= '1';
    } elseif (!isset($datos['id_codigo'])) {
        $datos['id_codigo'] = consultarProducto($c, $id)['id_codigo'];
        $datos['generar_codigo'] = '0';
    }
    return guardarProducto($c, $datos, $id, 1);
}
$datos = ['token_creacion' => bin2hex(random_bytes(32)), 'id_categoria' => 1, 'id_area' => 1, 'descripcion' => 'Portátil', 'atributos' => [1 => '5', 2 => '1']];
$id = guardarMysqlPrueba($c, $datos);
verificarMysql(consultarProducto($c, $id)['estado'] === 'ACTIVO', 'MySQL: crear producto');
foreach (['PRUEBA', 'Portátil', 'Oficina', 'Equipos'] as $buscar) {
    verificarMysql(consultarListadoProductos($c, $buscar, 1, 'ACTIVO', 1)['total'] === 1, 'MySQL: buscar ' . $buscar);
}
verificarMysql(guardarMysqlPrueba($c, $datos) === $id, 'MySQL: reenvío no duplica producto ni código');
$datos['version'] = 1;
$c->exec("UPDATE atributo_opciones SET estado='INACTIVO' WHERE id_atributo_opcion=1");
guardarMysqlPrueba($c, $datos, $id);
verificarMysql((int)consultarProducto($c, $id)['version'] === 2, 'MySQL: editar conservando opción inactiva');
try {
    guardarMysqlPrueba($c, $datos, $id); throw new RuntimeException('No detectó conflicto');
} catch (DomainException $error) {
    verificarMysql(str_contains($error->getMessage(), 'Otro administrador'), 'MySQL: rechaza versión anterior');
}
$datos['version'] = 2; $datos['atributos'][1] = '5.1';
try {
    guardarMysqlPrueba($c, $datos, $id); throw new RuntimeException('No validó entero');
} catch (DomainException $error) {
    verificarMysql(str_contains($error->getMessage(), 'decimales'), 'MySQL: rechaza decimales para entero');
}
verificarMysql((int)consultarProducto($c, $id)['version'] === 2, 'MySQL: rollback conserva versión');
try {
    guardarMysqlPrueba($c, array_replace($datos, ['estado' => 'INACTIVO']), $id);
    throw new RuntimeException('Se permitió retirar el equipo sin el flujo de bajas');
} catch (DomainException $error) {
    verificarMysql(str_contains($error->getMessage(), 'Dar de baja'), 'MySQL: exige el flujo de bajas');
}
echo "Pruebas terminadas; no se escribieron productos reales.",PHP_EOL;

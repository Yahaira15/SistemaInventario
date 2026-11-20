<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
require_once __DIR__ . '/../admin/productos/servicio.php';
$c = require __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/soporte_auditoria_pruebas.php';
prepararAuditoriaPruebas($c);
function guardarPrueba(PDO $c, array $datos, ?int $id = null): int
{
    if ($id === null && !isset($datos['token_creacion'])) {
        $datos['token_creacion'] = bin2hex(random_bytes(32));
    }
    return guardarProducto($c, $datos, $id, 1);
}
// Tablas temporales privadas: no modifica registros reales.
foreach (['productos', 'categorias', 'areas', 'codigos_inventario', 'producto_codigos', 'atributos', 'categoria_atributos', 'atributo_opciones', 'atributo_unidades', 'producto_atributo_valores'] as $tabla) {
    $ddl = $c->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
    $c->exec($ddl);
}
if ($c->query("SHOW COLUMNS FROM productos LIKE 'codigo'")->fetch()) {
    $c->exec('ALTER TABLE productos DROP COLUMN codigo');
}
$c->exec("INSERT INTO categorias (id_categoria,nombre,descripcion,prefijo_codigo,estado) VALUES (1,'Equipos','Prueba','TEST-PC','ACTIVO'),(2,'Sillas','Prueba','TEST-SE','ACTIVO'),(3,'Sin prefijo','Prueba','','ACTIVO')");
$c->exec("INSERT INTO areas (id_area,nombre,estado) VALUES (1,'Oficina','ACTIVO')");
function verificar(bool $ok, string $mensaje): void
{
    if (!$ok) {
        throw new RuntimeException($mensaje);
    } echo "OK: $mensaje\n";
}
function rechazar(callable $operacion): void
{
    try {
        $operacion();
    } catch (DomainException $e) {
        return;
    } throw new RuntimeException('Se aceptó una operación inválida');
}
$base = ['id_categoria' => 1, 'id_area' => 1, 'descripcion' => 'Equipo', 'generar_codigo' => '1'];
$operacion = bin2hex(random_bytes(32));
$id = guardarPrueba($c, $base + ['token_creacion' => $operacion]);
$repetido = guardarPrueba($c, $base + ['token_creacion' => $operacion]);
verificar($repetido === $id, 'Reenvío devuelve el mismo producto');
verificar((int)$c->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 1 && (int)$c->query('SELECT ultimo_consecutivo FROM categorias WHERE id_categoria=1')->fetchColumn() === 1, 'Reenvío no duplica producto ni avanza contador');
$p = consultarProducto($c, $id);
verificar($p['codigo'] === 'TEST-PC001', 'Genera y asigna el primer consecutivo');
$conservar = array_replace($base, ['generar_codigo' => '0', 'id_codigo' => $p['id_codigo'], 'version' => $p['version']]);
guardarPrueba($c, $conservar, $id);
verificar((int)$c->query('SELECT COUNT(*) FROM producto_codigos')->fetchColumn() === 1, 'Conservar código no duplica historial');
rechazar(fn () => guardarPrueba($c, $conservar));
verificar((int)$c->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 1, 'Rechaza código ocupado sin crear producto');
rechazar(fn () => guardarPrueba($c, $conservar, $id));
verificar(consultarProducto($c, $id)['codigo'] === 'TEST-PC001', 'Rechaza versión desactualizada');
guardarPrueba($c, $base + ['version' => 2], $id);
$p = consultarProducto($c, $id);
verificar($p['codigo'] === 'TEST-PC002', 'Cambio genera código y cierra el anterior');
$id2 = guardarPrueba($c, $conservar);
verificar(consultarProducto($c, $id2)['codigo'] === 'TEST-PC001', 'Reutiliza código liberado en otro equipo');
verificar(consultarListadoProductos($c, 'TEST-PC001', null, '', 1)['total'] === 2, 'Búsqueda incluye código vigente e histórico sin duplicar equipos');
rechazar(fn () => guardarPrueba($c, array_replace($conservar, ['id_categoria' => 2])));
rechazar(fn () => guardarPrueba($c, array_replace($base, ['id_categoria' => 2, 'version' => $p['version']]), $id));
rechazar(fn () => guardarPrueba($c, array_replace($base, ['id_categoria' => 3])));
verificar((int)$c->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 2, 'Rechaza categoría incompatible y sin prefijo');
// Una validación de atributos posterior a generar el código debe revertir todo.
$c->exec("INSERT INTO atributos (id_atributo,nombre,tipo_dato,decimales) VALUES (1,'Capacidad','NUMERO',0)");
$c->exec("INSERT INTO categoria_atributos (id_categoria_atributo,id_categoria,id_atributo,requerido,orden_visual) VALUES (1,1,1,1,1)");
$reintento = $base + ['token_creacion' => bin2hex(random_bytes(32))];
rechazar(fn () => guardarPrueba($c, $reintento));
verificar((int)$c->query('SELECT ultimo_consecutivo FROM categorias WHERE id_categoria=1')->fetchColumn() === 2, 'Fallo de atributos revierte el contador');
verificar((int)$c->query('SELECT COUNT(*) FROM codigos_inventario')->fetchColumn() === 2 && (int)$c->query('SELECT COUNT(*) FROM productos')->fetchColumn() === 2, 'Fallo revierte código y producto');
echo "Pruebas terminadas en tablas temporales.\n";
$corregido = guardarPrueba($c, $reintento + ['atributos' => [1 => '8']]);
verificar(consultarProducto($c, $corregido)['codigo'] === 'TEST-PC003', 'Corrección de errores reutiliza operación y genera una sola vez');
verificar(guardarPrueba($c, $reintento + ['atributos' => [1 => '8']]) === $corregido, 'Reintento confirmado conserva resultado');

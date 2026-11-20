<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Ejecuta los procesadores reales con entrada CLI y redirecciones adaptadas.
// Todas las tablas de escritura quedan ocultas por copias temporales privadas.
require_once __DIR__ . '/../admin/inventario/desactivacion.php';
require_once __DIR__ . '/../admin/productos/guardar_atributos.php';
$db = require __DIR__ . '/../config/conexion.php';
foreach (['categorias', 'atributos', 'categoria_atributos', 'atributo_opciones', 'atributo_unidades', 'productos', 'areas', 'producto_asignaciones', 'codigos_inventario', 'producto_atributo_valores'] as $tabla) {
    $ddl = $db->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
    $db->exec(preg_replace('/,\s*\)/', "\n)", $ddl));
}
class FinCategoriaAuditoria extends Exception {}
function volverACategorias(): never { throw new FinCategoriaAuditoria(); }
function volverACategoriaAtributo(int $id): never { throw new FinCategoriaAuditoria(); }
function entradaCategoriaAuditoria(int $tipo, string $campo, int $filtro): mixed {
    return filter_var($_POST[$campo] ?? null, $filtro);
}
function ejecutarCategoriaAuditoria(PDO $conexion, array $post, bool $relacion = false): array {
    $archivo = $relacion ? '/categoria_atributos/procesar.php' : '/procesar.php';
    $codigo = file_get_contents(__DIR__ . '/../admin/categorias' . $archivo);
    $inicio = strpos($codigo, '$csrfToken =');
    if ($inicio === false) { throw new RuntimeException('Revisar adaptador del procesador'); }
    $codigo = substr($codigo, $inicio);
    $lineaConexion = $relacion
        ? "\$conexion = require __DIR__ . '/../../../config/conexion.php';"
        : "\$conexion = require __DIR__ . '/../../config/conexion.php';";
    if (substr_count($codigo, $lineaConexion) !== 1) { throw new RuntimeException('Revisar conexión del adaptador'); }
    $codigo = str_replace($lineaConexion, '', $codigo);
    $codigo = str_replace("header('Location: /sistemaInventario/administracion/categorias');", '', $codigo);
    $codigo = str_replace('filter_input(', 'entradaCategoriaAuditoria(', $codigo);
    // El retorno directo de categoría inactiva también finaliza el procesador.
    $codigo = str_replace('exit;', 'throw new FinCategoriaAuditoria();', $codigo);
    $_POST = $post + ['csrf_token' => 'auditoria'];
    $_SESSION = [$relacion ? 'csrf_categoria_atributos' : 'csrf_categorias' => 'auditoria'];
    try { eval($codigo); } catch (FinCategoriaAuditoria $fin) {}
    return $_SESSION;
}
$correctas = 0; $fallos = 0;
function casoCategoriaAuditoria(string $nombre, callable $caso): void {
    global $correctas, $fallos, $db;
    try {
        if (!$caso()) { throw new RuntimeException('Resultado distinto del esperado'); }
        $correctas++; echo "OK: $nombre\n";
    } catch (Throwable $error) {
        $fallos++; echo "FALLO: $nombre: ", $error->getMessage(), "\n";
        if ($db->inTransaction()) { $db->rollBack(); }
    }
}
$base = ['accion' => 'crear', 'nombre' => 'Equipos prueba', 'descripcion' => 'Descripción de prueba', 'prefijo_codigo' => 'cat'];
casoCategoriaAuditoria('Crear normaliza prefijo y comienza activa', function () use ($db, $base) {
    $r = ejecutarCategoriaAuditoria($db, $base);
    $fila = $db->query('SELECT estado,prefijo_codigo FROM categorias')->fetch();
    return !isset($r['mensajeError']) && $fila['estado'] === 'ACTIVO' && $fila['prefijo_codigo'] === 'CAT';
});
$id = (int) $db->query('SELECT id_categoria FROM categorias LIMIT 1')->fetchColumn();
foreach ([['nombre' => ''], ['nombre' => str_repeat('x', 81)], ['descripcion' => ''], ['descripcion' => str_repeat('x', 121)], ['prefijo_codigo' => '1MAL'], ['prefijo_codigo' => ['x']], ['prefijo_codigo' => str_repeat('A', 21)]] as $indice => $cambio) {
    casoCategoriaAuditoria('Rechaza campos inválidos ' . ($indice + 1), fn () => isset(ejecutarCategoriaAuditoria($db, array_replace($base, $cambio))['mensajeError']));
}
casoCategoriaAuditoria('Rechaza nombre duplicado', fn () => isset(ejecutarCategoriaAuditoria($db, $base)['mensajeError']));
casoCategoriaAuditoria('Rechaza prefijo duplicado', fn () => isset(ejecutarCategoriaAuditoria($db, array_replace($base, ['nombre' => 'Otra categoría']))['mensajeError']));
$editar = $base + ['id_categoria' => $id, 'estado' => 'ACTIVO']; $editar['accion'] = 'actualizar';
casoCategoriaAuditoria('Edita descripción y permite cambiar prefijo sin códigos', fn () => !isset(ejecutarCategoriaAuditoria($db, array_replace($editar, ['descripcion' => 'Cambio', 'prefijo_codigo' => 'NUEVO']))['mensajeError']));
$db->prepare('INSERT INTO codigos_inventario (id_categoria,codigo) VALUES (?,"NUEVO0001")')->execute([$id]);
casoCategoriaAuditoria('Impide cambiar prefijo con códigos registrados', fn () => isset(ejecutarCategoriaAuditoria($db, $editar)['mensajeError']));
casoCategoriaAuditoria('Edita sin alterar prefijo utilizado', fn () => !isset(ejecutarCategoriaAuditoria($db, array_replace($editar, ['prefijo_codigo' => 'NUEVO']))['mensajeError']));
casoCategoriaAuditoria('Rechaza actualización inexistente', fn () => isset(ejecutarCategoriaAuditoria($db, array_replace($editar, ['id_categoria' => 999999]))['mensajeError']));
$cambiar = ['accion' => 'cambiar_estado', 'id_categoria' => $id, 'estado' => 'INACTIVO'];
casoCategoriaAuditoria('Desactiva categoría sin responsables', fn () => !isset(ejecutarCategoriaAuditoria($db, $cambiar)['mensajeError']));
casoCategoriaAuditoria('Impide asignar atributos a categoría inactiva', fn () => isset(ejecutarCategoriaAuditoria($db, ['accion' => 'asignar', 'id_categoria' => $id, 'id_atributo' => 1, 'requerido' => 0], true)['mensajeError']));
casoCategoriaAuditoria('Reactiva categoría', fn () => !isset(ejecutarCategoriaAuditoria($db, array_replace($cambiar, ['estado' => 'ACTIVO']))['mensajeError']));
$db->exec('INSERT INTO atributos (nombre,tipo_dato,estado) VALUES ("Texto prueba","TEXTO","ACTIVO"),("Lista vacía","SELECCION","ACTIVO"),("Inactivo","TEXTO","INACTIVO")');
$ids = $db->query('SELECT nombre,id_atributo FROM atributos')->fetchAll(PDO::FETCH_KEY_PAIR);
$asignar = ['accion' => 'asignar', 'id_categoria' => $id, 'id_atributo' => $ids['Texto prueba'], 'requerido' => 0];
casoCategoriaAuditoria('Asigna atributo activo con orden automático', fn () => !isset(ejecutarCategoriaAuditoria($db, $asignar, true)['mensajeError']) && (int) $db->query('SELECT orden_visual FROM categoria_atributos LIMIT 1')->fetchColumn() === 1);
$relacion = (int) $db->query('SELECT id_categoria_atributo FROM categoria_atributos LIMIT 1')->fetchColumn();
casoCategoriaAuditoria('Rechaza relación duplicada', fn () => isset(ejecutarCategoriaAuditoria($db, $asignar, true)['mensajeError']));
casoCategoriaAuditoria('Rechaza atributo inactivo', fn () => isset(ejecutarCategoriaAuditoria($db, array_replace($asignar, ['id_atributo' => $ids['Inactivo']]), true)['mensajeError']));
casoCategoriaAuditoria('Rechaza requerido fuera de 0/1', fn () => isset(ejecutarCategoriaAuditoria($db, array_replace($asignar, ['requerido' => 2]), true)['mensajeError']));
$db->exec('INSERT INTO areas (nombre,estado) VALUES ("Área prueba","ACTIVO")');
$area = (int) $db->query('SELECT id_area FROM areas LIMIT 1')->fetchColumn();
$db->prepare('INSERT INTO productos (id_categoria,id_area,descripcion,estado) VALUES (?,?,"Producto previo","ACTIVO")')->execute([$id, $area]);
$producto = (int) $db->lastInsertId();
$configurar = ['accion' => 'actualizar', 'id_categoria' => $id, 'id_categoria_atributo' => $relacion, 'requerido' => 1, 'estado' => 'ACTIVO'];
casoCategoriaAuditoria('Actualiza obligatoriedad de relación propia', fn () => !isset(ejecutarCategoriaAuditoria($db, $configurar, true)['mensajeError']) && (int) $db->query('SELECT requerido FROM categoria_atributos LIMIT 1')->fetchColumn() === 1);
casoCategoriaAuditoria('Desactiva y reactiva relación', function () use ($db, $id, $relacion) {
    foreach (['INACTIVO', 'ACTIVO'] as $estado) {
        $r = ejecutarCategoriaAuditoria($db, ['accion' => 'cambiar_estado', 'id_categoria' => $id, 'id_categoria_atributo' => $relacion, 'estado' => $estado], true);
        if (isset($r['mensajeError'])) { return false; }
    } return true;
});
casoCategoriaAuditoria('No modifica una relación de otra categoría', function () use ($db, $base, $configurar, $relacion) {
    ejecutarCategoriaAuditoria($db, array_replace($base, ['nombre' => 'Segunda categoría', 'prefijo_codigo' => 'OTRA']));
    $otra = (int) $db->query('SELECT MAX(id_categoria) FROM categorias')->fetchColumn();
    ejecutarCategoriaAuditoria($db, array_replace($configurar, ['id_categoria' => $otra, 'requerido' => 0, 'estado' => 'INACTIVO']), true);
    $q = $db->prepare('SELECT requerido,estado FROM categoria_atributos WHERE id_categoria_atributo=?'); $q->execute([$relacion]); $fila = $q->fetch();
    return (int) $fila['requerido'] === 1 && $fila['estado'] === 'ACTIVO';
});
casoCategoriaAuditoria('La consulta real de edición de relación es ejecutable', function () use ($db, $id, $relacion) {
    $codigo = file_get_contents(__DIR__ . '/../admin/categorias/categoria_atributos/index.php');
    if (!preg_match('/\x24consultaEditar = \x24conexion->prepare\(\s*\x27([^\x27]+)\x27/s', $codigo, $m)) { throw new RuntimeException('No se encontró la consulta'); }
    $q = $db->prepare($m[1]); $q->execute(['id_categoria_atributo' => $relacion, 'id_categoria' => $id]); return (bool) $q->fetch();
});
casoCategoriaAuditoria('Rechaza nombre de categoría enviado como arreglo', function () use ($db, $base) {
    set_error_handler(static fn () => true);
    try { $r = ejecutarCategoriaAuditoria($db, array_replace($base, ['nombre' => ['x'], 'prefijo_codigo' => 'ARRAY'])); }
    finally { restore_error_handler(); }
    return isset($r['mensajeError']);
});
casoCategoriaAuditoria('Impide asignar lista sin opciones activas', fn () => isset(ejecutarCategoriaAuditoria($db, array_replace($asignar, ['id_atributo' => $ids['Lista vacía'], 'requerido' => 1]), true)['mensajeError']));
// Evidencia del efecto de hacer obligatorio un atributo sin migrar productos.
casoCategoriaAuditoria('Confirma rechazo de producto sin nuevo obligatorio', function () use ($db, $id, $producto) {
    try { guardarAtributosProducto($db, $producto, $id, []); } catch (DomainException $error) { return true; } return false;
});
casoCategoriaAuditoria('Cambio de estado inválido no desactiva categoría', fn () => isset(ejecutarCategoriaAuditoria($db, array_replace($cambiar, ['estado' => 'BORRADO']))['mensajeError']));
$db->prepare('INSERT INTO producto_asignaciones (id_producto,id_usuario) VALUES (?,1)')->execute([$producto]);
casoCategoriaAuditoria('Desactivación con responsables revierte y conserva categoría activa', function () use ($db, $cambiar, $id) {
    $r = ejecutarCategoriaAuditoria($db, $cambiar);
    $q = $db->prepare('SELECT estado FROM categorias WHERE id_categoria=?'); $q->execute([$id]);
    return isset($r['mensajeError']) && !$db->inTransaction() && $q->fetchColumn() === 'ACTIVO';
});
casoCategoriaAuditoria('Edición tampoco permite desactivar con responsables', fn () => isset(ejecutarCategoriaAuditoria($db, array_replace($editar, ['estado' => 'INACTIVO', 'prefijo_codigo' => 'NUEVO']))['mensajeError']));
echo "RESUMEN: $correctas correctas; $fallos fallos. Tablas temporales; sin cambios en registros reales.\n";
// Conexión independiente: únicamente conteos de datos reales, sin datos personales.
$real = require __DIR__ . '/../config/conexion.php';
$real->exec('SET TRANSACTION READ ONLY');
$real->beginTransaction();
try {
    $faltantes = ' FROM productos p JOIN categoria_atributos ca ON ca.id_categoria=p.id_categoria
        JOIN atributos a ON a.id_atributo=ca.id_atributo
        LEFT JOIN producto_atributo_valores v ON v.id_producto=p.id_producto AND v.id_categoria_atributo=ca.id_categoria_atributo
        WHERE p.estado="ACTIVO" AND ca.estado="ACTIVO" AND a.estado="ACTIVO" AND ca.requerido=1 AND v.id_producto IS NULL';
    echo 'DATOS REALES: pares producto/obligatorio ausentes = ', $real->query('SELECT COUNT(*)' . $faltantes)->fetchColumn(), "\n";
    echo 'DATOS REALES: productos afectados = ', $real->query('SELECT COUNT(DISTINCT p.id_producto)' . $faltantes)->fetchColumn(), "\n";
    echo 'DATOS REALES: listas activas asignadas sin opciones = ', $real->query('SELECT COUNT(*) FROM categoria_atributos ca JOIN atributos a ON a.id_atributo=ca.id_atributo WHERE ca.estado="ACTIVO" AND a.estado="ACTIVO" AND a.tipo_dato="SELECCION" AND NOT EXISTS (SELECT 1 FROM atributo_opciones ao WHERE ao.id_atributo=a.id_atributo AND ao.estado="ACTIVO")')->fetchColumn(), "\n";
} finally { $real->rollBack(); }
exit($fallos ? 1 : 0);

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../admin/auditoria/cambios.php';
require_once __DIR__ . '/../admin/productos/servicio.php';
$db = require __DIR__ . '/../config/conexion.php';
// Solo tablas temporales, vacías y exclusivas de esta conexión.
foreach (['areas', 'categorias', 'usuarios', 'atributos', 'atributo_opciones', 'atributo_unidades', 'categoria_atributos', 'productos', 'producto_codigos', 'codigos_inventario', 'producto_atributo_valores', 'producto_asignaciones', 'inventario_movimientos', 'auditoria_eventos'] as $tabla) {
    $ddl = $db->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
    $db->exec($ddl);
}
function verificarAuditoria(bool $ok, string $mensaje): void {
    if (!$ok) { throw new RuntimeException($mensaje); }
    echo "OK: $mensaje\n";
}
$db->exec("INSERT INTO areas (id_area,nombre) VALUES (1,'Sistemas');
INSERT INTO categorias (id_categoria,nombre,descripcion,prefijo_codigo) VALUES (1,'Equipos','Prueba','TEST');
INSERT INTO usuarios (id_usuario,cedula,nombre,apellido,correo,id_area,rol,estado) VALUES (1,'100','Admin','Prueba','admin@example.test',1,'ADMINISTRADOR','ACTIVO'),(2,'200','Usuario','Prueba','usuario@example.test',1,'FUNCIONARIO','ACTIVO');
INSERT INTO atributos (id_atributo,nombre,tipo_dato) VALUES (1,'Color','SELECCION');
INSERT INTO atributo_opciones (id_atributo_opcion,id_atributo,valor) VALUES (1,1,'Rojo');
INSERT INTO categoria_atributos (id_categoria_atributo,id_categoria,id_atributo,requerido) VALUES (1,1,1,0)");
foreach (['areas' => ['id_area',1], 'categorias' => ['id_categoria',1], 'usuarios' => ['id_usuario',2], 'atributos' => ['id_atributo',1], 'atributo_opciones' => ['id_atributo_opcion',1], 'categoria_atributos' => ['id_categoria_atributo',1]] as $tabla => [$clave,$id]) {
    $db->beginTransaction();
    registrarCambioAuditoria($db, 1, $tabla, $id, null);
    $accionInicial = $tabla === 'categoria_atributos' ? 'ASIGNACION' : 'CREACION';
    verificarAuditoria($db->query('SELECT accion FROM auditoria_eventos ORDER BY id_evento DESC LIMIT 1')->fetchColumn() === $accionInicial, "$tabla: $accionInicial");
    $antes = consultarRegistroAuditoria($db, $tabla, $id);
    $cantidad = (int) $db->query('SELECT COUNT(*) FROM auditoria_eventos')->fetchColumn();
    registrarCambioAuditoria($db, 1, $tabla, $id, $antes);
    verificarAuditoria((int) $db->query('SELECT COUNT(*) FROM auditoria_eventos')->fetchColumn() === $cantidad, "$tabla: sin eventos al guardar sin cambios");
    foreach (['INACTIVO' => 'DESACTIVACION', 'ACTIVO' => 'REACTIVACION'] as $estado => $accion) {
        $antes = consultarRegistroAuditoria($db, $tabla, $id);
        $db->exec("UPDATE $tabla SET estado = '$estado' WHERE $clave = $id");
        registrarCambioAuditoria($db, 1, $tabla, $id, $antes);
        verificarAuditoria($db->query('SELECT accion FROM auditoria_eventos ORDER BY id_evento DESC LIMIT 1')->fetchColumn() === $accion, "$tabla: $accion");
    }
    $antes = consultarRegistroAuditoria($db, $tabla, $id);
    $campo = match ($tabla) { 'atributo_opciones' => 'valor', 'categoria_atributos' => 'orden_visual', default => 'nombre' };
    $db->exec("UPDATE $tabla SET $campo = " . ($campo === 'orden_visual' ? '2' : "'Editado'") . " WHERE $clave = $id");
    registrarCambioAuditoria($db, 1, $tabla, $id, $antes);
    verificarAuditoria($db->query('SELECT accion FROM auditoria_eventos ORDER BY id_evento DESC LIMIT 1')->fetchColumn() === 'EDICION', "$tabla: edición");
    $db->rollBack();
}
$entrada = ['token_creacion' => bin2hex(random_bytes(32)), 'generar_codigo' => '1', 'id_categoria' => 1, 'id_area' => 1, 'descripcion' => 'Equipo <prueba>', 'atributos' => [1 => 1]];
$id = guardarProducto($db, $entrada, null, 1);
$evento = $db->query('SELECT * FROM auditoria_eventos ORDER BY id_evento DESC LIMIT 1')->fetch();
$datos = json_decode($evento['datos_nuevos'], true, 512, JSON_THROW_ON_ERROR);
verificarAuditoria($evento['accion'] === 'CREACION' && !empty($datos['codigo']) && $datos['Atributo: Color (#1)'] === 'Rojo', 'Producto: creación con código y atributos');
verificarAuditoria(!isset($datos['token_creacion']), 'El evento no contiene tokens de creación');
$cantidad = (int) $db->query('SELECT COUNT(*) FROM auditoria_eventos')->fetchColumn();
verificarAuditoria(guardarProducto($db, $entrada, null, 1) === $id && (int) $db->query('SELECT COUNT(*) FROM auditoria_eventos')->fetchColumn() === $cantidad, 'Reintento de creación no duplica eventos');
$producto = consultarProducto($db, $id);
$entrada['generar_codigo'] = '0'; $entrada['id_codigo'] = $producto['id_codigo']; $entrada['version'] = $producto['version']; $entrada['descripcion'] = 'Equipo editado';
guardarProducto($db, $entrada, $id, 1);
verificarAuditoria($db->query('SELECT accion FROM auditoria_eventos ORDER BY id_evento DESC LIMIT 1')->fetchColumn() === 'EDICION', 'Producto: edición');
$producto = consultarProducto($db, $id);
$entrada['version'] = $producto['version']; $entrada['descripcion'] = 'No debe guardarse';
$cantidad = (int) $db->query('SELECT COUNT(*) FROM auditoria_eventos')->fetchColumn();
try { guardarProducto($db, $entrada, $id, 99999); throw new RuntimeException('Se aceptó actor inválido'); } catch (DomainException) {}
verificarAuditoria(consultarProducto($db, $id)['descripcion'] === 'Equipo editado' && (int) $db->query('SELECT COUNT(*) FROM auditoria_eventos')->fetchColumn() === $cantidad, 'Un fallo de auditoría revierte el cambio y no crea eventos');
darDeBajaProducto($db, $id, (int) $producto['version'], null, 'Motivo de prueba', 1);
$evento = $db->query('SELECT * FROM auditoria_eventos ORDER BY id_evento DESC LIMIT 1')->fetch();
$datos = json_decode($evento['datos_nuevos'], true, 512, JSON_THROW_ON_ERROR);
verificarAuditoria($evento['accion'] === 'DESACTIVACION' && $datos['motivo_baja'] === 'Motivo de prueba', 'Baja: estado, motivo y fecha auditados');
require_once __DIR__ . '/../admin/auditoria/presentacion.php';
$html = presentarDatosAuditoria(null, json_encode(['nombre' => '<script>Prueba</script>', 'estado' => 'ACTIVO', 'Unidad #1' => 'GB (ACTIVO)']));
verificarAuditoria(str_contains($html, '&lt;script&gt;') && str_contains($html, 'Unidad #1') && str_contains($html, 'Estado'), 'Modal: campos de otros módulos y escape HTML');
echo "Pruebas terminadas sin modificar registros reales.\n";

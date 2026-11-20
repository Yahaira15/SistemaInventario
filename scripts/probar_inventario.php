<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
require_once __DIR__ . '/../admin/inventario/servicio.php';
$db = require __DIR__ . '/../config/conexion.php';
// Administrador y eventos de auditoría aislados en tablas temporales.
$idAdministradorPrueba = 1;
function comprobarInventario(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
    echo "OK: $mensaje\n";
}
function rechazarInventario(callable $operacion): void
{
    try {
        $operacion();
    } catch (DomainException $error) {
        return;
    }
    throw new RuntimeException('Se aceptó una operación inválida.');
}
// Las tablas temporales ocultan las reales únicamente durante esta conexión.
$ddl = $db->query('SHOW CREATE TABLE producto_asignaciones')->fetch(PDO::FETCH_NUM)[1];
$ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
$ddl = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddl);
$ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
$db->exec($ddl);
$db->exec('CREATE TEMPORARY TABLE productos (id_producto INT PRIMARY KEY, descripcion VARCHAR(100), id_categoria INT, id_area INT, estado VARCHAR(20), disponible TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE usuarios (id_usuario INT PRIMARY KEY, nombre VARCHAR(80), apellido VARCHAR(80), estado VARCHAR(20), cedula VARCHAR(20)) ENGINE=InnoDB');
$ddlAuditoria = $db->query('SHOW CREATE TABLE auditoria_eventos')->fetch(PDO::FETCH_NUM)[1];
$ddlAuditoria = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddlAuditoria);
$ddlAuditoria = preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\R/m', '', $ddlAuditoria);
$ddlAuditoria = preg_replace('/,\s*\)/', "\n)", $ddlAuditoria);
$db->exec($ddlAuditoria);
$db->exec('CREATE TEMPORARY TABLE categorias (id_categoria INT PRIMARY KEY, nombre VARCHAR(80), estado VARCHAR(20) DEFAULT "ACTIVO")');
$db->exec('CREATE TEMPORARY TABLE areas (id_area INT PRIMARY KEY, nombre VARCHAR(80), estado VARCHAR(20) DEFAULT "ACTIVO")');
$db->exec('CREATE TEMPORARY TABLE producto_codigos (producto_vigente INT, id_codigo INT, id_producto INT, fecha_fin DATETIME NULL)');
$db->exec('CREATE TEMPORARY TABLE codigos_inventario (id_codigo INT, codigo VARCHAR(80))');
$db->exec("INSERT INTO categorias (id_categoria,nombre) VALUES (1,'Computadores'); INSERT INTO areas (id_area,nombre) VALUES (1,'Sistemas');
    INSERT INTO productos (id_producto,descripcion,id_categoria,id_area,estado) VALUES (1,'Portátil',1,1,'ACTIVO'),(2,'Monitor',1,1,'ACTIVO'),(3,'Retirado',1,1,'INACTIVO');
    INSERT INTO usuarios VALUES (1,'Ana','Uno','ACTIVO','123456'),(2,'Luis','Dos','ACTIVO','654321'),(3,'Usuario','Inactivo','INACTIVO','999999');
    INSERT INTO producto_codigos VALUES (1,1,1,NULL); INSERT INTO codigos_inventario VALUES (1,'PC001')");
$db->exec('ALTER TABLE usuarios ADD rol VARCHAR(20) NOT NULL DEFAULT "FUNCIONARIO"');
$db->exec('UPDATE usuarios SET rol="ADMINISTRADOR" WHERE id_usuario=1');
comprobarInventario((int)$db->query('SELECT disponible FROM productos WHERE id_producto=1')->fetchColumn() === 1, 'Producto nuevo disponible');
foreach (['Ana', 'Uno', '1234'] as $texto) {
    comprobarInventario(count(buscarResponsablesInventario($db, $texto, 1)) === 1, 'Búsqueda por nombre, apellido o cédula: ' . $texto);
}
comprobarInventario(buscarResponsablesInventario($db, 'Usuario', 1) === [] && buscarResponsablesInventario($db, 'Ana', 3) === [], 'Buscador excluye usuarios inactivos y equipos dados de baja');
comprobarInventario(buscarResponsablesInventario($db, '%_', 1) === [] && buscarResponsablesInventario($db, 'A', 1) === [], 'Búsqueda literal y mínimo de caracteres');
modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba);
comprobarInventario(buscarResponsablesInventario($db, 'Ana', 1) === [], 'Buscador excluye responsables actuales');
comprobarInventario((int)$db->query('SELECT disponible FROM productos WHERE id_producto=1')->fetchColumn() === 0, 'Asignación marca equipo no disponible');
modificarAsignacionInventario($db, 'asignar', 1, 2, $idAdministradorPrueba);
rechazarInventario(fn () => modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba));
rechazarInventario(fn () => modificarAsignacionInventario($db, 'asignar', 1, 3, $idAdministradorPrueba));
rechazarInventario(fn () => modificarAsignacionInventario($db, 'asignar', 3, 1, $idAdministradorPrueba));
rechazarInventario(fn () => modificarAsignacionInventario($db, 'asignar', 999, 1, $idAdministradorPrueba));
$listado = consultarInventario($db, filtrosInventario(['usuario' => 'Ana', 'asignacion' => 'CON']));
comprobarInventario($listado['total'] === 1 && count($listado['responsables'][1]) === 2, 'Usuario, filtro y múltiples responsables; duplicados e inactivos rechazados');
$asignacion = (int)$listado['responsables'][1][0]['id_producto_asignacion'];
foreach (['Ana', 'Uno', 'Ana Uno', '1234', 'Luis'] as $usuario) {
    $resultado = consultarInventario($db, filtrosInventario(['usuario' => $usuario]));
    comprobarInventario(
        $resultado['total'] === 1 && (int)$resultado['productos'][0]['id_producto'] === 1,
        'Filtro por responsable actual: ' . $usuario
    );
}
comprobarInventario(
    consultarInventario($db, filtrosInventario(['usuario' => 'o']))['total'] === 1,
    'Varios responsables coincidentes no duplican el equipo'
);
comprobarInventario(
    consultarInventario($db, filtrosInventario(['usuario' => 'Ana', 'asignacion' => 'SIN']))['total'] === 0,
    'Filtro de usuario se combina con estado de asignación'
);
comprobarInventario(
    !array_key_exists('buscar', filtrosInventario(['buscar' => 'Monitor'])),
    'El filtro retirado por código o descripción se ignora'
);
foreach (['%_', "' OR 1=1 --", 'Desconocido'] as $usuario) {
    comprobarInventario(
        consultarInventario($db, filtrosInventario(['usuario' => $usuario]))['total'] === 0,
        'Filtro de usuario trata el texto literalmente: ' . $usuario
    );
}
comprobarInventario(filtrosInventario(['usuario' => []])['usuario'] === '', 'Filtro de usuario malformado normalizado');
rechazarInventario(fn () => modificarAsignacionInventario($db, 'devolver', 2, $asignacion, $idAdministradorPrueba));
modificarAsignacionInventario($db, 'devolver', 1, $asignacion, $idAdministradorPrueba);
comprobarInventario(
    consultarInventario($db, filtrosInventario(['usuario' => 'Ana']))['total'] === 0,
    'Filtro excluye asignaciones devueltas aunque haya otros responsables'
);
comprobarInventario((int)$db->query('SELECT disponible FROM productos WHERE id_producto=1')->fetchColumn() === 0, 'Devolución parcial mantiene equipo no disponible');
rechazarInventario(fn () => modificarAsignacionInventario($db, 'devolver', 1, $asignacion, $idAdministradorPrueba));
modificarAsignacionInventario($db, 'asignar', 1, 1, $idAdministradorPrueba);
comprobarInventario((int)$db->query('SELECT COUNT(*) FROM producto_asignaciones WHERE fecha_devolucion IS NULL')->fetchColumn() === 2, 'Devolución individual, rechazo de devolución repetida y reasignación');
$listado = consultarInventario($db, filtrosInventario(['asignacion' => 'SIN', 'estado' => 'ACTIVO', 'area' => 1, 'categoria' => 1, 'pagina' => 999]));
comprobarInventario($listado['total'] === 1 && (int)$listado['productos'][0]['id_producto'] === 2 && $listado['pagina'] === 1, 'Filtros combinados y página fuera de rango');
$listado = consultarInventario($db, filtrosInventario(['usuario' => "' OR 1=1 --"]));
comprobarInventario($listado['total'] === 0, 'Búsqueda parametrizada sin resultados');
comprobarInventario(filtrosInventario(['usuario' => [], 'estado' => [], 'pagina' => []])['pagina'] === 1, 'Parámetros malformados normalizados');
$vigentes = $db->query('SELECT id_producto_asignacion FROM producto_asignaciones WHERE id_producto=1 AND fecha_devolucion IS NULL')->fetchAll(PDO::FETCH_COLUMN);
foreach ($vigentes as $vigente) {
    modificarAsignacionInventario($db, 'devolver', 1, (int)$vigente, $idAdministradorPrueba);
}
comprobarInventario((int)$db->query('SELECT disponible FROM productos WHERE id_producto=1')->fetchColumn() === 1, 'Última devolución libera el equipo');
comprobarInventario(count(buscarResponsablesInventario($db, 'Ana', 1)) === 1, 'Usuario devuelto vuelve a aparecer en la búsqueda');
echo "Pruebas completadas sin modificar registros reales.\n";

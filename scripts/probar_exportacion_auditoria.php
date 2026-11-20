<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Ejecutar con php -d extension=zip si ZIP no está habilitado en la CLI.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../admin/auditoria/excel.php';
$conexion = require __DIR__ . '/../config/conexion.php';
$conexion->exec('CREATE TEMPORARY TABLE auditoria_eventos (id_evento INT PRIMARY KEY, fecha DATETIME, administrador_nombre VARCHAR(161), modulo VARCHAR(40), accion VARCHAR(40), entidad VARCHAR(60), id_registro INT, datos_anteriores LONGTEXT, datos_nuevos LONGTEXT)');
$conexion->exec('CREATE TEMPORARY TABLE producto_asignaciones (id_producto_asignacion INT, id_producto INT)');
$conexion->exec('CREATE TEMPORARY TABLE producto_codigos (producto_vigente INT, id_codigo INT)');
$conexion->exec('CREATE TEMPORARY TABLE codigos_inventario (id_codigo INT, codigo VARCHAR(80))');
$insertar = $conexion->prepare('INSERT INTO auditoria_eventos VALUES (?,?,?,?,?,?,?,?,?)');
for ($id = 1; $id <= 31; ++$id) {
    $insertar->execute([$id,'2026-10-19 08:00:00','=1+1','PRODUCTOS','CREACION','productos',$id,null,json_encode(['codigo'=>'00123','equipo'=>'Portátil & monitor','area'=>'Producción','Atributo: Marca (#9)'=>'DELL'])]);
}
$insertar->execute([32,'2026-10-19 09:00:00','Otro','USUARIOS','CREACION','usuarios',1,null,'{"nombre":"Usuario"}']);
$_GET = ['modulo'=>'PRODUCTOS','pagina'=>2];
$exportarAuditoria = true;
require __DIR__ . '/../admin/auditoria/filtros.php';
if (isset($eventos) || $erroresFiltros) { throw new RuntimeException('La exportación ejecutó paginación o rechazó filtros válidos.'); }
$q = $conexion->prepare('SELECT ae.*, ci.codigo AS codigo_actual ' . $desdeSql . $dondeSql . ' ORDER BY ae.id_evento DESC');
$q->execute($parametros);
$excel = crearExcelAuditoria($q, $modulosAuditoria, $accionesAuditoria);
if ($excel->getSheet(0)->getHighestRow() !== 32 || $excel->getSheetCount() !== 2) { throw new RuntimeException('Filas omitidas o filtros ignorados.'); }
if ($excel->getSheet(0)->getCell('C2')->getDataType() !== 's') { throw new RuntimeException('Fórmula no protegida.'); }
$temporal = tempnam(sys_get_temp_dir(), 'prueba_auditoria_');
try {
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($excel))->save($temporal);
    $lectura = PhpOffice\PhpSpreadsheet\IOFactory::load($temporal);
    if ($lectura->getSheet(0)->getCell('F2')->getValue() !== 'Producto · Código: 00123 · Portátil & monitor') { throw new RuntimeException('Error en caracteres especiales.'); }
    $hoja = $lectura->getSheet(0);
    if ($lectura->getSheetNames() !== ['Eventos', 'Cambios'] || $hoja->getCell('A2')->getValue() !== '31' || $hoja->getCell('A32')->getValue() !== '1') { throw new RuntimeException('Hojas o IDs incorrectos.'); }
    $cambios = $lectura->getSheetByName('Cambios');
    if ($cambios->getHighestRow() !== 125 || !in_array(['31', 'Código: 00123', 'Producto · Código: 00123 · Portátil & monitor', '2026-10-19 08:00:00', '=1+1', 'Creación', 'Atributo: Marca', 'No aplica', 'DELL'], $cambios->toArray(), true)) { throw new RuntimeException('Cambios incompletos o ilegibles.'); }
    if ($cambios->getCell('E2')->getDataType() !== 's') { throw new RuntimeException('Fórmula no protegida en Cambios.'); }
    if ($lectura->getSheet(0)->getCell('C2')->getDataType() !== 's') { throw new RuntimeException('Tipo textual perdido.'); }
    $lectura->disconnectWorksheets();
} finally { unlink($temporal); $excel->disconnectWorksheets(); }
$vacio = crearExcelAuditoria([], $modulosAuditoria, $accionesAuditoria);
if ($vacio->getSheet(0)->getHighestRow() !== 1 || $vacio->getSheet(1)->getHighestRow() !== 1) { throw new RuntimeException('Exportación vacía incorrecta.'); }
$vacio->disconnectWorksheets();
echo "OK: Excel real de dos hojas, 31 eventos filtrados sin paginación, cambios legibles, acentos, texto seguro y resultado vacío.\n";

// Todas las clases de registro deben poder identificarse sin conocer los IDs internos.
$casosIdentificacion = [
    ['usuarios', ['nombre'=>'Ana','apellido'=>'Pérez','cedula'=>'001234'], 'Ana Pérez · Cédula: 001234'],
    ['areas', ['nombre'=>'Producción'], 'Área: Producción'],
    ['categorias', ['nombre'=>'Computadores'], 'Categoría: Computadores'],
    ['atributos', ['nombre'=>'Memoria RAM'], 'Atributo: Memoria RAM'],
    ['atributo_opciones', ['atributo'=>'Color','valor'=>'Azul'], 'Atributo: Color · Opción: Azul'],
    ['categoria_atributos', ['categoria'=>'Computadores','atributo'=>'Marca'], 'Categoría: Computadores · Atributo: Marca'],
    ['producto_asignaciones', ['codigo'=>'PC001','equipo'=>'Portátil','responsable'=>'Ana'], 'Código: PC001 · Portátil · Responsable: Ana'],
];
foreach ($casosIdentificacion as [$entidad, $datos, $esperado]) {
    $eventoPrueba = ['entidad'=>$entidad,'id_registro'=>1,'datos_anteriores'=>null,'datos_nuevos'=>json_encode($datos)];
    if (identificarRegistroExcelAuditoria($eventoPrueba) !== $esperado) { throw new RuntimeException('Identificación incorrecta: ' . $entidad); }
}
$bajaPrueba = ['entidad'=>'productos','id_registro'=>1,'datos_anteriores'=>'{"codigo":"PC001","equipo":"Portátil"}','datos_nuevos'=>'{"codigo":null,"equipo":"Portátil"}'];
if (identificarRegistroExcelAuditoria($bajaPrueba) !== 'Código anterior: PC001 · Portátil') { throw new RuntimeException('Código anterior perdido en baja'); }
echo "OK: identificación por módulo y código histórico de equipos dados de baja.\n";
$encabezadosEventos = ['ID del evento', 'Fecha', 'Admin. ejecutor', 'Módulo afectado', 'Acción realizada', 'Ítem afectado'];
$estructuraEventos = crearExcelAuditoria([], $modulosAuditoria, $accionesAuditoria);
if ($estructuraEventos->getSheetCount() !== 2 || $estructuraEventos->getSheet(0)->getHighestColumn() !== 'F' || $estructuraEventos->getSheet(0)->rangeToArray('A1:F1')[0] !== $encabezadosEventos) {
    throw new RuntimeException('La hoja Eventos no tiene las seis columnas solicitadas.');
}
$estructuraEventos->disconnectWorksheets();
echo "OK: Eventos con ID y detalle separado en Cambios.\n";
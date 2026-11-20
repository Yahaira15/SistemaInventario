<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
iniciarSesionSegura();
exigirAdministrador();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Método no permitido.');
}

$excel = null;
$archivoTemporal = null;
try {
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/excel.php';
    $conexion = require __DIR__ . '/../../config/conexion.php';
    $exportarAuditoria = true;
    require __DIR__ . '/filtros.php';
    if ($erroresFiltros) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo implode(' ', array_unique($erroresFiltros));
        exit;
    }
    $consulta = $conexion->prepare('SELECT ae.*, ci.codigo AS codigo_actual ' . $desdeSql . $dondeSql . ' ORDER BY ae.id_evento DESC');
    $consulta->execute($parametros);
    $eventosExportacion = (static function () use ($consulta): Generator {
        while ($evento = $consulta->fetch(PDO::FETCH_ASSOC)) { yield $evento; }
    })();
    $excel = crearExcelAuditoria($eventosExportacion, $modulosAuditoria, $accionesAuditoria);
    $archivoTemporal = tempnam(sys_get_temp_dir(), 'auditoria_excel_');
    if ($archivoTemporal === false) { throw new RuntimeException('No se pudo crear el archivo temporal.'); }
    (new Xlsx($excel))->save($archivoTemporal);
    $fecha = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="auditoria_' . $fecha->format('Y-m-d_H-i') . '.xlsx"');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    if (readfile($archivoTemporal) === false) { throw new RuntimeException('No se pudo enviar el archivo Excel.'); }
} catch (Throwable $error) {
    error_log('Error al exportar auditoría: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No fue posible generar el Excel de auditoría.';
    }
} finally {
    if (is_string($archivoTemporal) && is_file($archivoTemporal)) { unlink($archivoTemporal); }
    if ($excel instanceof Spreadsheet) { $excel->disconnectWorksheets(); }
}
exit;

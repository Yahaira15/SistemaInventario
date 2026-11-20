<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/../../../auth/sesion.php';
require_once __DIR__ . '/../../../auth/proteger.php';
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
    require_once __DIR__ . '/../../../vendor/autoload.php';
    require_once __DIR__ . '/servicio.php';
    require_once __DIR__ . '/excel.php';
    $conexion = require __DIR__ . '/../../../config/conexion.php';
    $listado = consultarListadoBajas($conexion, filtrosBajas($_GET), true);
    $excel = crearExcelBajas($listado['productos']);
    $archivoTemporal = tempnam(sys_get_temp_dir(), 'bajas_excel_');
    if ($archivoTemporal === false) {
        throw new RuntimeException('No se pudo crear el archivo temporal.');
    }
    (new Xlsx($excel))->save($archivoTemporal);
    $fecha = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
    $nombre = 'bajas_' . $fecha->format('Y-m-d_H-i') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    if (readfile($archivoTemporal) === false) {
        throw new RuntimeException('No se pudo enviar el archivo Excel.');
    }
} catch (DomainException $error) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error->getMessage();
} catch (Throwable $error) {
    error_log('Error al exportar bajas: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No fue posible generar el Excel de bajas.';
    }
} finally {
    if (is_string($archivoTemporal) && is_file($archivoTemporal)) {
        unlink($archivoTemporal);
    }
    if ($excel instanceof Spreadsheet) {
        $excel->disconnectWorksheets();
    }
}
exit;

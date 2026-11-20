<?php 
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

// LIBERA LA SESION MIENTRAS SE PREPARA LA DESCARGA 
session_write_close();

$autoload =__DIR__. '/../../vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit('No se encontraron las dependencias del exportador');
}

require_once $autoload;
require_once __DIR__ . '/servicio.php';
require_once __DIR__ . '/../productos/atributos_listado.php';

$excel = null;
$archivoTemporal = null;


try {
    $conexion = require __DIR__ . '/../../config/conexion.php';

    $filtros = filtrosInventario($_GET);

    $listado = consultarInventario($conexion, $filtros, true);

    $atributosProductos = consultarAtributosListadoProductos(
        $conexion,
        array_column($listado['productos'], 'id_producto')
    );
    $columnasAtributos = [];
    foreach ($atributosProductos as $atributos) {
        foreach ($atributos as $idAtributo => $atributo) {
            $columnasAtributos[$idAtributo] = $atributo['nombre'];
        }
    }
    asort($columnasAtributos, SORT_NATURAL | SORT_FLAG_CASE);

    $excel = new Spreadsheet();
    $hoja = $excel->getActiveSheet();
    $hoja->setTitle('Inventario');

    $encabezados = [
        'A' => 'Código vigente',
        'B' => 'Descripción',
        'C' => 'Categoría',
        'D' => 'Área',
        'E' => 'Estado',
        'F' => 'Responsables Actuales',
        'G' => 'Fechas de asignación',
        'H' => 'Códigos anteriores',
    ];

    $mapaColumnasAtributos = [];
    foreach ($columnasAtributos as $idAtributo => $nombre) {
        $columna = Coordinate::stringFromColumnIndex(count($encabezados) + 1);
        $encabezados[$columna] = $nombre;
        $mapaColumnasAtributos[$idAtributo] = $columna;
    }
    $ultimaColumna = Coordinate::stringFromColumnIndex(count($encabezados));

    foreach ($encabezados as $columna => $titulo) {
        $hoja->setCellValueExplicit(
            $columna . '1',
            $titulo,
            DataType::TYPE_STRING
        );
    }

    $fila = 2;

    foreach ($listado['productos'] as $producto) {
        $id = (int) $producto['id_producto'];
        $responsables = $listado['responsables'][$id] ?? [];

        $nombres = [];
        $fechas = [];

        if ($producto['estado'] === 'INACTIVO') {
            $nombres[] = 'No disponible';
        } elseif (!$responsables) {
            $nombres[] = 'Sin responsables';
        } else {
            foreach ($responsables as $responsable) {
                $nombres[] = (string) $responsable['nombre'];

                $fechas[] = (
                    new DateTimeImmutable($responsable['fecha_asignacion'])
                )->format('d/m/Y H:i');
            }
        }

        $valores = [
            'A' => $producto['codigo'] ?? 'Sin código vigente',
            'B' => $producto['descripcion'],
            'C' => $producto['categoria'],
            'D' => $producto['area'],
            'E' => $producto['estado'] === 'ACTIVO'
                ? 'Activo'
                : 'Dado de baja',
            'F' => implode("\n", $nombres),
            'G' => $fechas ? implode("\n", $fechas) : '—',
            'H' => $producto['codigos_anteriores'] ?? ''
            
        ];

        foreach ($mapaColumnasAtributos as $idAtributo => $columna) {
            $valores[$columna] = $atributosProductos[$id][$idAtributo]['valor'] ?? '';
        }

        foreach ($valores as $columna => $valor) {
            $hoja->setCellValueExplicit(
                $columna . $fila,
                (string) $valor,
                DataType::TYPE_STRING
            );
        }

        $fila++;
    }

    $ultimaFila = max(1, $fila -1);

    $hoja->getStyle('A1:' . $ultimaColumna . '1')->getFont()->setBold(true)->getColor()->setARGB('f4f5f7');

    $hoja->getStyle('A1:' . $ultimaColumna . '1')->getFill()->setFillType(
        \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID
    )
    ->getStartColor()->setARGB('a60000');

    $hoja->getStyle('A1:' . $ultimaColumna . $ultimaFila)
        ->getAlignment()
        ->setVertical(Alignment::VERTICAL_TOP)
        ->setWrapText(true);

    $anchos = [
        'A' => 18,
        'B' => 30,
        'C' => 20,
        'D' => 20,
        'E' => 15,
        'F' => 20,
        'G' => 22,
        'H' => 20
    ];

    foreach ($mapaColumnasAtributos as $columna) {
        $anchos[$columna] = 25;
    }

    foreach ($anchos as $columna => $ancho) {
        $hoja->getColumnDimension($columna)->setWidth($ancho);
    }

    $hoja->freezePane('A2');
    $hoja->setAutoFilter('A1:' . $ultimaColumna . $ultimaFila);

    // Genera el archivo antes de mandar los encabezados de descarga 
    $archivoTemporal = tempnam(
        sys_get_temp_dir(),
        'inventario_excel_'
    );

    if ($archivoTemporal === false) {
        throw new RuntimeException('No se pudo crear el archivo temporal.');
    }

    $escritor = new Xlsx($excel);
    $escritor->save($archivoTemporal);

    $fecha = new DateTimeImmutable(
        'now',
        new DateTimeZone('America/Bogota')
    );
    $nombreArchivo = 'inventario_'
        . $fecha->format('Y-m-d_H-i')
        . '.xlsx';

    header(
        'Content-Type: '
        . 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );
    header(
        'Content-Disposition: attachment; filename="'
        . $nombreArchivo
        . '"'
    );
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    if (readfile($archivoTemporal) === false) {
        throw new RuntimeException('No se pudo enviar el archivo Excel');
    }

} catch (Throwable $error) {
    error_log('Error al exportar inventario: ' . $error->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'No fue posible generar el Excel del inventario.';
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
<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

function crearExcelBajas(array $productos): Spreadsheet
{
    $excel = new Spreadsheet();
    $hoja = $excel->getActiveSheet();
    $hoja->setTitle('Bajas');
    $titulos = ['Último código', 'Fecha de baja', 'Descripción', 'Categoría', 'Área', 'Motivo', 'Registrada por', 'Último usuario asignado'];
    foreach ($titulos as $indice => $titulo) {
        $hoja->setCellValueExplicit(chr(65 + $indice) . '1', $titulo, DataType::TYPE_STRING);
    }
    $fila = 2;
    foreach ($productos as $producto) {
        $valores = [
            $producto['codigo'] ?? 'Sin código registrado',
            $producto['fecha_movimiento'] !== null
                ? Date::PHPToExcel(new DateTimeImmutable($producto['fecha_movimiento']))
                : 'Sin registro de baja',
            $producto['descripcion'],
            $producto['categoria'],
            $producto['area'],
            $producto['motivo'] ?? '—',
            $producto['administrador'] ?? 'Sin registro',
            $producto['ultimo_responsable'] ?? 'Sin asignaciones registradas',
        ];
        foreach ($valores as $indice => $valor) {
            // Conserva códigos y textos literalmente, sin ejecutar fórmulas.
            if ($indice === 1 && $producto['fecha_movimiento'] !== null) {
                $hoja->setCellValueExplicit('B' . $fila, $valor, DataType::TYPE_NUMERIC);
                $hoja->getStyle('B' . $fila)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
            } else {
                $hoja->setCellValueExplicit(chr(65 + $indice) . $fila, (string) $valor, DataType::TYPE_STRING);
            }
        }
        $fila++;
    }
    $ultimaFila = max(1, $fila - 1);
    $hoja->getStyle('A1:H1')->getFont()->setBold(true)->getColor()->setARGB('f4f5f7');

    $hoja->getStyle('A1:H1')->getFill()->setFillType(
        \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID
    )
    ->getStartColor()->setARGB('a60000');

    $hoja->getStyle('A1:H' . $ultimaFila)->getAlignment()
        ->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
    foreach (['A' => 18, 'B' => 20, 'C' => 35, 'D' => 25, 'E' => 25, 'F' => 40, 'G' => 38, 'H' => 38] as $columna => $ancho) {
        $hoja->getColumnDimension($columna)->setWidth($ancho);
    }
    $hoja->freezePane('A2');
    $hoja->setAutoFilter('A1:H' . $ultimaFila);
    return $excel;
}

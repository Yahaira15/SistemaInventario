<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require_once __DIR__ . '/presentacion.php';

/** Convierte las mismas filas del modal a texto, sin exportar HTML ni JSON. */
function filasCambiosExcelAuditoria(array $evento): array
{
    $html = presentarDatosAuditoria($evento['datos_anteriores'], $evento['datos_nuevos']);
    $documento = new DOMDocument();
    $documento->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $filas = [];
    foreach ($documento->getElementsByTagName('tr') as $fila) {
        $valores = $fila->getElementsByTagName('td');
        if ($valores->length !== 2) { continue; }
        $filas[] = [
            $fila->getElementsByTagName('th')->item(0)?->textContent ?? '',
            $valores->item(0)->textContent,
            $valores->item(1)->textContent,
        ];
    }
    return $filas;
}

function escribirFilaExcelAuditoria(Worksheet $hoja, int $fila, array $valores): void
{
    foreach (array_values($valores) as $indice => $valor) {
        // Datos explícitamente textuales: mantiene códigos y evita fórmulas.
        $hoja->setCellValueExplicit([$indice + 1, $fila], (string) $valor, DataType::TYPE_STRING);
    }
}

/** Identifica el registro con los datos históricos, sin depender de nombres actuales. */
function identificarRegistroExcelAuditoria(array $evento): string
{
    $antes = json_decode($evento['datos_anteriores'] ?? 'null', true);
    $despues = json_decode($evento['datos_nuevos'] ?? 'null', true);
    $datos = array_replace(is_array($antes) ? $antes : [], is_array($despues) ? $despues : []);
    $texto = static function (string $campo) use ($datos): string {
        return is_scalar($datos[$campo] ?? null) ? trim((string) $datos[$campo]) : '';
    };
    $referencia = '#' . $evento['id_registro'];
    $tipo = $evento['entidad'];
    if (in_array($tipo, ['productos', 'producto_asignaciones'], true)) {
        $codigo = $texto('codigo');
        $etiquetaCodigo = 'Código';
        if ($codigo === '' && is_array($antes) && is_string($antes['codigo'] ?? null) && trim($antes['codigo']) !== '') {
            $codigo = trim($antes['codigo']);
            $etiquetaCodigo = 'Código anterior';
        }
        if ($codigo === '' && !array_key_exists('codigo', $datos) && is_string($evento['codigo_actual'] ?? null)) {
            $codigo = trim($evento['codigo_actual']);
            $etiquetaCodigo = 'Código actual';
        }
        $partes = [$codigo !== '' ? "$etiquetaCodigo: $codigo" : 'Código no registrado'];
        $partes[] = $texto('equipo') ?: ($texto('descripcion') ?: 'Equipo sin descripción');
        if ($tipo === 'producto_asignaciones') {
            $partes[] = 'Responsable: ' . ($texto('responsable') ?: 'No registrado en el evento');
        }
        return implode(' · ', $partes);
    }
    return match ($tipo) {
        'usuarios' => (trim($texto('nombre') . ' ' . $texto('apellido')) ?: "Usuario $referencia")
            . ' · Cédula: ' . ($texto('cedula') ?: 'No registrada'),
        'areas' => 'Área: ' . ($texto('nombre') ?: $referencia),
        'categorias' => 'Categoría: ' . ($texto('nombre') ?: $referencia),
        'atributos' => 'Atributo: ' . ($texto('nombre') ?: $referencia),
        'atributo_opciones' => 'Atributo: ' . ($texto('atributo') ?: 'No registrado')
            . ' · Opción: ' . ($texto('valor') ?: $referencia),
        'categoria_atributos' => 'Categoría: ' . ($texto('categoria') ?: 'No registrada')
            . ' · Atributo: ' . ($texto('atributo') ?: $referencia),
        default => nombreRegistroAuditoria($evento) . " ($referencia)",
    };
}

function crearExcelAuditoria(iterable $eventos, array $modulos, array $acciones): Spreadsheet
{
    $excel = new Spreadsheet();
    $resumen = $excel->getActiveSheet()->setTitle('Eventos');
    escribirFilaExcelAuditoria($resumen, 1, ['ID del evento', 'Fecha', 'Admin. ejecutor', 'Módulo afectado', 'Acción realizada', 'Ítem afectado']);
    $tipos = ['productos'=>'Producto', 'producto_asignaciones'=>'Asignación de equipo', 'usuarios'=>'Usuario', 'areas'=>'Área', 'categorias'=>'Categoría', 'atributos'=>'Atributo', 'atributo_opciones'=>'Opción de atributo', 'categoria_atributos'=>'Atributo de categoría'];
    $detalle = $excel->createSheet()->setTitle('Cambios');
    escribirFilaExcelAuditoria($detalle, 1, ['ID del evento', 'Código del equipo', 'Ítem afectado', 'Fecha', 'Admin. ejecutor', 'Acción realizada', 'Campo modificado', 'Antes', 'Después']);
    $filaCambio = 2;
    $filaEvento = 2;
    foreach ($eventos as $evento) {
        $identificacion = identificarRegistroExcelAuditoria($evento);
        $tipoRegistro = $tipos[$evento['entidad']] ?? $evento['entidad'];
        $itemAfectado = str_starts_with($identificacion, $tipoRegistro . ': ')
            ? $identificacion
            : $tipoRegistro . ' · ' . $identificacion;
        $codigo = contextoEventoAuditoria($evento) ? codigoRegistroAuditoria($evento) : 'No aplica';
        foreach (filasCambiosExcelAuditoria($evento) as $cambio) {
            escribirFilaExcelAuditoria($detalle, $filaCambio++, [
                $evento['id_evento'], $codigo, $itemAfectado, $evento['fecha'],
                $evento['administrador_nombre'], $acciones[$evento['accion']] ?? $evento['accion'],
                ...$cambio,
            ]);
        }
        escribirFilaExcelAuditoria($resumen, $filaEvento, [
            $evento['id_evento'],
            $evento['fecha'],
            $evento['administrador_nombre'],
            $modulos[$evento['modulo']] ?? $evento['modulo'],
            $acciones[$evento['accion']] ?? $evento['accion'],
            $itemAfectado,
        ]);
        $resumen->getRowDimension($filaEvento++)->setRowHeight(-1);
    }
    foreach ([[$resumen, 'F', $filaEvento], [$detalle, 'I', $filaCambio]] as [$hoja, $ultimaColumna, $siguienteFila]) {
        $ultimaFila = max(1, $siguienteFila - 1);
        $hoja->freezePane('A2');
        $hoja->setAutoFilter("A1:$ultimaColumna$ultimaFila");
        $hoja->getStyle("A1:{$ultimaColumna}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $hoja->getStyle("A1:{$ultimaColumna}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFA60000');
        $hoja->getStyle("A1:$ultimaColumna$ultimaFila")->getAlignment()->setWrapText(true)->setVertical('top');
        foreach (range('A', $ultimaColumna) as $columna) { $hoja->getColumnDimension($columna)->setWidth(25); }
        $hoja->getColumnDimension('A')->setWidth(14);
        $hoja->getRowDimension(1)->setRowHeight(30);
    }
    $resumen->getColumnDimension('F')->setWidth(65);
    $detalle->getColumnDimension('C')->setWidth(65);
    $detalle->getColumnDimension('G')->setWidth(35);
    $detalle->getColumnDimension('H')->setWidth(55);
    $detalle->getColumnDimension('I')->setWidth(55);
    $excel->setActiveSheetIndex(0);
    return $excel;
}
<?php

declare(strict_types=1);

function paginasAuditoria(int $paginaActual, int $totalPaginas): array
{
    $paginas = [1, $totalPaginas];

    for ($pagina = $paginaActual - 1; $pagina <= $paginaActual + 1; $pagina++) {
        if ($pagina >= 1 && $pagina <= $totalPaginas) {
            $paginas[] = $pagina;
        }
    }

    $paginas = array_values(array_unique($paginas));
    sort($paginas);
    $resultado = [];
    $anterior = null;

    foreach ($paginas as $pagina) {
        if ($anterior !== null && $pagina > $anterior + 1) {
            $resultado[] = null;
        }

        $resultado[] = $pagina;
        $anterior = $pagina;
    }

    return $resultado;
}

function escaparAuditoria(mixed $valor): string
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function codigoRegistroAuditoria(array $evento): string
{
    foreach (['datos_nuevos', 'datos_anteriores'] as $campo) {
        $datos = json_decode($evento[$campo] ?? 'null', true);
        if (is_array($datos) && array_key_exists('codigo', $datos)) {
            return is_string($datos['codigo']) && trim($datos['codigo']) !== ''
                ? 'Código: ' . $datos['codigo']
                : 'Sin código registrado';
        }
    }

    return !empty($evento['codigo_actual'])
        ?  $evento['codigo_actual']
        : 'Código no disponible';
}

function nombreRegistroAuditoria(array $evento): string
{
    foreach (['datos_nuevos', 'datos_anteriores'] as $campo) {
        $datos = json_decode($evento[$campo] ?? 'null', true);
        if (!is_array($datos)) { continue; }
        if ($evento['entidad'] === 'categoria_atributos') {
            return ($datos['categoria'] ?? 'Categoría') . ' / ' . ($datos['atributo'] ?? 'Atributo');
        }
        if ($evento['entidad'] === 'atributo_opciones') {
            return ($datos['atributo'] ?? 'Atributo') . ' / ' . ($datos['valor'] ?? 'Opción');
        }
        foreach (['equipo', 'nombre', 'descripcion'] as $nombre) {
            if (is_string($datos[$nombre] ?? null) && trim($datos[$nombre]) !== '') {
                return trim($datos[$nombre] . ($nombre === 'nombre' ? ' ' . ($datos['apellido'] ?? '') : ''));
            }
        }
    }
    return 'Registro sin descripción';
}
function contextoEventoAuditoria(array $evento): array
{
    if (!in_array($evento['entidad'], ['productos', 'producto_asignaciones'], true)) {
        return [];
    }

    $antes = json_decode($evento['datos_anteriores'] ?? 'null', true);
    $despues = json_decode($evento['datos_nuevos'] ?? 'null', true);
    $antes = is_array($antes) ? $antes : [];
    $despues = is_array($despues) ? $despues : [];
    $contexto = [];

    foreach (['equipo' => 'Equipo', 'responsable' => 'Responsable', 'area' => 'Área'] as $campo => $etiqueta) {
        // Un valor explícitamente vacío no se sustituye por un dato anterior.
        $valor = array_key_exists($campo, $despues)
            ? $despues[$campo]
            : ($antes[$campo] ?? null);
        $contexto[$etiqueta] = is_string($valor) && trim($valor) !== ''
            ? trim($valor)
            : 'No registrado en este evento';
    }

    return $contexto;
}

function presentarDatosAuditoria(
    ?string $datosAnteriores,
    ?string $datosNuevos
): string {
    $antes = $datosAnteriores === null 
        ? []
        : json_decode($datosAnteriores, true, 512, JSON_THROW_ON_ERROR);

    $despues = $datosNuevos === null
        ? []
        : json_decode($datosNuevos, true, 512, JSON_THROW_ON_ERROR);

    $campos = [
        'codigo' => 'Código',
        'nombre' => 'Nombre', 'apellido' => 'Apellido', 'descripcion' => 'Descripción',
        'cedula' => 'Cédula', 'correo' => 'Correo', 'rol' => 'Rol', 'estado' => 'Estado',
        'categoria' => 'Categoría', 'atributo' => 'Atributo', 'prefijo_codigo' => 'Prefijo del código',
        'tipo_dato' => 'Tipo de dato', 'unidad' => 'Unidad', 'longitud_maxima' => 'Longitud máxima',
        'valor_minimo' => 'Valor mínimo', 'valor_maximo' => 'Valor máximo', 'decimales' => 'Decimales',
        'valor' => 'Valor', 'orden_visual' => 'Orden', 'requerido' => 'Obligatorio',
        'disponible' => 'Disponible', 'motivo_baja' => 'Motivo de baja', 'fecha_baja' => 'Fecha de baja',
        'credenciales_actualizadas' => 'Credenciales actualizadas',
        'equipo' => 'Equipo',
        'responsable' => 'Responsable',
        'area' => 'Área',
        'fecha_asignacion' => 'Fecha de asignación',
        'fecha_devolucion' => 'Fecha de devolución'
    ];

    foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $campo) {
        if (str_starts_with($campo, 'Atributo: ') || str_starts_with($campo, 'Unidad #') || str_starts_with($campo, 'Opción #')) {
            $campos[$campo] = str_starts_with($campo, 'Atributo: ')
                ? preg_replace('/\s+\(#[0-9]+\)$/u', '', $campo)
                : $campo;
        }
    }

    $formatear = static function (string $campo, mixed $valor): string {
        if ($valor === null || $valor === '') {
            return 'Sin registrar';
        }

        if (in_array($campo, ['requerido', 'disponible'], true)) {
            return (int) $valor === 1 ? 'Sí' : 'No';
        }
        if (in_array($campo, [
            'fecha_baja',
            'fecha_asignacion',
            'fecha_devolucion'
        ], true)) {
            try {
                $fecha = new DateTimeImmutable((string) $valor);

                return $fecha->format(
                    strlen((string) $valor) > 10
                        ? 'd/m/Y H:i'
                        : 'd/m/Y'
                );
            } catch (Exception) {
                return (string) $valor;
            }
        }
        
        return (string) $valor;
    };

    $filas = '';

    foreach ($campos as $campo => $etiqueta) {
        if (
            !array_key_exists($campo, $antes)
            && !array_key_exists($campo, $despues)
        ) {
            continue;
        }

        $valorAntes = $antes[$campo] ?? null;
        $valorDespues = $despues[$campo] ?? null;

        if (
            $datosAnteriores !== null
            && $datosNuevos !== null
            && $valorAntes === $valorDespues 
        ) {
            continue;
        }

        $textoAntes = $datosAnteriores === null
            ? 'No aplica'
            : $formatear($campo, $valorAntes);

        $textoDespues = $datosNuevos === null
            ? 'No aplica'
            : $formatear($campo, $valorDespues);

        $filas .= '<tr>'
            . '<th scope="row">' . escaparAuditoria($etiqueta) . '</th>'
            . '<td>' . escaparAuditoria($textoAntes) . '</td>'
            . '<td>' . escaparAuditoria($textoDespues) . '</td>'
            . '</tr>';
    }

    if ($filas === '') {
        return '<p>No hay cambios en los campos mostrados.</p>';
    }

    return '<div class="comparacion-contenedor">'
        . '<table class="tabla-cambios">'
        . '<caption>Comparación de la operación</caption>'
        . '<thead><tr>'
        . '<th scope="col">Campo</th>'
        . '<th scope="col">Antes</th>'
        . '<th scope="col">Despues</th>'
        . '</tr></thead>'
        . '<tbody>' . $filas . '</tbody>'
        . '</table></div>';
}

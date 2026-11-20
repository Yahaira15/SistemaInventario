<?php

declare(strict_types=1);
require_once __DIR__ . '/entrada.php';
require_once __DIR__ . '/busqueda.php';

/** Campos recuperables por módulo. Las contraseñas y los tokens nunca se guardan. */
function configuracionFormulario(string $modulo): array
{
    return match ($modulo) {
        'productos' => ['id_producto', 
            [
                'id_categoria', 'id_codigo', 'generar_codigo', 'token_creacion', 'descripcion', 'id_area', 'estado', 'version', 'atributos', 'unidades_producto'
            ]
        ],
        'areas' => ['id_area', ['nombre', 'estado']],
        'categorias' => ['id_categoria', ['nombre', 'descripcion', 'estado', 'prefijo_codigo']],
        'usuarios' => ['id_usuario', ['cedula', 'nombre', 'apellido', 'correo', 'id_area', 'rol', 'estado']],
        'atributos' => ['id_atributo', ['nombre', 'descripcion', 'tipoDato', 'longitud_maxima', 'estado', 'opciones', 'unidades']],
        'atributo_opciones' => ['id_atributo_opcion', ['valor', 'estado']],
        'categoria_atributos' => ['id_categoria_atributo', ['id_atributo', 'requerido', 'estado']],
    };
}

/** Genera el retorno al formulario solo cuando falló una creación o edición. */
function urlRetornoFormulario(string $modulo, string $ruta, array $contexto = []): string
{
    $accion = $_POST['accion'] ?? '';
    if (!empty($_SESSION['mensajeError']) && in_array($accion, ['crear', 'asignar', 'actualizar'], true)) {
        [$campoId, $campos] = configuracionFormulario($modulo);
        $datos = [];
        foreach ($campos as $campo) {
            $valor = $_POST[$campo] ?? null;
            if (is_scalar($valor)) {
                $datos[$campo] = (string) $valor;
            } elseif (in_array($campo, ['opciones', 'unidades'], true) && is_array($valor)) {
                $datos[$campo] = array_values(array_filter($valor, 'is_scalar'));
            } elseif (in_array($campo, ['atributos', 'unidades_producto'], true) && is_array($valor)) {
                $datos[$campo] = array_filter($valor, 'is_scalar');
            }
        }
        $id = $accion === 'actualizar' ? max(0, (int) ($_POST[$campoId] ?? 0)) : 0;
        $token = bin2hex(random_bytes(16));
        // Límite de borradores por sesión; cada pestaña recibe un identificador propio.
        $borradores = $_SESSION['formularios_pendientes'] ?? [];
        $borradores = array_slice($borradores, -19, null, true);
        $borradores[$token] = compact('modulo', 'id', 'contexto', 'datos');
        $_SESSION['formularios_pendientes'] = $borradores;
        $contexto['recuperar'] = $token;
        $contexto[$id > 0 ? 'editar' : 'crear'] = $id > 0 ? $id : 1;
    }
    $volver = [];
    if (is_string($_POST['volver'] ?? null)) {
        parse_str($_POST['volver'], $volver);
    }
    $parametros = array_replace(contextoListado($modulo, $volver), $contexto);
    return $ruta . ($parametros ? '?' . http_build_query($parametros) : '');
}

/** Consume una sola vez los datos, sin mezclarlos con otro módulo o registro. */
function recuperarFormulario(string $modulo, int $id = 0, array $contexto = []): array
{
    $token = $_GET['recuperar'] ?? '';
    if (!is_string($token)) {
        return [];
    }
    $borrador = $_SESSION['formularios_pendientes'][$token] ?? null;
    if (!$borrador || $borrador['modulo'] !== $modulo || $borrador['id'] !== $id || $borrador['contexto'] !== $contexto) {
        return [];
    }
    unset($_SESSION['formularios_pendientes'][$token]);
    return $borrador['datos'];
}

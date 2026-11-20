<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../auth/formularios.php';

function verificarRecuperacion(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
    echo 'OK: ', $mensaje, PHP_EOL;
}

foreach (['areas', 'categorias', 'usuarios', 'atributos', 'atributo_opciones', 'categoria_atributos'] as $modulo) {
    [$campoId, $campos] = configuracionFormulario($modulo);
    $contexto = match ($modulo) {
        'atributo_opciones' => ['atributo' => 7],
        'categoria_atributos' => ['categoria' => 9],
        default => [],
    };
    foreach (['crear', 'actualizar'] as $accion) {
        $_SESSION = ['mensajeError' => 'Error de prueba'];
        $_POST = array_fill_keys($campos, '');
        $_POST += ['accion' => $accion, $campoId => 42, 'contrasena' => 'secreto', 'csrf_token' => 'token'];
        if ($modulo === 'atributos') {
            $_POST['opciones'] = ['Rojo', '', '<Azul>'];
            $_POST['decimales'] = '0';
        }
        if ($modulo === 'categoria_atributos') {
            $_POST['requerido'] = '0';
            if ($accion === 'crear') {
                $_POST['accion'] = 'asignar';
            }
        }
        $url = urlRetornoFormulario($modulo, '/formulario', $contexto);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $_GET);
        $id = $accion === 'actualizar' ? 42 : 0;
        verificarRecuperacion(isset($_GET[$id ? 'editar' : 'crear']), "$modulo: mantiene contexto de $accion");
        verificarRecuperacion(recuperarFormulario($modulo, $id + 1, $contexto) === [], "$modulo: no aplica datos a otro registro");
        $datos = recuperarFormulario($modulo, $id, $contexto);
        $esperados = array_intersect_key($_POST, array_flip($campos));
        verificarRecuperacion($datos === $esperados, "$modulo: recupera campos, vacíos y listas sin contraseñas ni tokens");
        verificarRecuperacion(recuperarFormulario($modulo, $id, $contexto) === [], "$modulo: consume los datos una sola vez");
    }
}

$_SESSION = ['mensajeError' => 'Error'];
$_POST = ['accion' => 'crear', 'nombre' => 'Primera pestaña'];
$url1 = urlRetornoFormulario('areas', '/areas');
$_POST['nombre'] = 'Segunda pestaña';
$url2 = urlRetornoFormulario('areas', '/areas');
parse_str((string) parse_url($url1, PHP_URL_QUERY), $_GET);
verificarRecuperacion(recuperarFormulario('categorias') === [], 'No mezcla módulos');
verificarRecuperacion(recuperarFormulario('areas')['nombre'] === 'Primera pestaña', 'Conserva la primera pestaña');
parse_str((string) parse_url($url2, PHP_URL_QUERY), $_GET);
verificarRecuperacion(recuperarFormulario('areas')['nombre'] === 'Segunda pestaña', 'Conserva la segunda pestaña');

$_POST = ['accion' => 'actualizar', 'id_atributo_opcion' => 3, 'valor' => 'Azul'];
$url = urlRetornoFormulario('atributo_opciones', '/opciones', ['atributo' => 7]);
parse_str((string) parse_url($url, PHP_URL_QUERY), $_GET);
verificarRecuperacion(recuperarFormulario('atributo_opciones', 3, ['atributo' => 8]) === [], 'No mezcla atributos padre');
verificarRecuperacion(recuperarFormulario('atributo_opciones', 3, ['atributo' => 7])['valor'] === 'Azul', 'Conserva el atributo padre correcto');

$_SESSION = ['mensajeExito' => 'Guardado'];
verificarRecuperacion(urlRetornoFormulario('areas', '/areas') === '/areas' && !isset($_SESSION['formularios_pendientes']), 'Un guardado correcto no genera borradores');
$_SESSION = ['mensajeError' => 'Error'];
$_POST = ['accion' => 'cambiar_estado'];
verificarRecuperacion(urlRetornoFormulario('areas', '/areas') === '/areas', 'Cambiar estado no abre un formulario de edición');
$_POST = ['accion' => 'crear', 'nombre' => 'Área'];
for ($i = 0; $i < 25; $i++) {
    urlRetornoFormulario('areas', '/areas');
}
verificarRecuperacion(count($_SESSION['formularios_pendientes']) === 20, 'Limita la cantidad de borradores en sesión');

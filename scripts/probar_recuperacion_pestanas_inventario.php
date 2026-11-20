<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../admin/inventario/recuperacion.php';

function verificarPestanas(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
    echo "OK: $mensaje\n";
}

// Dos pestañas de la misma sesión, incluso administrando el mismo equipo.
$_SESSION = [];
$primero = ['id_producto' => 1, 'exito' => '', 'formulario' => [
    'id_producto' => 1, 'id_usuario' => 3, 'responsable_texto' => 'Ana', 'error' => 'Error A',
]];
$segundo = ['id_producto' => 1, 'exito' => '', 'formulario' => [
    'id_producto' => 1, 'id_usuario' => 4, 'responsable_texto' => 'Luis', 'error' => 'Error B',
]];
$tokenA = guardarResultadoInventario($primero);
$tokenB = guardarResultadoInventario($segundo);
verificarPestanas($tokenA !== $tokenB, 'Cada pestaña recibe un identificador diferente');
verificarPestanas(consultarResultadoInventario($tokenB) === $segundo && consultarResultadoInventario($tokenA) === $primero, 'Retornos en orden inverso conservan texto, selección y error propios');
foreach ([null, [], 1, '../', str_repeat('a', 32)] as $invalido) {
    verificarPestanas(consultarResultadoInventario($invalido) === [], 'Token ausente, malformado o desconocido no consume borradores');
}
descartarResultadoInventario($tokenA, 2);
verificarPestanas(consultarResultadoInventario($tokenA) === $primero, 'Otro equipo no puede descartar el borrador');
descartarResultadoInventario($tokenA, 1);
verificarPestanas(consultarResultadoInventario($tokenA) === [] && consultarResultadoInventario($tokenB) === $segundo, 'Mostrar o descartar A no consume B');
$tokenReintento = guardarResultadoInventario($primero);
verificarPestanas(consultarResultadoInventario($tokenB) === $segundo, 'Un reintento fallido conserva el borrador de otra pestaña');
descartarResultadoInventario($tokenReintento, 1);
$tokenExito = guardarResultadoInventario(['id_producto' => 1, 'exito' => 'Guardado']);
verificarPestanas(consultarResultadoInventario($tokenExito)['exito'] === 'Guardado' && consultarResultadoInventario($tokenB) === $segundo, 'El éxito tiene su propio retorno y no borra el error ajeno');
descartarResultadoInventario($tokenExito, 1);
verificarPestanas(consultarResultadoInventario($tokenB) === $segundo, 'Consumir el éxito no altera otra pestaña');
$sesionOriginal = $_SESSION;
$_SESSION = [];
verificarPestanas(consultarResultadoInventario($tokenB) === [], 'Otra sesión no puede recuperar datos con el token');
$_SESSION = $sesionOriginal;
for ($i = 0; $i < 25; $i++) {
    $ultimo = guardarResultadoInventario(['id_producto' => 2, 'exito' => (string)$i]);
}
verificarPestanas(count($_SESSION['inventario_resultados']) === 20 && consultarResultadoInventario($ultimo)['exito'] === '24', 'Limita los retornos pendientes y conserva el más reciente');

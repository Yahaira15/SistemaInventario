<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../auth/formularios.php';
class FinRespuestaModalBaja extends Exception {}
function respuestaModalBaja(array $post, array $sesion, int $id): array {
    $archivo = file_get_contents(__DIR__ . '/../admin/productos/procesar.php');
    $inicio = strpos($archivo, "if (\$accion === 'dar_baja' && isset(\$_SESSION['mensajeError'])");
    if ($inicio === false) { throw new RuntimeException('Revisar adaptador de respuesta'); }
    $codigo = str_replace('exit;', 'throw new FinRespuestaModalBaja();', substr($archivo, $inicio));
    $_POST = $post + ['modal_baja' => '1']; $_SESSION = $sesion; $accion = 'dar_baja';
    http_response_code(200);
    ob_start();
    try { eval($codigo); } catch (FinRespuestaModalBaja $fin) {}
    $texto = ob_get_clean();
    return ['datos' => json_decode($texto, true, 512, JSON_THROW_ON_ERROR), 'estado' => http_response_code(), 'sesion' => $_SESSION];
}
function comprobarModalBaja(bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
}
// Evita enviar salida antes de las cabeceras de los casos siguientes.
$exito = respuestaModalBaja(['volver' => 'buscar=Portatil&categoria=2&pagina=3&url=https://example.com'], ['mensajeExito' => 'Baja registrada'], 7);
comprobarModalBaja($exito['estado'] === 200 && $exito['datos']['exito'] === true, 'Respuesta de éxito inválida');
comprobarModalBaja($exito['datos']['url'] === '/sistemaInventario/administracion/productos?buscar=Portatil&categoria=2&pagina=3', 'No conserva filtros o admite retorno externo');
comprobarModalBaja($exito['sesion']['mensajeExito'] === 'Baja registrada', 'Consume el mensaje antes de mostrar el listado');
$error = respuestaModalBaja(['fecha_baja' => '2026-10-07T08:30:00', 'motivo' => 'Motivo ingresado'], ['mensajeError' => 'Fecha inválida'], 7);
comprobarModalBaja($error['estado'] === 422 && $error['datos']['url'] === '/sistemaInventario/administracion/bajas?id=7', 'No permite recargar el error dentro del modal');
comprobarModalBaja($error['sesion']['baja_productos'][7] === ['motivo' => 'Motivo ingresado'], 'Conserva el motivo e ignora la fecha enviada al fallar');
$invalido = respuestaModalBaja([], ['mensajeError' => 'Equipo inválido'], 0);
comprobarModalBaja($invalido['estado'] === 422 && $invalido['datos']['mensaje'] === 'Equipo inválido', 'Error de identificador no manejado');
echo "OK: éxito JSON, filtros y retorno interno, mensaje conservado, error 422, recuperación de motivo y equipo inválido. Sin conexión ni cambios en base de datos.\n";

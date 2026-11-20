<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/probar_inventario.php';
require_once __DIR__ . '/../admin/inventario/recuperacion.php';

// Simula nuevas altas que desplazan el equipo 1 fuera de la primera página.
$insertar = $db->prepare("INSERT INTO productos
    (id_producto, descripcion, id_categoria, id_area, estado)
    VALUES (?, 'Equipo de prueba', 1, 1, 'ACTIVO')");
for ($idNuevo = 4; $idNuevo <= 17; $idNuevo++) {
    $insertar->execute([$idNuevo]);
}
// El código vigente se ordena antes que los equipos sin código.
// Los nuevos códigos anteriores al PC001 desplazan el equipo de forma realista.
$db->exec("INSERT INTO producto_codigos (producto_vigente,id_codigo,id_producto,fecha_fin) VALUES (2,2,2,NULL);
    INSERT INTO codigos_inventario VALUES (2,'AA002')");
for ($idNuevo = 4; $idNuevo <= 17; $idNuevo++) {
    $db->exec("INSERT INTO producto_codigos (producto_vigente,id_codigo,id_producto,fecha_fin) VALUES ($idNuevo,$idNuevo,$idNuevo,NULL);
        INSERT INTO codigos_inventario VALUES ($idNuevo,'AA$idNuevo')");
}
$listado = consultarInventario($db, filtrosInventario([]));
comprobarInventario(!in_array(1, array_column($listado['productos'], 'id_producto')), 'Equipo desplazado fuera de la primera página');
$recuperado = consultarEquipoRecuperacionInventario($db, 1);
comprobarInventario((int)$recuperado['producto']['id_producto'] === 1, 'Recupera equipo fuera de la página');
$filtros = filtrosInventario(['usuario' => 'NO-COINCIDE', 'pagina' => 2]);
comprobarInventario(consultarInventario($db, $filtros)['total'] === 0, 'Filtros excluyen el equipo');
modificarAsignacionInventario($db, 'asignar', 1, 2, $idAdministradorPrueba);
$recuperado = consultarEquipoRecuperacionInventario($db, 1);
comprobarInventario(count($recuperado['responsables']) === 1 && (int)$recuperado['responsables'][0]['id_usuario'] === 2, 'Recuperación consulta responsables actuales');

// Renderiza la misma plantilla usada por el listado y la recuperación.
function escaparInventario(mixed $valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
function camposInventario(array $filtros, int $producto): void {
    echo '<input type="hidden" name="usuario" value="', escaparInventario($filtros['usuario']), '">';
}
$producto = $recuperado['producto'];
$responsables = $recuperado['responsables'];
$id = 1;
$recuperar = true;
$usuarioRecuperado = 1;
$textoRecuperado = 'Ana "<Prueba>"';
$error = 'Error <controlado>';
ob_start();
require __DIR__ . '/../admin/inventario/modal/modal_asignaciones.php';
$html = ob_get_clean();
comprobarInventario(str_contains($html, 'data-recuperar-formulario') && str_contains($html, 'id="modal-1"'), 'Genera ventana recuperada independiente del listado');
comprobarInventario(str_contains($html, 'value="Ana &quot;&lt;Prueba&gt;&quot;"'), 'Conserva y escapa texto ingresado');
comprobarInventario(str_contains($html, 'name="id_usuario" value="1"'), 'Conserva selección para reintentar');
comprobarInventario(str_contains($html, 'value="NO-COINCIDE"'), 'Mantiene filtros de retorno');
$db->exec("UPDATE productos SET estado='INACTIVO' WHERE id_producto=1");
comprobarInventario(consultarEquipoRecuperacionInventario($db, 1)['producto']['estado'] === 'INACTIVO', 'Detecta equipo dado de baja para impedir reintento');
comprobarInventario(consultarEquipoRecuperacionInventario($db, 99999) === null, 'Detecta equipo inexistente');
echo "Recuperación verificada con tablas temporales.\n";

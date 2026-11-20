<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../auth/sesion.php';
require_once __DIR__ . '/../../../auth/proteger.php';
require_once __DIR__ . '/../servicio.php';

iniciarSesionSegura();
exigirAdministrador();

$conexion = require __DIR__ . '/../../../config/conexion.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$producto = consultarProducto($conexion, $id);
if (!$producto) {
    http_response_code(404); exit('Producto no encontrado.');
}
if ($producto['estado'] === 'INACTIVO' && $producto['codigo'] === '') {
    $codigoAnterior = $conexion->prepare(
        'SELECT c.codigo FROM producto_codigos pc
         JOIN codigos_inventario c ON c.id_codigo = pc.id_codigo
         WHERE pc.id_producto = ?
         ORDER BY pc.fecha_inicio DESC, pc.id_producto_codigo DESC LIMIT 1'
    );
    $codigoAnterior->execute([$id]);
    $producto['codigo'] = (string) ($codigoAnterior->fetchColumn() ?: '');
}
$_SESSION['csrf_productos'] ??= bin2hex(random_bytes(32));
$error = (string) ($_SESSION['mensajeError'] ?? '');
unset($_SESSION['mensajeError']);
$datos = $_SESSION['baja_productos'][$id] ?? [];
unset($_SESSION['baja_productos'][$id]);
$q = $conexion->prepare('SELECT m.*, CONCAT(u.nombre," ",u.apellido) AS administrador FROM inventario_movimientos m LEFT JOIN usuarios u ON u.id_usuario=m.id_usuario_registro WHERE m.id_producto=? AND m.tipo_movimiento="BAJA" ORDER BY m.id_movimiento DESC LIMIT 1');
$q->execute([$id]);
$baja = $q->fetch();
$consultaDetalle = $conexion->prepare(
    'SELECT c.nombre AS categoria, a.nombre AS area,
        (SELECT CONCAT(u.nombre, " ", u.apellido)
         FROM producto_asignaciones pa
         LEFT JOIN usuarios u ON u.id_usuario = pa.id_usuario
         WHERE pa.id_producto = p.id_producto
         ORDER BY pa.fecha_asignacion DESC, pa.id_producto_asignacion DESC
         LIMIT 1) AS ultimo_responsable
     FROM productos p
     LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
     LEFT JOIN areas a ON a.id_area = p.id_area
     WHERE p.id_producto = ?'
);
$consultaDetalle->execute([$id]);
$detalleProducto = $consultaDetalle->fetch(PDO::FETCH_ASSOC) ?: [];
function escaparBaja(mixed $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
$seccionActiva = $producto['estado'] === 'INACTIVO' ? 'inventario' : 'productos';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Baja de equipo | Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/inventario/inventario.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/productos/bajas/modal.css">
</head>
<body>
<div class="estructura-admin">
    <?php require __DIR__ . '/../../menu_lateral.php'; ?>
    <div class="area-principal">
        <section class="encabezado-modulo"><h1>Baja de equipo</h1><?php require __DIR__ . '/../../usuario_encabezado.php'; ?></section>
        <main class="contenido-crud">
            <?php if ($error !== ''): ?><div class="alerta error" role="alert"><?= escaparBaja($error) ?></div><?php endif; ?>
            <section class="tarjeta ficha-baja-producto">
                <div class="titulo-tarjeta resumen-baja-producto">
                    <div class="identidad-baja-producto">
                        <span class="codigo-baja-producto"><?= escaparBaja($producto['codigo'] !== '' ? $producto['codigo'] : 'Equipo #' . $id) ?></span>
                        <span class="descripcion-baja-producto"><?= escaparBaja($producto['descripcion']) ?></span>
                    </div>
                </div>
                <?php if ($producto['estado'] === 'INACTIVO'): ?>
                    <div class="detalle-baja-producto">
                        <div class="estado-baja-producto">
                            <span class="punto-baja-producto" aria-hidden="true"></span>
                            Equipo retirado del inventario
                        </div>
                        <?php if (!$baja): ?>
                            <p class="alerta error" role="status">Registro anterior sin movimiento de baja. Su fecha y motivo requieren revisión.</p>
                        <?php endif; ?>
                        <dl class="datos-baja-producto">
                            <div>
                                <dt>Categoría</dt>
                                <dd><?= escaparBaja($detalleProducto['categoria'] ?? 'Sin registro') ?></dd>
                            </div>
                            <div>
                                <dt>Área</dt>
                                <dd><?= escaparBaja($detalleProducto['area'] ?? 'Sin registro') ?></dd>
                            </div>
                            <div>
                                <dt>Fecha de baja</dt>
                                <dd><?= escaparBaja(
                                    !empty($baja['fecha_movimiento'])
                                        ? (new DateTimeImmutable($baja['fecha_movimiento']))->format('d/m/Y H:i')
                                        : 'Sin registro de baja'
                                ) ?></dd>
                            </div>
                            <div>
                                <dt>Registrada por</dt>
                                <dd><?= escaparBaja($baja['administrador'] ?? 'Sin registro') ?></dd>
                            </div>
                            <div class="dato-baja-completo">
                                <dt>Último usuario asignado</dt>
                                <dd><?= escaparBaja($detalleProducto['ultimo_responsable'] ?? 'Sin asignaciones registradas') ?></dd>
                            </div>
                        </dl>
                        <section class="motivo-baja-producto" aria-labelledby="titulo-motivo-baja">
                            <h3 id="titulo-motivo-baja">Motivo de la baja</h3>
                            <p><?= escaparBaja($baja['motivo'] ?? 'Sin registro') ?></p>
                        </section>
                        <p class="nota-baja-producto">El historial del equipo se conserva. Este equipo no admite nuevas asignaciones.</p>
                    </div>
                <?php else: ?>
                    <form class="formulario-crud" method="post" action="/sistemaInventario/administracion/productos/procesar" data-confirmar="¿Confirmar la baja? Se cerrarán las asignaciones y se liberará el código del equipo.">
                        <input type="hidden" name="csrf_token" value="<?= escaparBaja($_SESSION['csrf_productos']) ?>">
                        <input type="hidden" name="accion" value="dar_baja">
                        <input type="hidden" name="id_producto" value="<?= $id ?>">
                        <input type="hidden" name="version" value="<?= (int)$producto['version'] ?>">
                        
                        <div class="campo-formulario">
                            <label for="motivo-baja">Motivo de la baja</label>
                            <textarea id="motivo-baja" name="motivo" maxlength="500" rows="4" required><?= escaparBaja($datos['motivo'] ?? '') ?></textarea>
                        </div>
                        <p>Se cerrarán las asignaciones a funcionarios y el código quedará disponible. El historial se conservará. El equipo no podrá reactivarse desde este módulo.</p>
                        <div class="acciones-formulario">
                            <button class="boton primario" type="submit">Confirmar baja</button>
                            <a class="boton secundario" data-cerrar-baja href="/sistemaInventario/administracion/productos">Cancelar</a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>
<script src="/sistemaInventario/admin/js/confirmaciones.js"></script>
</body>
</html>

<?php

declare(strict_types = 1);

require_once __DIR__ . '/../../../auth/sesion.php';
require_once __DIR__ . '/../../../auth/proteger.php';
require_once __DIR__ . '/servicio.php';

iniciarSesionSegura();
exigirAdministrador();

$conexion = require __DIR__ . '/../../../config/conexion.php';

$errorFiltro = '';
try {
    $filtros = filtrosBajas($_GET);
    $listado = consultarListadoBajas($conexion, $filtros);
} catch (DomainException $error) {
    $errorFiltro = $error->getMessage();
    $filtros = filtrosBajas(array_diff_key($_GET, ['desde' => true, 'hasta' => true]));
    foreach (['desde', 'hasta'] as $campo) {
        $filtros[$campo] = is_string($_GET[$campo] ?? null) ? mb_substr($_GET[$campo], 0, 10) : '';
    }
    $listado = ['productos' => [], 'total' => 0, 'pagina' => 1, 'totalPaginas' => 1];
}
$filtros['pagina'] = $listado['pagina'];
$filtrosExportacion = $filtros;
unset($filtrosExportacion['pagina']);
$urlExportacion = '/sistemaInventario/administracion/inventario/bajas/exportable?'
    . http_build_query($filtrosExportacion);

$categorias = $conexion->query(
    'SELECT id_categoria, nombre
     FROM categorias
     ORDER BY nombre'
)->fetchAll(PDO::FETCH_ASSOC);

$areas = $conexion->query(
    'SELECT id_area, nombre
     FROM areas
     ORDER BY nombre'
)->fetchAll(PDO::FETCH_ASSOC);

$seccionActiva = 'inventario';

function escaparListadoBajas(mixed $valor): string {
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function urlListadoBajas (array $filtros, int $pagina): string {
    $filtros['pagina'] = $pagina;

    return '/sistemaInventario/administracion/inventario/bajas?'
        . http_build_query($filtros);
}

// MUESTRA HASTA CINCO  NUMEROS ALREDEDOR DE LA PGINA ACTUAL.
$inicioPaginas = max(
    1, 
    min($listado['pagina'] - 2, $listado['totalPaginas'] - 4)
);

$finPaginas = min(
    $listado['totalPaginas'],
    $inicioPaginas + 4
);

?>



<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bajas | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/inventario/inventario.css">
</head>
<body>
    <div class="estructura-admin">
        <?php require __DIR__ . '/../../menu_lateral.php'; ?>
        
        <div class="area-principal">
            <section class="encabezado-modulo">
                <h1>Equipos de Baja</h1>
                <?php require __DIR__ . '/../../usuario_encabezado.php'; ?>
            </section>

            <div class="barra-navegacion">
                <a class="modulo-navegacion" href="/sistemaInventario/administracion/inventario">Inventario</a>
                <a class="modulo-navegacion activo" href="/sistemaInventario/administracion/inventario/bajas" aria-current="page">Bajas</a>
            </div>
        

            <main class="contenido-crud">
                <?php if ($errorFiltro !== ''): ?><div class="alerta error" role="alert"><?= escaparListadoBajas($errorFiltro) ?></div><?php endif; ?>
                <section class="tarjeta tabla-crud">
                    <div class="titulo-tarjeta encabezado-listado-inventario">
                        <div>
                            <h2>Listado de bajas</h2>
                            <p>Consulta los equipos retirados y su historial</p>
                        </div>
                        <a class="boton exportar boton-exportar-excel" href="<?= escaparListadoBajas($urlExportacion) ?>">
                            <svg class="icono-excel" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <path d="M14 2v6h6M8 12h8v6H8zM8 15h8M12 12v6" />
                            </svg>
                            <span>Generar Excel</span>
                        </a>
                    </div>

                    <form 
                        class="formulario-crud filtros-inventario filtros-bajas"
                        method="get"
                        action="/sistemaInventario/administracion/inventario/bajas"
                    >
                        <div class="campo-formulario">
                            <label for="buscar-bajas">Código o descripción</label>
                            <input type="search"
                                id="buscar-bajas"
                                name="buscar"
                                maxlength="100"
                                value="<?= escaparListadoBajas($filtros['buscar']) ?>">
                        </div>

                        <div class="campo-formulario">
                            <label for="categoria-bajas">Categoría</label>
                            <select id="categoria-bajas" name="categoria">
                                <option value="">Todas</option>

                                <?php foreach ($categorias as $categoria):  ?>
                                    <option
                                        value="<?= (int) $categoria['id_categoria'] ?>"
                                        <?= $filtros['categoria'] === (int) $categoria['id_categoria'] 
                                        ? 'selected'
                                        : '' ?>
                                    ><?= escaparListadoBajas($categoria['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="campo-formulario">
                            <label for="area-bajas">Área</label>
                            <select name="area" id="area-bajas">
                                <option value="">Todas</option>
                                <?php foreach($areas as $area): ?>
                                <option 
                                    value="<?= (int) $area['id_area'] ?>"
                                    <?= $filtros['area'] === (int) $area['id_area']
                                        ? 'selected'
                                        : '' ?>
                                ><?= escaparListadoBajas($area['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="campo-formulario">
                            <label for="desde-bajas">Fecha de baja: desde</label>
                            <input type="date" id="desde-bajas" name="desde" value="<?= escaparListadoBajas($filtros['desde']) ?>">
                        </div>
                        <div class="campo-formulario">
                            <label for="hasta-bajas">Fecha de baja: hasta</label>
                            <input type="date" id="hasta-bajas" name="hasta" value="<?= escaparListadoBajas($filtros['hasta']) ?>">
                        </div>

                        <div class="acciones-formulario">
                            <button type="submit" class="boton primario">Buscar</button>
                            <a class="boton secundario" href="/sistemaInventario/administracion/inventario/bajas">Limpiar</a>
                        </div>
                    </form>

                    <div class="titulo-tarjeta">
                        <p><?= (int) $listado['total'] ?> equipo(s) encontrado(s)</p>
                    </div>

                    <div class="tabla-contenedor">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Último código</th>
                                    <th scope="col">Descripción</th>
                                    <th scope="col">Categoría</th>
                                    <th scope="col">Área</th>
                                    <th scope="col">Fecha de baja</th>
                                    <th scope="col">Motivo</th>
                                    <th scope="col">Registrada por</th>
                                    <th scope="col">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($listado['productos'] as $producto): ?>
                                    <?php $id = (int) $producto['id_producto']; ?>
                                    <tr>
                                        <td><?= escaparListadoBajas($producto['codigo'] ?? 'Sin código registrado') ?></td>
                                        <td><?= escaparListadoBajas($producto['descripcion']) ?></td>
                                        <td><?= escaparListadoBajas($producto['categoria']) ?></td>
                                        <td><?= escaparListadoBajas($producto['area']) ?></td>

                                        <td>
                                            <?php if($producto['fecha_movimiento'] !== null): ?>
                                                <?= escaparListadoBajas(
                                                    (new DateTimeImmutable($producto['fecha_movimiento']))
                                                        ->format('d/m/Y H:i')
                                                ) ?>
                                            <?php else: ?>
                                                Sin registro de baja
                                            <?php endif; ?>
                                        </td>

                                        <td><?= escaparListadoBajas($producto['motivo']) ?></td>
                                        <td><?= escaparListadoBajas($producto['administrador'] ?? 'Sin registro') ?></td>
                                        <td>
                                            <div class="acciones-tabla acciones-listado-bajas">
                                                <button
                                                    type="button"
                                                    class="boton-tabla"
                                                    onclick="abrirModal('detalle-baja-<?= $id ?>')"
                                                    aria-haspopup="dialog"
                                                    aria-expanded="false"
                                                    aria-controls="detalle-baja-<?= $id ?>"
                                                >Ver baja</button>

                                                <button
                                                    type="button"
                                                    class="boton administracion"
                                                    onclick="abrirModal('historial-baja-<?= $id ?>')"
                                                    aria-haspopup="dialog"
                                                    aria-expanded="false"
                                                    aria-controls="historial-baja-<?= $id ?>"
                                                >Historial</button>
                                            </div>

                                            <?php require __DIR__ . '/modal_detalle.php'; ?>

                                            <dialog
                                                class="modal modal-inventario"
                                                id="historial-baja-<?= $id ?>"
                                                aria-labelledby="titulo-historial-baja-<?= $id ?>"
                                            >
                                                <div class="modal-contenido">
                                                    <header class="encabezado-modal-inventario">
                                                        <h2 id="titulo-historial-baja-<?= $id ?>">
                                                            Historial del equipo
                                                        </h2>
                                                        <button
                                                            type="button"
                                                            class="cerrar"
                                                            aria-label="Cerrar ventana"
                                                            onclick="cerrarModal('historial-baja-<?= $id ?>')"
                                                        >×</button>
                                                    </header>

                                                    <div class="equipo-modal">
                                                        <p><?= escaparListadoBajas(
                                                            $producto['descripcion']
                                                        ) ?></p>
                                                    </div>

                                                    <?php require __DIR__ . '/../modal/panel_historial.php' ?>
                                                </div>
                                            </dialog>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (!$listado['productos']): ?>
                                    <tr>
                                        <td colspan="8" class="estado-vacio">
                                            No se encontraron equipos dados de baja.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($listado['totalPaginas'] > 1): ?>
                        <nav class="paginacion" aria-label="Páginas de bajas">
                            <?php if($listado['pagina'] >1): ?>
                                <a 
                                    class="pagina-enlace"
                                    aria-label="Página anterior"
                                    href="<?= escaparListadoBajas(
                                        urlListadoBajas($filtros, $listado['pagina'] - 1)
                                    ) ?>"
                                >‹</a>
                            <?php endif; ?>

                            <?php for($numero = $inicioPaginas; $numero <= $finPaginas; $numero++): ?>
                                <a 
                                    class="pagina-enlace <?= $numero === $listado['pagina'] ? 'activa' : '' ?>"
                                    <?= $numero === $listado['pagina'] ? 'aria-current="page"' : '' ?>
                                    href="<?= escaparListadoBajas(
                                        urlListadoBajas($filtros, $numero)
                                    ) ?>"
                                ><?= $numero ?></a>
                            <?php endfor; ?>

                            <?php if($listado['pagina'] < $listado['totalPaginas']): ?>
                                <a 
                                    class="pagina-enlace"
                                    aria-label="Página siguiente"
                                    href="<?= escaparListadoBajas(
                                        urlListadoBajas($filtros, $listado['pagina'] + 1)
                                    ) ?>"
                                >›</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <script src="/sistemaInventario/admin/inventario/formulario.js"></script>
    
</body>
</html>

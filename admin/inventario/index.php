<?php
declare(strict_types=1);
require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/servicio.php';
require_once __DIR__ . '/recuperacion.php';
require_once __DIR__ . '/../productos/atributos_listado.php';

iniciarSesionSegura();
exigirAdministrador();

$_SESSION['csrf_inventario'] ??= bin2hex(random_bytes(32));
$conexion = require __DIR__ . '/../../config/conexion.php';
$filtros = filtrosInventario($_GET);
$listado = consultarInventario($conexion, $filtros);
$atributosInventario = consultarAtributosListadoProductos(
    $conexion,
    array_column($listado['productos'], 'id_producto')
);
$filtros = $listado['filtros'];
$filtros['pagina'] = $listado['pagina'];
$filtrosExportacion = $filtros;
unset($filtrosExportacion['pagina']);
$urlExportacion = '/sistemaInventario/administracion/inventario/exportable?'
    . http_build_query($filtrosExportacion);
$categorias = $conexion->query('SELECT id_categoria,nombre FROM categorias ORDER BY nombre')->fetchAll();
$areas = $conexion->query('SELECT id_area,nombre FROM areas ORDER BY nombre')->fetchAll();
$tokenResultado = $_GET['resultado'] ?? null;
$resultado = consultarResultadoInventario($tokenResultado);
$exito = $resultado['exito'] ?? '';
$recuperacion = $resultado['formulario'] ?? [];
$error = $recuperacion['error'] ?? '';
if ($resultado && !$recuperacion) {
    descartarResultadoInventario($tokenResultado, $resultado['id_producto']);
}
$equipoRecuperado = $recuperacion
    ? consultarEquipoRecuperacionInventario($conexion, (int) $recuperacion['id_producto'])
    : null;
$seccionActiva = 'inventario';
function escaparInventario(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

/** @return array<int|null> */
function paginasInventario(int $paginaActual, int $totalPaginas): array
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

function camposInventario(array $filtros, int $producto, ?string $tokenResultado = null): void
{
    if ($tokenResultado !== null) {
        echo '<input type="hidden" name="resultado" value="'
            . escaparInventario($tokenResultado) . '">';
    }
    echo '<input type="hidden" name="csrf_token" value="'
        . escaparInventario($_SESSION['csrf_inventario']) . '">';
    
    echo '<input type="hidden" name="id_producto" value="'
        . $producto . '">';

    foreach ($filtros as $clave => $valor) {
        if ($clave === 'categoria') {
            foreach ($valor as $categoria) {
                echo '<input type="hidden" name="categoria[]" value="'
                    . (int) $categoria . '">';
            }

            continue;
        }

        echo '<input type="hidden" name="'
            . escaparInventario($clave)
            . '" value="'
            . escaparInventario($valor)
            . '">';
    }
}

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inventario | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/inventario/inventario.css">
</head>
<body>
<div class="estructura-admin">
<?php require __DIR__ . '/../menu_lateral.php'; ?>
<div class="area-principal">
    <section class="encabezado-modulo">
        <h1>Inventario</h1>
        <?php require __DIR__ . '/../usuario_encabezado.php'; ?>
    </section>
    
    <div class="barra-navegacion">
        <a class="modulo-navegacion activo" href="/sistemaInventario/administracion/inventario" aria-current="page">Inventario</a>
        <a class="modulo-navegacion" href="/sistemaInventario/administracion/inventario/bajas">Bajas</a>
    </div>
    
    <main class="contenido-crud">
        <?php if ($exito): ?><div class="alerta exito" role="status"><?= escaparInventario($exito) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alerta error" role="alert"><?= escaparInventario($error) ?></div><?php endif; ?>
        <section class="tarjeta tabla-crud tarjeta-inventario">
            <div class="titulo-tarjeta encabezado-listado-inventario">
                <div>
                    <h2>Equipos y responsables</h2>
                    <p>Consulta los equipos y administra sus responsables actuales.</p>
                </div>
                <a class="boton exportar boton-exportar-excel" href="<?= escaparInventario($urlExportacion) ?>">
                    <svg class="icono-excel" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <path d="M14 2v6h6M8 12h8v6H8zM8 15h8M12 12v6" />
                    </svg>
                    <span>Generar Excel</span>
                </a>
            </div>
            <form method="get" class="formulario-crud filtros-inventario" id="filtros-inventario"
                action="/sistemaInventario/administracion/inventario"
            >
                <div class="campo-formulario">
                    <label for="filtro-codigo">Código</label>
                    <input 
                        type="search"
                        id="filtro-codigo"
                        name="codigo"
                        maxlength="100" 
                        placeholder="Ejemplo: PC001"
                        value="<?= escaparInventario($filtros['codigo']) ?>"    
                    >
                </div>

                <div class="campo-formulario">
                    <label for="filtro-usuario">Usuario</label>
                    <input id="filtro-usuario" name="usuario" maxlength="100"
                        placeholder="Nombre o cédula"
                        value="<?= escaparInventario($filtros['usuario']) ?>">
                </div>

                <fieldset class="filtro-categorias">
                    <legend>Categorías</legend>
                    <details class="desplegable-categorias">
                        <summary><?= $filtros['categoria'] ? count($filtros['categoria']) . ' seleccionada(s)' : 'Todas las categorías' ?></summary>
                    <div class="opciones-categorias">
                        <?php foreach ($categorias as $categoria):?>
                            <label class="opcion-categoria">
                                <input type="checkbox" name="categoria[]"
                                value="<?= (int) $categoria['id_categoria'] ?>"
                                <?= in_array(
                                    (int) $categoria['id_categoria'],
                                    $filtros['categoria'],
                                    true
                                ) ? 'checked' : '' ?>
                                >
                                <span><?= escaparInventario($categoria['nombre']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    </details>
                </fieldset>

                <div class="campo-formulario">
                    <label for="area">Área</label>

                    <select id="area" name="area">
                        <option value="">Todas</option>

                        <?php foreach ($areas as $area): ?>
                            <option
                                value="<?= (int) $area['id_area'] ?>"
                                <?= $filtros['area'] === (int) $area['id_area']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= escaparInventario($area['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php foreach([
                    'asignacion' => [
                        'Responsable',
                        [
                            'CON' => 'Con responsables.',
                            'SIN' => 'Sin responsables.',
                        ],
                    ],
                ] as $clave => [$etiqueta, $opciones]): ?>
                <div class="campo-formulario">
                    <label for="<?= $clave ?>"><?= $etiqueta ?></label>
                    <select id="<?= $clave ?>" name="<?= $clave ?>">
                        <option value="">Todos</option>
                    <?php foreach ($opciones as $valor => $texto): ?>
                        <option value="<?= $valor ?>" <?= $filtros[$clave] === $valor ? 'selected' : '' ?>><?= $texto ?></option>
                    <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
                
                
                <div class="acciones-formulario">
                    <button type="submit" class="boton primario">Buscar</button>

                    <a href="/sistemaInventario/administracion/inventario"
                        class="boton secundario"
                    >Limpiar</a>

                    
                </div>




            </form>
            <div class="titulo-tarjeta"><p><?= $listado['total'] ?> equipo(s) encontrado(s)</p></div>
            <div class="tabla-contenedor">
                
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Categoría</th>
                        <th>Área</th>
                        <th>Estado</th>
                        <th>Atributos</th>
                        <th>Responsable</th>
                        <th>Fecha Asignación</th>
                        <th>Asignación</th>
                    </tr>
                </thead>
                <tbody>
            <?php foreach ($listado['productos'] as $producto): $id = (int) $producto['id_producto']; $responsables = $listado['responsables'][$id] ?? []; ?>
                <?php
                $recuperar = ($recuperacion['id_producto'] ?? 0) === $id;
                $usuarioRecuperado = $recuperar ? ($recuperacion['id_usuario'] ?? 0) : 0;
                $textoRecuperado = $recuperar ? ($recuperacion['responsable_texto'] ?? '') : '';
                ?>
                <tr>
                    <td><?= escaparInventario($producto['codigo'] ?? 'Sin código vigente') ?>
                        <?php if ($producto['codigos_anteriores']): ?>
                            <br>
                            <small>Anteriores: <?= escaparInventario($producto['codigos_anteriores']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= escaparInventario($producto['descripcion']) ?>
                    </td>
                    <td><?= escaparInventario($producto['categoria']) ?></td>
                    <td><?= escaparInventario($producto['area']) ?></td>
                    
                    <td><?= $producto['estado'] === 'ACTIVO' ? 'Activo' : 'Dado de baja' ?></td>
                    <td>
                        <button type="button" class="ver-atributos-inventario"
                            onclick="abrirModal('atributos-<?= $id ?>')"
                            aria-label="Ver atributos de <?= escaparInventario($producto['descripcion']) ?>"
                            aria-haspopup="dialog" aria-expanded="false"
                            aria-controls="atributos-<?= $id ?>">Ver atributos</button>
                    </td>
                    <td>
                        <?php if ($producto['estado'] === 'INACTIVO'): ?>
                            No disponible
                        <?php else: ?>
                        <?php if (!$responsables): ?>Sin responsables<?php endif; ?>
                            <?php foreach ($responsables as $responsable): ?>
                            <div class="responsable-inventario">
                                <span><?= escaparInventario($responsable['nombre']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($producto['estado'] === 'INACTIVO' || !$responsables): ?>
                            —
                        <?php else: ?>
                            <?php foreach($responsables as $responsable): ?>
                                <div class="responsable-inventario">
                                    <span>
                                        <?= escaparInventario(
                                            (new DateTimeImmutable($responsable['fecha_asignacion']))
                                                ->format('d/m/Y H:i')
                                        ) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>

                    <!-- VENTANA MODAL PARA LA ADMINISTRACIÓN DE LAS ASIGNACIONES -->
                    <td>
                        <?php if ($producto['estado'] === 'ACTIVO'): ?>
                            <button
                                type="button"
                                onclick="abrirModal('modal-<?= $id ?>')" 
                                class="boton administracion" 
                                aria-haspopup="dialog"
                                aria-expanded="false"
                                aria-controls="modal-<?= $id ?>"
                            >Administrar</button>

                            <?php if (!$recuperar) require __DIR__ . '/modal/modal_asignaciones.php'; ?>
                            <?php else: ?>
                                <button type="button" class="boton administracion"
                                    onclick="abrirModal('historial-<?= $id ?>')"
                                    aria-haspopup="dialog" aria-expanded="false"
                                    aria-controls="historial-<?= $id ?>">Historial</button>
                                <dialog class="modal modal-inventario" id="historial-<?= $id ?>"
                                    aria-labelledby="titulo-historial-<?= $id ?>">
                                    <div class="modal-contenido">
                                        <header class="encabezado-modal-inventario">
                                            <h2 id="titulo-historial-<?= $id ?>">Historial del producto</h2>
                                            <button type="button" class="cerrar" aria-label="Cerrar ventana"
                                                onclick="cerrarModal('historial-<?= $id ?>')">×</button>
                                        </header>
                                        <div class="equipo-modal"><p><?= escaparInventario($producto['descripcion']) ?></p></div>
                                        <?php require __DIR__ . '/modal/panel_historial.php'; ?>
                                    </div>
                                </dialog>
                            <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

                <?php if (!$listado['productos']): ?>
                    <tr>
                        <td colspan="9" class="estado-vacio">No se encontraron equipos. Ajusta los filtros o registra un equipo en Productos.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
    </div>
            <?php if ($listado['totalPaginas'] > 1): ?>
                <nav class="paginacion" aria-label="Páginas del inventario">
                    <?php if ($listado['pagina'] > 1): ?>
                        <a class="pagina-enlace" aria-label="Página anterior" href="?<?= escaparInventario(http_build_query(array_replace($filtros, ['pagina' => $listado['pagina'] - 1]))) ?>">‹</a>
                    <?php endif; ?>
                    <?php foreach (paginasInventario($listado['pagina'], $listado['totalPaginas']) as $numeroPagina): ?>
                        <?php if ($numeroPagina === null): ?>
                            <span class="paginacion-elipsis" aria-hidden="true">…</span>
                        <?php else: ?>
                            <a class="pagina-enlace <?= $numeroPagina === $listado['pagina'] ? 'activa' : '' ?>"
                                aria-label="Página <?= $numeroPagina ?>"
                                <?= $numeroPagina === $listado['pagina'] ? 'aria-current="page"' : '' ?>
                                href="?<?= escaparInventario(http_build_query(array_replace($filtros, ['pagina' => $numeroPagina]))) ?>"><?= $numeroPagina ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($listado['pagina'] < $listado['totalPaginas']): ?>
                        <a class="pagina-enlace" aria-label="Página siguiente" href="?<?= escaparInventario(http_build_query(array_replace($filtros, ['pagina' => $listado['pagina'] + 1]))) ?>">›</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>
</div>
</div>
<div data-dialogos-paginacion>
<?php foreach ($listado['productos'] as $producto): $id = (int) $producto['id_producto']; ?>
    <?php require __DIR__ . '/modal/modal_atributos.php'; ?>
<?php endforeach; ?>
<?php if ($recuperacion): ?>
    <?php if ($equipoRecuperado && $equipoRecuperado['producto']['estado'] === 'ACTIVO'): ?>
        <?php
        $producto = $equipoRecuperado['producto'];
        $id = (int) $producto['id_producto'];
        $responsables = $equipoRecuperado['responsables'];
        $recuperar = true;
        $usuarioRecuperado = $recuperacion['id_usuario'] ?? 0;
        $textoRecuperado = $recuperacion['responsable_texto'] ?? '';
        require __DIR__ . '/modal/modal_asignaciones.php';
        descartarResultadoInventario($tokenResultado, $id);
        ?>
    <?php else: ?>
        <dialog class="modal modal-inventario" id="modal-recuperacion-no-disponible"
            aria-labelledby="titulo-recuperacion-no-disponible" data-recuperar-formulario>
            <div class="modal-contenido">
                <h2 id="titulo-recuperacion-no-disponible">El equipo ya no está disponible</h2>
                <p>No se puede reintentar la operación porque el equipo fue dado de baja o ya no existe. Conservamos el texto que escribiste.</p>
                <label for="texto-recuperado">Responsable ingresado</label>
                <input id="texto-recuperado" readonly value="<?= escaparInventario($recuperacion['responsable_texto'] ?? '') ?>">
                <form method="post" action="/sistemaInventario/administracion/inventario/procesar">
                    <?php camposInventario($filtros, (int) $recuperacion['id_producto'], $tokenResultado); ?>
                    <input type="hidden" name="accion" value="descartar_recuperacion">
                    <button class="boton secundario" type="button" onclick="cerrarModal('modal-recuperacion-no-disponible')">Cerrar y conservar</button>
                    <button class="boton primario" type="submit">Descartar datos</button>
                </form>
            </div>
        </dialog>
    <?php endif; ?>
<?php endif; ?>
</div>
<script src="/sistemaInventario/admin/inventario/formulario.js"></script>
</body>
</html>

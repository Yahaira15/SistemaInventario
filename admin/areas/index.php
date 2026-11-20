<?php

declare(strict_types=1);
require_once __DIR__ . '/../../auth/busqueda.php';

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

if (empty($_SESSION['csrf_areas'])) {
    $_SESSION['csrf_areas'] = bin2hex(random_bytes(32));
}

$conexion = require __DIR__ . '/../../config/conexion.php';

function urlPagina(int $pagina, array $parametrosUrl): string
{
    $parametrosUrl['pagina'] = $pagina;

    return '/sistemaInventario/administracion/areas?'
        . http_build_query($parametrosUrl);
}

/** @return array<int|null> */
function paginasPaginacion(int $paginaActual, int $totalPaginas): array
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

// Recupera los mensajes generados por las operaciones POST.
$mensajeExito = (string) ($_SESSION['mensajeExito'] ?? '');
$mensajeError = (string) ($_SESSION['mensajeError'] ?? '');
unset($_SESSION['mensajeExito'], $_SESSION['mensajeError']);

// Carga el usuario solicitado cuando la pantalla está en modo edición.
$areaEditar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

if ($idEditar !== null && $idEditar !== false) {
    $consultaAreaEditar = $conexion->prepare(
        'SELECT id_area, nombre, estado
         FROM areas
         WHERE id_area = :id_area
         LIMIT 1'
    );
    $consultaAreaEditar->execute(['id_area' => $idEditar]);
    $areaEditar = $consultaAreaEditar->fetch();

    if (!$areaEditar) {
        http_response_code(404);
        exit('Area no encontrada.');
    }
}

// Recibe la búsqueda, los filtros y la página solicitada.
$buscar = trim(textoEntrada($_GET, 'buscar'));
$estadoFiltro = strtoupper(trim(textoEntrada($_GET, 'estado')));
$porPagina = 15;
$paginaActual = filter_input(
    INPUT_GET,
    'pagina',
    FILTER_VALIDATE_INT
);
if (!$paginaActual || $paginaActual < 1) {
    $paginaActual = 1;
}

// Normaliza y valida los valores recibidos desde la URL.
if (mb_strlen($buscar) > 100) {
    $buscar = mb_substr($buscar, 0, 100);
}

if (!in_array($estadoFiltro, ['', 'ACTIVO', 'INACTIVO'], true)) {
    $estadoFiltro = '';
}

$condiciones = [];
$parametros = [];

if ($buscar !== '') {
    $terminoBusqueda = patronBusqueda($buscar);

    $condiciones[] = '
    (
        nombre LIKE :buscar_nombre ESCAPE "!"

    )';

    $parametros['buscar_nombre'] = $terminoBusqueda;

}

if ($estadoFiltro !== '') {
    $condiciones[] = 'estado = :filtro_estado';
    $parametros['filtro_estado'] = $estadoFiltro;
}

$whereSql = '';

if ($condiciones !== []) {
    $whereSql = ' WHERE ' . implode(' AND ', $condiciones);
}

// Cuenta todas las áreas que cumplen la búsqueda y los filtros.
$sqlTotal = '
    SELECT COUNT(*)
    FROM areas
' . $whereSql;

$consultaTotal = $conexion->prepare($sqlTotal);
$consultaTotal->execute($parametros);

$totalAreas = (int) $consultaTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalAreas / $porPagina));

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset = ($paginaActual - 1) * $porPagina;

// Obtiene solamente las áreas correspondientes a la página actual.
$sqlArea = '
    SELECT id_area, nombre, estado
    FROM areas
' . $whereSql . '
    ORDER BY nombre ASC, id_area DESC
    LIMIT :limite OFFSET :offset
';

$consulta = $conexion->prepare($sqlArea);

foreach ($parametros as $nombre => $valor) {
    $consulta->bindValue(
        ':' . $nombre,
        $valor,
        PDO::PARAM_STR
    );
}

$consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$consulta->bindValue(':offset', $offset, PDO::PARAM_INT);
$consulta->execute();

$areas = $consulta->fetchAll();
$estaEditando = $areaEditar !== null;
require_once __DIR__ . '/../../auth/formularios.php';
$datosRecuperados = recuperarFormulario('areas', (int) ($areaEditar['id_area'] ?? 0), []);
$datosFormulario = array_replace($areaEditar ?? [], $datosRecuperados);
$formularioVisible = $estaEditando || isset($_GET['crear']);
$seccionActiva = 'areas';
$hayFiltros = $buscar !== '' || $estadoFiltro !== '';

// Conserva los filtros cuando el usuario cambia de página.
$parametrosUrl = [];

if ($buscar !== '') {
    $parametrosUrl['buscar'] = $buscar;
}

if ($estadoFiltro !== '') {
    $parametrosUrl['estado'] = $estadoFiltro;
}

?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Áreas | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
</head>
<body>
    <div class="estructura-admin">
        <?php require __DIR__ . '/../menu_lateral.php'?>
        <div class="area-principal">
        <section class="encabezado-modulo">
                <h1>Áreas</h1>
                <?php require __DIR__ . '/../usuario_encabezado.php'; ?>
            </section>

            <div class="barra-navegacion">
                <a class="modulo-navegacion" href="/sistemaInventario/administracion/usuarios">Usuarios</a>
                <a class="modulo-navegacion activo" href="/sistemaInventario/administracion/areas">Áreas</a>
            </div>

            <main class="contenido-crud">
                
                <?php if ($mensajeExito !== ''): ?>
                    <div class="alerta exito" role="status"><?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($mensajeError !== ''): ?>
                    <div class="alerta error" role="alert"><?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="column">
                    <section class="tarjeta formulario-area-desplegable" id="formulario-area" <?= $formularioVisible ? '' : 'hidden' ?>>
                        <div class="titulo-tarjeta">
                            <h2><?= $estaEditando ? 'Editar área' : 'Crear área' ?></h2>
                            <p><?= $estaEditando ? 'Actualiza la información' : 'Registra el nombre del área' ?></p>
                        </div>
                        <form class="formulario-crud" action="/sistemaInventario/administracion/areas/procesar" method="post"
                            <?= $estaEditando ? 'data-confirmar="¿Está seguro de guardar los cambios de esta área?"' : '' ?>>
                            <input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('areas', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_areas'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="accion" value="<?= $estaEditando ? 'actualizar' : 'crear' ?>">

                            <?php if ($estaEditando): ?>
                                <input type="hidden" name="id_area" value="<?= (int) $datosFormulario['id_area'] ?>">
                            <?php endif; ?>

                            <div class="container">
                                <div class="columna-formulario">
                                    <div class="campo-formulario">
                                        <label for="nombre-area">Nombre del área</label>
                                        <input type="text" id="nombre-area" name="nombre" minlength="2" maxlength="80"
                                            autocomplete="organization"
                                            value="<?= htmlspecialchars($datosFormulario['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>
                            

                            <?php if ($estaEditando): ?>
                                <div class="columna-formulario">
                                    <div class="campo-formulario">
                                        <label for="estado">Estado</label>
                                        <select name="estado" id="estado" required>
                                            <option value="ACTIVO" <?= $datosFormulario['estado'] === 'ACTIVO' ? 'selected' : '' ?>>Activo</option>
                                            <option value="INACTIVO" <?= $datosFormulario['estado'] === 'INACTIVO' ? 'selected' : '' ?>>Inactivo</option>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>
                            </div>

                            <div class="container">
                                <div class="acciones-formulario">
                                    <button class="boton primario" type="submit">Guardar área</button>
                                    <?php if ($estaEditando): ?>
                                        <a class="boton secundario" href="<?= htmlspecialchars(urlContextoListado('areas'), ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                                    <?php else: ?>
                                        <a class="boton secundario" id="boton-cancelar-area"
                                            href="<?= htmlspecialchars(urlContextoListado('areas'), ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </form>
                    </section>
                    <section class="tarjeta tabla-crud tabla-areas">
                        <div class="contenedor-desplegable encabezado-listado-areas">
                            <div class="titulo-tarjeta">
                                <h2>Áreas registradas</h2>
                                <p>Consulta, edita, activa o desactiva áreas</p> 
                                <span class="contador">   
                                <?= $totalAreas ?>
                                <?= $hayFiltros ? 'resultados' : 'registrados' ?>
                                </span>
                            </div>
                            
                            <div class="boton-desplegable">
                                <a class="boton boton-agregar-area" id="boton-crear-area"
                                    href="<?= $formularioVisible
                                        ? '/sistemaInventario/administracion/areas'
                                        : '/sistemaInventario/administracion/areas?crear=1' ?>"
                                    aria-controls="formulario-area" aria-expanded="<?= $formularioVisible ? 'true' : 'false' ?>">
                                    <span aria-hidden="true"><?= $formularioVisible ? '×' : '+' ?></span>
                                    <?= $formularioVisible ? 'Cerrar' : 'Crear' ?>
                                </a>
                            </div>
                        </div>

                        <div class="buscador-crud">

                            <form method="get" action="/sistemaInventario/administracion/areas" class="buscador">

                                <label class="visualmente-oculto" for="buscar">Buscar</label>

                                <input type="serach" name="buscar" id="buscar"  maxlength="100"
                                placeholder="Buscar nombre del área"
                                value="<?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?>">

                                <div>
                                    <label for="filtro-estado">Estado</label>
                                    <select name="estado" id="filtro-estado">
                                        <option value="">Todos los estados</option>

                                        <option value="ACTIVO"
                                            <?= $estadoFiltro === 'ACTIVO' ? 'selected' : '' ?>
                                        >
                                            Activo
                                        </option>
                                        
                                        <option value="INACTIVO"
                                            <?= $estadoFiltro === 'INACTIVO' ? 'selected' : '' ?>
                                        >
                                            Inactivo
                                        </option>
                                    </select>
                                </div>

                                <button type="submit" class="boton primario">Buscar</button>
                                
                                <?php if ($buscar !== '' || $estadoFiltro !== ''): ?>
                                    <a href="/sistemaInventario/administracion/areas" class="boton secundario">limpiar</a>
                                <?php endif; ?>
                            </form>
                        </div>
                        
                        <?php if ($areas === []): ?>

                            <?php if ($hayFiltros): ?>
                                <div class="estado-vacio">No se encontraron áreas con los filtros seleccionados</div>
                            <?php else: ?>
                                <div class="estado-vacio">
                                    Todavía no hay áreas registradas.
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="tabla-contenedor">
                                <table>
                                    <caption class="visualmente-oculto">
                                        Listado de áreas registradas con su estado y acciones disponibles.
                                    </caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Área</th>
                                            <th scope="col">Estado</th>
                                            <th scope="col">Acciones</th>
                                        </tr>
                                    </thead>

                                    
                                    <tbody>
                                        <?php foreach ($areas as $area): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($area['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                            
                                                <td>
                                                    <span class="estado <?= strtolower($area['estado']) ?>">
                                                        <?= htmlspecialchars($area['estado'], ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="acciones-tabla">
                                                        <a href="<?= htmlspecialchars(urlContextoListado('areas', ['editar' => (int) $area['id_area']]), ENT_QUOTES, 'UTF-8') ?>" class="boton-tabla editar">Editar</a>
                                                        <form action="/sistemaInventario/administracion/areas/procesar" method="post" data-confirmar="¿Está seguro que desea cambiar el estado?">
<input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('areas', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_areas'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="cambiar_estado">
                                                            <input type="hidden" name="id_area" value="<?= (int) $area['id_area'] ?>">
                                                            <input type="hidden" name="estado" value="<?= $area['estado'] === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO' ?>">
                                                            <button class="boton-tabla" type="submit">
                                                                <?= $area['estado'] === 'ACTIVO' ? 'Desactivar' : 'Activar' ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if ($totalPaginas > 1): ?>
                            <nav class="paginacion" aria-label="Páginas">
                                <?php if ($paginaActual > 1): ?>
                                    <a href="<?= htmlspecialchars(
                                        urlPagina($paginaActual - 1, $parametrosUrl), ENT_QUOTES, 'UTF-8'
                                    ) ?>" class="pagina-enlace" aria-label="Página anterior">‹</a>
                                <?php endif; ?>

                                <?php foreach (paginasPaginacion($paginaActual, $totalPaginas) as $pagina): ?>
                                    <?php if ($pagina === null): ?>
                                        <span class="paginacion-elipsis" aria-hidden="true">…</span>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars(
                                            urlPagina($pagina, $parametrosUrl), ENT_QUOTES, 'UTF-8'
                                        ) ?>"
                                        class="pagina-enlace <?= $pagina === $paginaActual ? 'activa' : '' ?>"
                                        aria-label="Página <?= $pagina ?>"
                                        <?= $pagina === $paginaActual ? 'aria-current="page"' : '' ?>
                                        ><?= $pagina ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <?php if ($paginaActual < $totalPaginas): ?>
                                    <a href="<?= htmlspecialchars(
                                        urlPagina($paginaActual + 1, $parametrosUrl), ENT_QUOTES, 'UTF-8') ?>"
                                    class="pagina-enlace" aria-label="Página siguiente">›</a>

                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </section>
                </div>
            </main>
        </div>
    </div>
    <script type="application/json" id="config-areas"><?= json_encode([
        'estaEditando' => $estaEditando,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <script src="/sistemaInventario/admin/areas/formulario.js"></script>
    <script src="/sistemaInventario/admin/js/confirmaciones.js"></script>
</body>
</html>

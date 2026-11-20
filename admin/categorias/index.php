<?php

declare(strict_types=1);
require_once __DIR__ . '/../../auth/busqueda.php';

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

if (empty($_SESSION['csrf_categorias'])) {
    $_SESSION['csrf_categorias'] = bin2hex(random_bytes(32));
}

$conexion = require __DIR__ . '/../../config/conexion.php';

function urlPagina(int $pagina, array $parametrosUrl): string
{
    $parametrosUrl['pagina'] = $pagina;

    return '/sistemaInventario/administracion/categorias?'
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

// Carga la categoría cuando la pantalla esta en modo edición
$categoriaEditar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

if ($idEditar !== null && $idEditar !== false) {
    $consultaCategoriaEditar = $conexion->prepare(
        'SELECT id_categoria, nombre, descripcion, estado, prefijo_codigo
         FROM categorias
         WHERE id_categoria = :id_categoria
         LIMIT 1'
    );
    $consultaCategoriaEditar->execute(['id_categoria' => $idEditar]);
    $categoriaEditar = $consultaCategoriaEditar->fetch();

    if (!$categoriaEditar) {
        http_response_code(404);
        exit('Categoría no encontrada.');
    }
}

// Recibe la busqueda, los filtros y la página solicitada
$buscar = trim(textoEntrada($_GET, 'buscar'));
$estadoFiltro = strtoupper(trim(textoEntrada($_GET, 'estado')));
$porPagina = 6;
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

// Construye las condiciones compartidas por el conteo y el lisado.
$condiciones = [];
$parametros = [];

if ($buscar !== '') {
    $terminoBusqueda = patronBusqueda($buscar);

    $condiciones[] = '
        (
            nombre LIKE :buscar_nombre ESCAPE "!"
            OR descripcion LIKE :buscar_descripcion ESCAPE "!"
        )
    ';

    $parametros['buscar_nombre'] = $terminoBusqueda;
    $parametros['buscar_descripcion'] = $terminoBusqueda;
}

if ($estadoFiltro !== '') {
    $condiciones[] = 'estado = :filtro_estado';
    $parametros['filtro_estado'] = $estadoFiltro;
}

$whereSql = '';

if ($condiciones !== []) {
    $whereSql = ' WHERE ' . implode(' AND ', $condiciones);
}

// Cuenta todos los usuarios que cumplen la búsqueda y los filtros.
$sqlTotal = '
    SELECT COUNT(*)
    FROM categorias
' . $whereSql;

$consultaTotal = $conexion->prepare($sqlTotal);
$consultaTotal->execute($parametros);

$totalCategorias = (int) $consultaTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalCategorias / $porPagina));

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset = ($paginaActual - 1) * $porPagina;

// Obtiene solamente las categorias correspondientes a la página actual.
$sqlCategorias = '
    SELECT id_categoria, nombre,
            descripcion, estado
    FROM categorias
' . $whereSql . '
    ORDER BY fecha_actualizacion DESC, id_categoria DESC
    LIMIT :limite OFFSET :offset
';

$consulta = $conexion->prepare($sqlCategorias);

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

$categorias = $consulta->fetchAll();
$estaEditando = $categoriaEditar !== null;
require_once __DIR__ . '/../../auth/formularios.php';
$datosRecuperados = recuperarFormulario('categorias', (int) ($categoriaEditar['id_categoria'] ?? 0), []);
$datosFormulario = array_replace($categoriaEditar ?? [], $datosRecuperados);
$seccionActiva = 'categorias';
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Categorías | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
</head>
<body>
    <div class="estructura-admin">
        <?php require __DIR__ . '/../menu_lateral.php'; ?>

        <div class="area-principal">
<section class="encabezado-modulo">
                <div>
                    <h1>Categorías</h1>
                </div>
                
                <?php require __DIR__ . '/../usuario_encabezado.php'; ?>
            </section>

            <div class="barra-navegacion">
                <a class="modulo-navegacion activo" href="/sistemaInventario/administracion/categorias" aria-current="page">Categorías</a>
                <a class="modulo-navegacion" href="/sistemaInventario/administracion/atributos">Atributos</a>
            </div>
            
            <main class="contenido-crud">
                <?php if ($mensajeExito !== ''): ?>
                    <div class="alerta exito" role="status">
                        <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($mensajeError !== ''): ?>
                    <div class="alerta error" role="alert">
                        <?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <div class="column">
                    <section class="tarjeta">
                        <div class="titulo-tarjeta">
                            <h2><?= $estaEditando ? 'Editar categoría' : 'Nueva categoría' ?></h2>
                            <p><?= $estaEditando ? 'Actualiza los datos seleccionados.' : 'Registra una categoría para clasificar productos.' ?></p>
                        </div>

                        <form action="/sistemaInventario/administracion/categorias/procesar" method="post" class="formulario-crud">
<input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('categorias', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_categorias'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="accion" value="<?= $estaEditando ? 'actualizar' : 'crear' ?>">
                            

                            <?php if ($estaEditando): ?>
                                <input type="hidden" name="id_categoria" value="<?= (int) $datosFormulario['id_categoria'] ?>">
                            <?php endif; ?>
                            <div class="container">
                                <div class="columna-formulario">
                                    <div class="campo-formulario">
                                        <label for="nombre">Nombre</label>
                                        <input type="text" id="nombre" name="nombre" minlength="2"  maxlength="80"
                                            value="<?= htmlspecialchars($datosFormulario['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            required
                                            autofocus
                                        >
                                    </div>

                                    <div class="campo-formulario">
                                        <label for="prefijo_codigo">Prefijo de código (opcional)</label>
                                        <input type="text" id="prefijo_codigo" name="prefijo_codigo"
                                            maxlength="20" placeholder="Ejemplo: ADP-PC"
                                            aria-describedby="ayuda-prefijo"
                                            value="<?= htmlspecialchars($datosFormulario['prefijo_codigo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <small id="ayuda-prefijo">Necesario para generar códigos automáticamente.</small>
                                    </div>

                                    <div class="campo-formulario">
                                        <?php if ($estaEditando): ?>
                                            <label for="estado">Estado</label>
                                            <select name="estado" id="estado" required>
                                                <option value="ACTIVO" <?= $datosFormulario['estado'] === 'ACTIVO' ? 'selected' : '' ?>>Activo</option>
                                                <option value="INACTIVO" <?= $datosFormulario['estado'] === 'INACTIVO' ? 'selected' : '' ?>>Inactivo</option>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="columna-formulario">
                                    <div class="campo-formulario">
                                        <label for="descripcion">Descripción</label>
                                        <textarea id="descripcion" name="descripcion" maxlength="120" rows="4" required><?= htmlspecialchars($datosFormulario['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="container">
                                <div class="acciones-formulario">
                                    <button class="boton primario" type="submit">
                                        <?= $estaEditando ? 'Guardar cambios' : 'Crear categoría' ?>
                                    </button>

                                    <?php if ($estaEditando): ?>
                                        <a class="boton secundario" href="<?= htmlspecialchars(urlContextoListado('categorias'), ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                                    <?php endif; ?>
                                </div>
                        </div>
                        </form>
                    </section>

                    <section class="tarjeta tabla-crud">
                        <div class="titulo-tarjeta">
                            <h2>Categorías registradas</h2>
                            <p>Desde aquí puedes editar, activar o desactivar registros.</p>
                            <span class="contador">
                                <?= $totalCategorias?>
                                <?= $hayFiltros ? 'resultado' : 'registrados' ?>
                            </span>
                        </div>
                        <div class="buscador-crud">
                            <form method="get" action="/sistemaInventario/administracion/categorias" class="buscador">
                                <label class="visualmente-oculto" for="buscar">Buscar categorías</label>
                                <input type="search" id="buscar" name="buscar" maxlength="100"
                                placeholder="Buscar categoría"
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
                                    <a href="/sistemaInventario/administracion/categorias" class="boton secundario">
                                        Limpiar
                                    </a>
                                <?php endif; ?>
                            </form>
                         </div>

                        <?php if ($categorias === []): ?>
                            <?php if ($hayFiltros): ?>
                                <div class="estado-vacio">No se encotraron categorias con los filtros seleccionados.</div>
                            <?php else: ?>
                                <div class="estado-vacio">Todavía no hay categorías registradas.</div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="tabla-contenedor">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>Descripción</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categorias as $categoria): ?>
                                            <tr>
                                                
                                                <td class="nombre-categoria"><?= htmlspecialchars($categoria['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($categoria['descripcion'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <span class="estado <?= strtolower($categoria['estado']) ?>">
                                                        <?= htmlspecialchars($categoria['estado'], ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="acciones-tabla">
                                                        <a class="boton-tabla editar" href="<?= htmlspecialchars(urlContextoListado('categorias', ['editar' => (int) $categoria['id_categoria']]), ENT_QUOTES, 'UTF-8') ?>">Editar</a>

                                                        <form action="/sistemaInventario/administracion/categorias/procesar" method="post" data-confirmar="¿Está seguro que desea cambiar el estado?">
<input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('categorias', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_categorias'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="cambiar_estado">
                                                            <input type="hidden" name="id_categoria" value="<?= (int) $categoria['id_categoria'] ?>">
                                                            <input type="hidden" name="estado" value="<?= $categoria['estado'] === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO' ?>">
                                                            <button class="boton-tabla estado-boton" type="submit">
                                                                <?= $categoria['estado'] === 'ACTIVO' ? 'Desactivar' : 'Activar' ?>
                                                            </button>
                                                        </form>

                                                        <a class="boton-tabla atributos" href="/sistemaInventario/administracion/categorias/atributos?categoria=<?= (int) $categoria['id_categoria'] ?>">Atributos</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPaginas > 1): ?>
                            <nav class="paginacion" aria-label="Páginas">
                                <?php if ($paginaActual > 1): ?>
                                    <a href="<?= htmlspecialchars(
                                        urlPagina($paginaActual - 1, $parametrosUrl), ENT_QUOTES, 'UTF-8'
                                    ) ?>" class="pagina-enlace" >‹</a>
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
                                    class="pagina-enlace">›</a>

                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>

                        <?php endif; ?>
                    </section>
                </div>
            </main>
        </div>
    </div>
    <script src="/sistemaInventario/admin/js/confirmaciones.js"></script>
</body>
</html>

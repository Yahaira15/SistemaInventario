<?php

declare(strict_types=1);
require_once __DIR__ . '/../../auth/busqueda.php';

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/../../auth/formularios.php';
require_once __DIR__ . '/servicio.php';

iniciarSesionSegura();
exigirAdministrador();

if (empty($_SESSION['csrf_productos'])) {
    $_SESSION['csrf_productos'] = bin2hex(random_bytes(32));
}

$conexion = require __DIR__ . '/../../config/conexion.php';

function urlProducto(int $pagina, array $parametros): string
{
    $parametros['pagina'] = $pagina;
    return '/sistemaInventario/administracion/productos?' . http_build_query($parametros);
}

/** @return array<int|null> */
function paginasProducto(int $paginaActual, int $totalPaginas): array
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
    $paginaAnterior = null;

    foreach ($paginas as $pagina) {
        if ($paginaAnterior !== null && $pagina > $paginaAnterior + 1) {
            $resultado[] = null;
        }
        $resultado[] = $pagina;
        $paginaAnterior = $pagina;
    }

    return $resultado;
}

$mensajeExito = (string) ($_SESSION['mensajeExito'] ?? '');
$mensajeError = (string) ($_SESSION['mensajeError'] ?? '');
unset($_SESSION['mensajeExito'], $_SESSION['mensajeError']);

$productoEditar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

if ($idEditar) {
    $productoEditar = consultarProducto($conexion, (int) $idEditar);
    
    if (!$productoEditar) {
        http_response_code(404);
        exit('Producto no encontrado.');
    }
    if ($productoEditar['estado'] === 'INACTIVO') {
        header('Location: /sistemaInventario/administracion/bajas?id=' . (int)$idEditar);
        exit;
    }
}

$estaEditando = $productoEditar !== null;
$datosRecuperados = recuperarFormulario('productos', (int) ($idEditar ?: 0));
$datosFormulario = array_replace($productoEditar ?: [], $datosRecuperados);
$atributosRecuperados = $datosRecuperados['atributos'] ?? [];
$tokenCreacion = $datosRecuperados['token_creacion'] ?? '';
if (!is_string($tokenCreacion) || !preg_match('/^[a-f0-9]{64}$/D', $tokenCreacion)) {
    $tokenCreacion = bin2hex(random_bytes(32));
}

$categoriaActual = (int) ($datosFormulario['id_categoria'] ?? 0);
if ($estaEditando) {
    $categoriaActual = (int) $productoEditar['id_categoria'];
}

$consultaCategorias = $conexion->prepare(
    'SELECT id_categoria, nombre, estado, prefijo_codigo, ultimo_consecutivo FROM categorias
     WHERE estado = "ACTIVO" OR id_categoria = :id ORDER BY nombre'
);
$consultaCategorias->execute(['id' => $categoriaActual]);
$categoriasFormulario = $consultaCategorias->fetchAll();
$categorias = $conexion->query(
    'SELECT id_categoria, nombre FROM categorias ORDER BY nombre'
)->fetchAll();

$buscar = trim(textoEntrada($_GET, 'buscar'));
if (mb_strlen($buscar) > 100) {
    $buscar = mb_substr($buscar, 0, 100);
}

$categoriasSeleccionadas = normalizarCategoriasFiltro($_GET['categoria'] ?? []);
$idsCategorias = array_map('intval', array_column($categorias, 'id_categoria'));
$categoriasSeleccionadas = array_values(array_intersect($categoriasSeleccionadas, $idsCategorias));
// Una categoría conserva la paginación; varias muestran un resumen por grupo.
$categoriaFiltro = count($categoriasSeleccionadas) === 1 ? $categoriasSeleccionadas[0] : null;
$estadoFiltro = strtoupper(trim(textoEntrada($_GET, 'estado')));
if (!in_array($estadoFiltro, ['', 'ACTIVO', 'INACTIVO'], true)) {
    $estadoFiltro = '';
}

// Obtiene de la tabla de áreas las opciones del formulario y del filtro.
$consultaAreasFormulario = $conexion->prepare(
    'SELECT id_area, nombre
     FROM areas
     WHERE estado = "ACTIVO"
        OR id_area = :area_actual
     ORDER BY nombre ASC'
);
$consultaAreasFormulario->execute([
    'area_actual' => (int) ($datosFormulario['id_area'] ?? 0),
]);
$areasFormulario = $consultaAreasFormulario->fetchAll();

$gruposProductos = [];
$total = 0;
$idsProductosVisibles = [];

$totalesCategorias = consultarTotalesProductosPorCategoria(
    $conexion,
    $buscar,
    $categoriaFiltro,
    $estadoFiltro
);

$columnasPorCategoria = consultarColumnasProductosPorCategoria(
    $conexion,
    array_keys($totalesCategorias)
);
foreach ($categorias as $categoriaListado) {
    $idCategoriaListado = (int) $categoriaListado['id_categoria'];
    if ($categoriasSeleccionadas && !in_array($idCategoriaListado, $categoriasSeleccionadas, true)) continue;
    $totalCategoria = $totalesCategorias[$idCategoriaListado] ?? 0;

    if ($totalCategoria === 0) {
        continue;
    }

    $listadoCategoria = consultarListadoProductos(
        $conexion,
        $buscar, 
        $idCategoriaListado, 
        $estadoFiltro,
        $categoriaFiltro ? (filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1) : 1,
        $categoriaFiltro ? 10 : 8,
        $totalCategoria
    );
    $total += $listadoCategoria['total'];
    $idsProductosVisibles = array_merge($idsProductosVisibles, array_column($listadoCategoria['productos'], 'id_producto'));
    $gruposProductos[] = [
        'categoria' => $categoriaListado, 'listado' => $listadoCategoria,
        'columnas' => $columnasPorCategoria[$idCategoriaListado] ?? [],
    ];
}
$atributosProductos = consultarAtributosListadoProductos($conexion, $idsProductosVisibles);
$formularioVisible = $estaEditando || isset($_GET['crear']) || $datosRecuperados !== [];
$query = [];
if ($buscar !== '') {
    $query['buscar'] = $buscar;
}
if ($categoriasSeleccionadas) {
    $query['categoria'] = $categoriasSeleccionadas;
}
if ($estadoFiltro !== '') {
    $query['estado'] = $estadoFiltro;
}
$hayFiltros = $query !== [];
$seccionActiva = 'productos';
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Productos | Sistema de Inventario</title>
        <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
        <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
        <link rel="stylesheet" href="/sistemaInventario/admin/productos/bajas/modal.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/productos/productos.css">
    </head>
    <body>
        <div class="estructura-admin"><?php require __DIR__ . '/../menu_lateral.php';?>
        <div class="area-principal">
            <section class="encabezado-modulo">
                <h1>Administración de productos</h1>
                <?php require __DIR__ . '/../usuario_encabezado.php'; ?>
            </section>
            <main class="contenido-crud">
                <?php if ($mensajeExito !== ''):?>
                    <div class="alerta exito" role="status">
                        <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8')?>
                    </div>
                <?php endif;if ($mensajeError !== ''):?>
                    <div class="alerta error" role="alert">
                        <?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8')?>
                    </div>
                <?php endif;?>

                <div class="column">
                    <section id="formulario-desplegable" class="tarjeta formulario-productos" <?= $formularioVisible ? '' : 'hidden' ?>>
                        <div class="titulo-tarjeta">
                            <h2><?= $estaEditando?'Editar producto':'Nuevo producto'?></h2>
                            <p>Completa los datos básicos del producto.</p>
                        </div>

                        <?php if (!$categoriasFormulario):?>
                            <div class="estado-vacio">Debes crear una categoría activa antes de registrar productos.</div>
                        <?php else:?>
                            <form class="formulario-crud" method="post" action="/sistemaInventario/administracion/productos/procesar">
                                <input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('productos', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_productos'], ENT_QUOTES, 'UTF-8') ?>">

                                <input type="hidden" name="accion" value="<?= $estaEditando?'actualizar':'crear'?>">
                                <?php if (!$estaEditando): ?>
                                    <input type="hidden" name="token_creacion" value="<?= htmlspecialchars($tokenCreacion, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                                
                                <?php if ($estaEditando):?>
                                    <input type="hidden" name="id_producto" value="<?= (int) $productoEditar['id_producto'] ?>">
                                    <input type="hidden" name="version" value="<?= (int) $datosFormulario['version'] ?>">
                                <?php endif;?>

                                <div class="container">
                                    <div class="columna-formulario">
                                        <div class="campo-formulario">
                                            <label for="categoria">Categoría</label>
                                            <?php if ($estaEditando): ?>
                                                <input type="hidden" name="id_categoria" value="<?= (int) $productoEditar['id_categoria'] ?>">
                                            <?php endif; ?>
                                            <select id="categoria" name="id_categoria" required <?= $estaEditando ? 'disabled' : '' ?>>
                                                <option value="" disabled <?= $categoriaActual === 0?'selected':''?>>Selecciona una categoría</option>
                                               
                                                <?php foreach ($categoriasFormulario as $cat):?>
                                                    <option value="<?= (int)$cat['id_categoria']?>" data-generable="<?= $cat['estado'] === 'ACTIVO' && trim((string) $cat['prefijo_codigo']) !== '' && (int) $cat['ultimo_consecutivo'] < 4294967295 ? '1' : '0' ?>"
                                                    data-codigo-estimado="<?= htmlspecialchars(mb_strtoupper(trim((string) $cat['prefijo_codigo']), 'UTF-8') . str_pad((string) ((int) $cat['ultimo_consecutivo'] + 1), 3, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?>"
                                                    <?= $categoriaActual === (int)$cat['id_categoria']?'selected':''?>>
                                                    <?= htmlspecialchars($cat['nombre'], ENT_QUOTES, 'UTF-8')?>
                                                    <?= $cat['estado'] === 'INACTIVO'?' (Inactiva)':''?></option>
                                                <?php endforeach;?>
                                            </select>
                                        </div>
                                        
                                        <div class="campo-formulario selector-codigos" id="selector-codigos">
                                            <label for="buscar-codigo">Código</label>
                                            <!-- ESTE IDENTIFICADOR ES EL QUE SE ENVIARA AL GUARDAR  -->
                                             <input type="hidden" name="id_codigo" id="id-codigo" value="">
                                             <label class="opcion-generar-codigo" for="generar-codigo">
                                                 <input type="checkbox" name="generar_codigo" id="generar-codigo" value="1" disabled>
                                                 <span>Generar código nuevo al guardar</span>
                                             </label>
                                            <div class="control-codigo">
                                                <input type="text" id="buscar-codigo"
                                                    placeholder="Selecciona una categoría"
                                                    autocomplete="off"
                                                    maxlength="50"
                                                    role="combobox"
                                                    aria-autocomplete="list"
                                                    aria-expanded="false"
                                                    aria-controls="opciones-codigo"
                                                    aria-describedby="estado-codigo"
                                                    required
                                                    disabled    
                                                >

                                                <button type="button" id="abrir-codigos" 
                                                    aria-label="Mostrar códigos disponibles"
                                                    aria-controls="opciones-codigo"
                                                    disabled
                                                >
                                                    ▾
                                                </button>

                                                <div id="desplegable-codigos" class="desplegable-codigos" hidden>
                                                    <ul id="opciones-codigo" role="listbox" aria-label="Códigos disponibles"></ul>

                                                    <p id="sin-codigos" hidden>
                                                        No hay códigos disponibles 
                                                    </p>
                                                </div>

                                            </div>
                                                <p id="estado-codigo" role="status" aria-live="polite">
                                                    Selecciona una categoría para consultar sus códigos.
                                                </p>
                                        </div>

                                        <div class="campo-formulario">
                                            <label for="area">Área</label>
                                            <select name="id_area" id="area" required>
                                               <option value="" disabled
                                                <?= empty($datosFormulario['id_area']) ? 'selected' : '' ?>
                                            >
                                                Selecciona un área
                                                </option>

                                                <?php foreach ($areasFormulario as $area): ?>
                                                    <option
                                                        value="<?= (int) $area['id_area'] ?>"
                                                        <?= (int) ($datosFormulario['id_area'] ?? 0) === (int) $area['id_area']
                                                            ? 'selected'
                                                            : '' ?>
                                                    >
                                                        <?= htmlspecialchars(
                                                            $area['nombre'],
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="columna-formulario">
                                        <div class="campo-formulario">
                                            <label for="descripcion">Descripcion</label>
                                            <textarea name="descripcion" id="descripcion" maxlength="255" required><?= htmlspecialchars($datosFormulario['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                        
                                    </div>
                                </div>

                                <section class="atributos-producto" aria-labelledby="titulo-atributos-producto" id="titulo-atributos-producto">
                                    <div id="campos-atributos" class="rejilla-atributos" aria-live="polite">
                                        <p class="ayuda-atributos">Selecciona una categoría para cargar sus atributos.</p>
                                    </div>
                                    <button type="button" id="reintentar-atributos" class="boton secundario" hidden>Reintentar carga</button>
                                    <noscript>Activa JavaScript para cargar los atributos y guardar el producto.</noscript>
                                </section>
                                
                                <div class="acciones-formulario">
                                    <button type="submit" id="guardar-producto" class="boton primario" disabled>
                                        <?= $estaEditando?'Guardar cambios':'Crear producto'?>
                                    </button>
                                    
                                    <?php if ($estaEditando):?>
                                        <a class="boton secundario" href="<?= htmlspecialchars(urlContextoListado('productos'), ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                                    <?php else: ?>
                                        <a id="cancelar-desplegable" class="boton secundario" href="<?= htmlspecialchars(urlContextoListado('productos'), ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                                    <?php endif;?>
                                </div>
                            </form>
                            
                        <?php endif;?>
                    </section>

                    <section class="tarjeta tabla-crud">
                        <div class="encabezado-listado-areas">
                            <div class="titulo-tarjeta">
                                <h2>Productos registrados</h2>
                                <p>Consulta y administra los productos por categoría.</p>
                                <span class="contador"><?= $total ?> <?= $hayFiltros ? 'resultados' : 'registrados' ?></span>
                            </div>
                            <a id="abrir-desplegable" class="boton boton-agregar-area"
                               href="<?= htmlspecialchars(urlContextoListado('productos', $formularioVisible ? [] : ['crear' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                               data-listado="<?= htmlspecialchars(urlContextoListado('productos'), ENT_QUOTES, 'UTF-8') ?>"
                               data-etiqueta="Crear" data-editando="<?= $estaEditando ? 'true' : 'false' ?>"
                               aria-controls="formulario-desplegable" aria-expanded="<?= $formularioVisible ? 'true' : 'false' ?>">
                                <span aria-hidden="true"><?= $formularioVisible ? '×' : '+' ?></span>
                                <?= $formularioVisible ? 'Cerrar' : 'Crear' ?>
                            </a>
                        </div>
                        
                        <div class="buscador-crud">
                            <form class="buscador" method="get">
                                <label class="visualmente-oculto" for="buscar">Buscar</label>
                                <input id="buscar" name="buscar" placeholder="Código, descripción, área o categoría" value="<?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?>">

                                <fieldset class="filtro-categorias" data-filtro-categorias>
                                    <legend>Categorías</legend>
                                    <details class="desplegable-categorias">
                                        <summary data-resumen-categorias><?= $categoriasSeleccionadas ? count($categoriasSeleccionadas) . ' seleccionadas' : 'Todas las categorías' ?></summary>
                                        <div class="panel-categorias">
                                    <p>Sin casillas marcadas se muestran todas.</p>
                                    <div class="lista-categorias">
                                        <?php foreach ($categorias as $cat): ?>
                                            <label class="opcion-categoria">
                                                <input type="checkbox" name="categoria[]" value="<?= (int) $cat['id_categoria'] ?>" <?= in_array((int) $cat['id_categoria'], $categoriasSeleccionadas, true) ? 'checked' : '' ?>>
                                                <span><?= htmlspecialchars($cat['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                        </div>
                                    </details>
                                </fieldset>
                                
                                <div>
                                    <label for="fest">Estado</label>
                                    <select id="fest" name="estado">
                                        <option value="">Todos</option>
                                        <option <?= $estadoFiltro === 'ACTIVO'?'selected':''?>>ACTIVO</option>
                                        <option <?= $estadoFiltro === 'INACTIVO'?'selected':''?>>INACTIVO</option>
                                    </select>
                                </div>
                                
                                <button class="boton primario">Buscar</button>
                                <?php if ($hayFiltros):?>
                                    <a class="boton secundario" href="/sistemaInventario/administracion/productos">Limpiar</a>
                                <?php endif;?>
                            </form>
                        </div>
                        
                        <?php if ($categoriasSeleccionadas): ?>
                            <div class="acciones-formulario">
                                <a class="boton secundario" href="<?= htmlspecialchars(urlContextoListado('productos', ['categoria' => '', 'pagina' => 1]), ENT_QUOTES, 'UTF-8') ?>">Volver a todas las categorías</a>
                            </div>
                        <?php endif; ?>
                        <?php if (!$total):?>
                            <div class="estado-vacio">
                                <?= $hayFiltros?'No se encontraron productos.':'Todavía no hay productos registrados.'?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($gruposProductos as $grupo):
                            $productos = $grupo['listado']['productos'];
                            $columnasAtributos = $grupo['columnas'];
                            $totalPaginas = $grupo['listado']['totalPaginas'];
                            $pagina = $grupo['listado']['pagina'];
                        ?>
                        <section class="grupo-productos">
                            <div class="titulo-tarjeta">
                                <h3><?= htmlspecialchars($grupo['categoria']['nombre'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p>Mostrando <?= count($productos) ?> de <?= (int) $grupo['listado']['total'] ?> productos<?= $hayFiltros ? ' encontrados' : '' ?></p>
                            </div>
                            <div class="tabla-contenedor">
                                <table>
                                    <caption class="visualmente-oculto">Productos de <?= htmlspecialchars($grupo['categoria']['nombre'], ENT_QUOTES, 'UTF-8') ?></caption>
                                    <thead>
                                        <tr>
                                            <?php foreach (['Código', 'Descripción'] as $h):?>
                                                <th scope="col"><?= $h?></th>
                                            <?php endforeach;?>
                                            <?php foreach ($columnasAtributos as $columna): ?>
                                                <th scope="col"><?= htmlspecialchars($columna['nombre'], ENT_QUOTES, 'UTF-8') ?></th>
                                            <?php endforeach; ?>
                                            <?php foreach (['Área', 'Estado', 'Acciones'] as $h): ?>
                                                <th scope="col"><?= $h ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($productos as $p):?>
                                            <tr>
                                                <td><?= htmlspecialchars($p['codigo'] !== '' ? $p['codigo'] : 'Sin código vigente', ENT_QUOTES, 'UTF-8')?>
                                                    <?php if ($p['codigo_anterior'] !== null): ?><br><small>Anterior: <?= htmlspecialchars($p['codigo_anterior'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($p['descripcion'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <?php foreach ($columnasAtributos as $columna): ?>
                                                    <td class="celda-atributos-productos"><?= htmlspecialchars(
                                                        $atributosProductos[(int) $p['id_producto']][(int) $columna['id_atributo']]['valor'] ?? '—',
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?></td>
                                                <?php endforeach; ?>
                                                <td><?= htmlspecialchars($p['area'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><span class="estado <?= strtolower($p['estado'])?>"><?= $p['estado']?></span></td>
                                                <td>
                                                    <div class="acciones-tabla">
                                                        <?php if ($p['estado'] === 'ACTIVO'): ?>
                                                            <a class="boton-tabla editar" href="<?= htmlspecialchars(urlContextoListado('productos', ['editar' => (int)$p['id_producto']]), ENT_QUOTES, 'UTF-8') ?>">Editar</a>
                                                        <?php endif; ?>
                                                        <a class="boton-tabla" data-modal-baja href="/sistemaInventario/administracion/bajas?id=<?= (int)$p['id_producto'] ?>"><?= $p['estado'] === 'ACTIVO' ? 'Dar de baja' : 'Ver baja' ?></a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach;?>
                                    </tbody>
                                </table>
                            </div>
                        <?php if (!$categoriaFiltro && $grupo['listado']['total'] > 8): ?>
                            <div class="acciones-formulario">
                                <a class="boton secundario" href="<?= htmlspecialchars(urlProducto(1, array_replace($query, ['categoria' => (int) $grupo['categoria']['id_categoria']])), ENT_QUOTES, 'UTF-8') ?>">Ver más</a>
                            </div>
                        <?php endif; ?>
                        <?php if ($categoriaFiltro && $totalPaginas > 1):?>
                            <nav class="paginacion" aria-label="Páginas">
                                <?php if ($pagina > 1): ?>
                                    <a class="pagina-enlace" aria-label="Página anterior"
                                       href="<?= htmlspecialchars(urlProducto($pagina - 1, $query), ENT_QUOTES, 'UTF-8') ?>">‹</a>
                                <?php endif; ?>
                                <?php foreach (paginasProducto($pagina, $totalPaginas) as $p):?>
                                    <?php if ($p === null):?>
                                        <span class="paginacion-elipsis">…</span>
                                    <?php else:?>
                                        <a class="pagina-enlace <?= $p === $pagina?'activa':''?>"
                                        aria-label="Página <?= $p ?>"
                                        <?= $p === $pagina ? 'aria-current="page"' : '' ?>
                                        href="<?= htmlspecialchars(urlProducto($p, $query), ENT_QUOTES, 'UTF-8')?>">
                                        <?= $p?>
                                        </a>
                                    <?php endif;?>
                                <?php endforeach;?>
                                <?php if ($pagina < $totalPaginas): ?>
                                    <a class="pagina-enlace" aria-label="Página siguiente"
                                       href="<?= htmlspecialchars(urlProducto($pagina + 1, $query), ENT_QUOTES, 'UTF-8') ?>">›</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif;?>
                        </section>
                        <?php endforeach; ?>
                    </section>
                </div>
            </main>
        </div>
        </div>
        <script type="application/json" id="config-productos"><?= json_encode([
            'producto' => (int) ($productoEditar['id_producto'] ?? 0),
            'categoria' => $categoriaActual,
            'id_codigo' => (int) ($datosFormulario['id_codigo'] ?? 0),
            'generar_codigo' => (string) ($datosFormulario['generar_codigo'] ?? '0') === '1',
            'atributos' => (object) $atributosRecuperados,
            'unidades' => (object) ($datosRecuperados['unidades_producto'] ?? []),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) ?></script>
        <dialog id="modal-baja" class="modal-baja" aria-labelledby="titulo-modal-baja">
            <header class="encabezado-modal-baja">
                <h2 id="titulo-modal-baja">Baja de equipo</h2>
                <button type="button" id="cerrar-modal-baja" class="cerrar" aria-label="Cerrar ventana">×</button>
            </header>
            <p id="estado-modal-baja" role="status" hidden></p>
            <div id="contenido-modal-baja"></div>
        </dialog>
        <script src="/sistemaInventario/admin/productos/filtro-categorias.js"></script>
        <script src="/sistemaInventario/admin/productos/formulario.js"></script>
        <script src="/sistemaInventario/admin/productos/selector-area.js"></script>
        <script src="/sistemaInventario/admin/js/formulario-desplegable.js"></script>
        <script src="/sistemaInventario/admin/js/confirmaciones.js"></script>
        <script src="/sistemaInventario/admin/productos/bajas/modal.js"></script>
        <script src="/sistemaInventario/admin/codigos/selector.js"></script>
    </body>
</html>

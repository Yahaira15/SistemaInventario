<?php

declare (strict_types=1);
require_once __DIR__ . '/../../auth/busqueda.php';

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

if (empty($_SESSION['csrf_atributos'])) {
    $_SESSION['csrf_atributos'] = bin2hex(random_bytes(32));
}

$conexion = require __DIR__ . '/../../config/conexion.php';

function urlPagina(int $pagina, array $parametrosUrl): string
{
    $parametrosUrl['pagina'] = $pagina;

    return '/sistemaInventario/administracion/atributos?'
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

// Recupera los mensajes generados por las operaciones POST
$mensajeExito = (string) ($_SESSION['mensajeExito'] ?? '');
$mensajeError = (string) ($_SESSION['mensajeError'] ?? '');
unset($_SESSION['mensajeExito'], $_SESSION['mensajeError']);

// Carga el atributo cuando la pantalla esta en modo edición
$atributoEditar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

if ($idEditar !== null && $idEditar !== false) {
    $consultaAtributoEditar = $conexion->prepare(
        'SELECT id_atributo, nombre, descripcion,
                tipo_dato, unidad, longitud_maxima, valor_minimo,
                valor_maximo, decimales, estado
         FROM atributos
         WHERE id_atributo = :id_atributo
         LIMIT 1'
    );
    $consultaAtributoEditar->execute(['id_atributo' => $idEditar]);
    $atributoEditar = $consultaAtributoEditar->fetch();

    if (!$atributoEditar) {
        http_response_code(404);
        exit('Atributo no encontrado.');
    }
}

$tipoDatoBloqueado = false;
$motivosBloqueoTipo = [];

if ($atributoEditar) {
    $consultaDependencias = $conexion->prepare(
        'SELECT
            EXISTS (
                SELECT 1
                FROM atributo_opciones AS ao
                WHERE ao.id_atributo = :id_atributo_opciones
            ) AS tiene_opciones,

            EXISTS (
                SELECT 1
                FROM categoria_atributos AS ca
                WHERE ca.id_atributo = :id_atributo_asignaciones
            ) AS tiene_asignaciones,

            EXISTS (
                SELECT 1
                FROM producto_atributo_valores AS pav
                INNER JOIN categoria_atributos AS ca
                    ON ca.id_categoria_atributo = pav.id_categoria_atributo
                WHERE ca.id_atributo = :id_atributo_valores
            ) AS tiene_valores'
    );

    $consultaDependencias->execute([
        'id_atributo_opciones' => $atributoEditar['id_atributo'],
        'id_atributo_asignaciones' => $atributoEditar['id_atributo'],
        'id_atributo_valores' => $atributoEditar['id_atributo'],
    ]);

    $dependencias = $consultaDependencias->fetch();

    if ((int) $dependencias['tiene_opciones'] === 1) {
        $motivosBloqueoTipo[] = 'opciones registradas';
    }

    if ((int) $dependencias['tiene_asignaciones'] === 1) {
        $motivosBloqueoTipo[] = 'asignaciones a categorías';
    }

    if ((int) $dependencias['tiene_valores'] === 1) {
        $motivosBloqueoTipo[] = 'valores de productos';
    }

    $tipoDatoBloqueado = $motivosBloqueoTipo !== [];
}

// Recibe la busqueda, los filtros y la página solicitada
$buscar = trim(textoEntrada($_GET, 'buscar'));
$datoFiltro = strtoupper(trim(textoEntrada($_GET, 'dato')));
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

if (!in_array($datoFiltro, ['', 'TEXTO', 'NUMERO', 'FECHA', 'BOOLEANO', 'SELECCION'], true)) {
    $datoFiltro = '';
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
        )
    ';

    $parametros['buscar_nombre'] = $terminoBusqueda;
}

if ($datoFiltro !== '') {
    $condiciones[] = 'tipo_dato = :filtro_dato';
    $parametros['filtro_dato'] = $datoFiltro;
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
    FROM atributos
' . $whereSql;

$consultaTotal = $conexion->prepare($sqlTotal);
$consultaTotal->execute($parametros);

$totalAtributos = (int) $consultaTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalAtributos / $porPagina));

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset = ($paginaActual - 1) * $porPagina;

// Obtiene solamente los atributos correspondientes a la página actual.
$sqlAtributos = '
    SELECT id_atributo, nombre, descripcion,
            tipo_dato, unidad, longitud_maxima, valor_minimo,
            valor_maximo, decimales, estado
    FROM atributos
' . $whereSql . '
    ORDER BY fecha_actualizacion DESC, id_atributo DESC
    LIMIT :limite OFFSET :offset
';

$consulta = $conexion->prepare($sqlAtributos);

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

$atributos = $consulta->fetchAll();
$estaEditando = $atributoEditar !== null;
require_once __DIR__ . '/../../auth/formularios.php';
$datosRecuperados = recuperarFormulario('atributos', (int) ($atributoEditar['id_atributo'] ?? 0), []);
$datosFormulario = array_replace($atributoEditar ?? [], $datosRecuperados);
$unidadesPorAtributo = [];
foreach ($conexion->query('SELECT id_atributo, simbolo FROM atributo_unidades WHERE estado = "ACTIVO" ORDER BY simbolo')->fetchAll() as $u) {
    $unidadesPorAtributo[$u['id_atributo']][] = $u['simbolo'];
}
$unidadesFormulario = $datosRecuperados['unidades'] ?? ($unidadesPorAtributo[$atributoEditar['id_atributo'] ?? 0] ?? ['']);
$seccionActiva = 'atributos';
$hayFiltros = $buscar !== '' || $datoFiltro !== '' || $estadoFiltro !== '';

// Conserva los filtros cuando el usuario cambia de página.
$parametrosUrl = [];

if ($buscar !== '') {
    $parametrosUrl['buscar'] = $buscar;
}

if ($datoFiltro !== '') {
    $parametrosUrl['dato'] = $datoFiltro;
}

if ($estadoFiltro !== '') {
    $parametrosUrl['estado'] = $estadoFiltro;
}

$tipoDatoActual = $atributoEditar['tipo_dato'] ?? 'TEXTO';
$tipoDatoFormulario = $tipoDatoBloqueado ? $tipoDatoActual : ($datosRecuperados['tipoDato'] ?? $tipoDatoActual);

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atributos</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/atributos/opciones/modal.css">
</head>
<body>
    <div class="estructura-admin">
        <?php require __DIR__ . '/../menu_lateral.php'?>

        <div class="area-principal">
<section class="encabezado-modulo">
                <h1>Atributos</h1>
                
                <?php require __DIR__ . '/../usuario_encabezado.php'; ?>
            </section>

            <div class="barra-navegacion">
                <a class="modulo-navegacion" href="/sistemaInventario/administracion/categorias">Categorías</a>
                <a class="modulo-navegacion activo" href="/sistemaInventario/administracion/atributos" aria-current="page">Atributos</a>
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
                            <h2><?= $estaEditando ? 'Editar atributo' : 'Nuevo atributo' ?></h2>
                            <p><?= $estaEditando ? 'Actualiza la información seleccionada.' : 'Registra un atributo para utilizarlo en las categorías.' ?></p>
                        </div>
                        <form action="/sistemaInventario/administracion/atributos/procesar" method="post" class="formulario-crud">
<input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('atributos', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_atributos'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="accion" value="<?= $estaEditando ? 'actualizar' : 'crear' ?>">

                            <?php if ($estaEditando): ?>
                                <input type="hidden" name="id_atributo" value="<?= (int) $datosFormulario['id_atributo'] ?>">
                            <?php endif; ?>

                            <div class="container">
                                <div class="columna-formulario">
                                   
                                    <div class="campo-formulario">
                                        <label for="nombre">Nombre</label>
                                        <input type="text" id="nombre" name="nombre" minlength="2" maxlength="80"
                                            value="<?= htmlspecialchars($datosFormulario['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>

                                    <div class="campo-formulario">
                                        <label for="descripcion">Descripción</label>
                                        <textarea id="descripcion" name="descripcion" maxlength="255" rows="4"><?= htmlspecialchars($datosFormulario['descripcion'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                </div>

                                <div class="columna-formulario">
                                    <div class="campo-formulario">
                                        <label for="tipoDato">Tipo de dato</label>
                                        <select name="tipoDato" id="tipoDato" required
                                            <?= $tipoDatoBloqueado ? 'disabled' : '' ?> 
                                        >
                                            <option value="TEXTO" <?= $tipoDatoFormulario === 'TEXTO' ? 'selected' : '' ?>>Texto</option>
                                            <option value="NUMERO" <?= $tipoDatoFormulario === 'NUMERO' ? 'selected' : '' ?>>Número</option>
                                            <option value="FECHA" <?= $tipoDatoFormulario === 'FECHA' ? 'selected' : '' ?>>Fecha</option>
                                            <option value="BOOLEANO" <?= $tipoDatoFormulario === 'BOOLEANO' ? 'selected' : '' ?>>Sí / No</option>
                                            <option value="SELECCION" <?= $tipoDatoFormulario === 'SELECCION' ? 'selected' : '' ?>>Lista de opciones</option>
                                        </select>
                                        <?php if ($tipoDatoBloqueado): ?>
                                        <input
                                                type="hidden"
                                                name="tipoDato"
                                                value="<?= htmlspecialchars($tipoDatoActual, ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                        <?php endif; ?>
                                    </div>

                                    <div id="config-texto" data-configuracion-tipo="TEXTO">
                                        <div class="campo-formulario">
                                            <label for="longitud_maxima">Longitud máxima</label>
                                            <input type="number" name="longitud_maxima" id="longitud_maxima" min="1" max="255"
                                                value="<?= htmlspecialchars((string) ($datosFormulario['longitud_maxima'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                placeholder="Ejemplo: 100">
                                            <small>Cantidad máxima de caracteres permitidos.</small>
                                        </div>
                                    </div>
                                        <!-- DESPLIEGUE SEGUN SI EL TIPO DE DATO SELECCIONADO ES EL NÚMERO, CAMPO UNIDAD -->
                                    <div id="config-numero" class="estado-vacio" data-configuracion-tipo="NUMERO">
                                        <label>Unidades</label>
                                        <div id="lista-unidades">
                                            <?php foreach ($unidadesFormulario ?: [''] as $simbolo): ?>
                                            <div class="fila-opcion">
                                                <input type="text" name="unidades[]" maxlength="50" aria-label="Unidad permitida" placeholder="Ejemplo: GB" value="<?= htmlspecialchars((string) $simbolo, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="button" class="boton secundario quitar-opcion">Quitar</button>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" id="agregar-unidad" class="boton opciones">+ Agregar</button>
                                        <?php if ($estaEditando): ?><small>Quitar una unidad la desactiva; los productos que ya la utilizan la conservan. Para reactivarla, vuelve a agregar su símbolo.</small><?php endif; ?>
                                    </div>

                                    <div id="config-fecha" class="estado-vacio" data-configuracion-tipo="FECHA" aria-live="polite">
                                        Este tipo no necesita configuración adicional.
                                    </div>

                                    <div id="config-booleano" class="estado-vacio" data-configuracion-tipo="BOOLEANO" aria-live="polite">
                                        El formulario del producto mostrará las opciones Sí y No.
                                    </div>

                                    <div id="config-seleccion" class="estado-vacio" data-configuracion-tipo="SELECCION" aria-live="polite">
                                        <?php if ($estaEditando && $tipoDatoActual === 'SELECCION'): ?>
                                            Puedes administrar los valores de la lista desde
                                            <a class="boton primaraio   " href="/sistemaInventario/administracion/atributos/opciones?atributo=<?= (int) $datosFormulario['id_atributo'] ?>">Opciones</a>.
                                        <?php else: ?>

                                            <div id="lista-opciones">
                                                <?php foreach (($datosRecuperados['opciones'] ?? ['']) ?: [''] as $indiceOpcion => $valorOpcion): ?>
                                                <div class="fila-opcion">
                                                    <input type="text" name="opciones[]" maxlength="100" placeholder="Ejemplo: Rojo" aria-label="Opción <?= (int) $indiceOpcion + 1 ?>" value="<?= htmlspecialchars((string) $valorOpcion, ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="button" class="boton secundario quitar-opcion">Quitar</button>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <button type="button" id="agregar-opcion" class="boton opciones">+ Agregar</button> 
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($estaEditando): ?>
                                        <div class="campo-formulario">
                                            <label for="estado">Estado</label>
                                            <select name="estado" id="estado" required>
                                                <option value="ACTIVO" <?= $datosFormulario['estado'] === 'ACTIVO' ? 'selected' : '' ?>>Activo</option>
                                                <option value="INACTIVO" <?= $datosFormulario['estado'] === 'INACTIVO' ? 'selected' : '' ?>>Inactivo</option>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="acciones-formulario">
                                <button class="boton primario" type="submit"><?= $estaEditando ? 'Guardar cambios' : 'Crear atributo' ?></button>
                                <?php if ($estaEditando): ?>
                                    <a class="boton secundario" href="<?= htmlspecialchars(urlContextoListado('atributos'), ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>

                    <section class="tarjeta tabla">
                        <div class="titulo-tarjeta">
                            <h2>Atributos registrados</h2>
                            <p>Consulta, edita, activa o desactiva atributos.</p>
                            <span class="contador">
                        <?= $totalAtributos?>
                        <?= $hayFiltros ? 'resultado' : 'registrados' ?>
                    </span>
                        
                        </div>

                        <div class="buscador-crud">
                            
                            <form method="get" action="/sistemaInventario/administracion/atributos" class="buscador">
            
                                    <label class="visualmente-oculto" for="buscar">Buscar atributos</label>

                                    <input type="search" id="buscar" name="buscar" maxlength="100"
                                    placeholder="Buscar nombre del atributo"
                                    value="<?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?>">
                                
                                    <div>
                                        <label for="filtro-dato">Tipo de dato</label>

                                        <select name="dato" id="filtro-dato">
                                            <option value="">Todos los datos</option>

                                            <option value="TEXTO"
                                                <?= $datoFiltro === 'TEXTO' ? 'selected' : '' ?>
                                            >
                                                Texto
                                            </option>
                                            
                                            <option value="NUMERO"
                                                <?= $datoFiltro === 'NUMERO' ? 'selected' : '' ?>
                                            >
                                                Número
                                            </option>

                                            <option value="FECHA"
                                                <?= $datoFiltro === 'FECHA' ? 'selected' : '' ?>
                                            >
                                                Fecha
                                            </option>

                                            <option value="BOOLEANO"
                                                <?= $datoFiltro === 'BOOLEANO' ? 'selected' : '' ?>
                                            >
                                                Sí / No
                                            </option>

                                            <option value="SELECCION"
                                                <?= $datoFiltro === 'SELECCION' ? 'selected' : '' ?>
                                            >
                                                Selección
                                            </option>
                                        </select>
                                    </div>

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
                    
                                <?php if ($buscar !== '' || $datoFiltro !== '' || $estadoFiltro !== ''): ?>
                                    <a  href="/sistemaInventario/administracion/atributos" class="boton secundario">
                                        Limpiar
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>
                        
                         <?php if ($atributos === []): ?>

                            <?php if ($hayFiltros): ?>
                                <div class="estado-vacio">No se encontraron atributos con los filtros seleccionados</div>
                            <?php else: ?>
                                <div class="estado-vacio">
                                    Todavía no hay atributos registrados.
                                </div>
                            <?php endif; ?>

                         <?php else: ?>

                            <div class="tabla-contenedor">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>Descripción</th>
                                            <th>Tipo de dato</th>
                                            <th>Unidad</th>
                                            <th>Decimal</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($atributos as $atributo): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($atributo['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($atributo['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($atributo['tipo_dato'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars(implode(', ', $unidadesPorAtributo[$atributo['id_atributo']] ?? []), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($atributo['decimales'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <span class="estado <?= strtolower($atributo['estado']) ?>">
                                                        <?= htmlspecialchars($atributo['estado'], ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <div class="acciones-tabla">
                                                        <a class="boton-tabla editar" href="<?= htmlspecialchars(urlContextoListado('atributos', ['editar' => (int) $atributo['id_atributo']]), ENT_QUOTES, 'UTF-8') ?>">Editar</a>

                                                        <?php if ($atributo['tipo_dato'] === 'SELECCION'): ?>
                                                            <?php if ($atributo['estado'] === 'ACTIVO'): ?>
                                                                <a class="boton-tabla atributos" href="/sistemaInventario/administracion/atributos/opciones?atributo=<?= (int) $atributo['id_atributo'] ?>">Opciones</a>
                                                            <?php else: ?>
                                                                <span class="boton-tabla atributos deshabilitado" aria-disabled="true" title="Activa el atributo para administrar sus opciones">Opciones</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>

                                                        <form action="/sistemaInventario/administracion/atributos/procesar" method="post" data-confirmar="¿Está seguro que desea cambiar el estado?">
<input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('atributos', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_atributos'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="cambiar_estado">
                                                            <input type="hidden" name="id_atributo" value="<?= (int) $atributo['id_atributo'] ?>">
                                                            <input type="hidden" name="estado" value="<?= $atributo['estado'] === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO' ?>">
                                                            <button class="boton-tabla" type="submit">
                                                                <?= $atributo['estado'] === 'ACTIVO' ? 'Desactivar' : 'Activar' ?>
                                                            </button>
                                                        </form>
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
    <script src="/sistemaInventario/admin/atributos/formulario.js"></script>
    <script src="/sistemaInventario/admin/js/confirmaciones.js"></script>
    <dialog id="modal-opciones" class="modal-opciones" aria-labelledby="titulo-opciones">
        <header class="encabezado-opciones">
            <h2 id="titulo-opciones">Opciones del atributo</h2>
            <button type="button" class="cerrar" id="cerrar-opciones" aria-label="Cerrar opciones">×</button>
        </header>
        <p id="estado-opciones" role="status" hidden></p>
        <div id="contenido-opciones"></div>
    </dialog>
    <script src="/sistemaInventario/admin/atributos/opciones/modal.js"></script>
</body>
</html>

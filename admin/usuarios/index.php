<?php

declare(strict_types=1);
require_once __DIR__ . '/../../auth/busqueda.php';
require_once __DIR__ . '/imagenes.php';

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

if (empty($_SESSION['csrf_usuarios'])) {
    $_SESSION['csrf_usuarios'] = bin2hex(random_bytes(32));
}

$conexion = require __DIR__ . '/../../config/conexion.php';

function urlPagina(int $pagina, array $parametrosUrl): string
{
    $parametrosUrl['pagina'] = $pagina;

    return '/sistemaInventario/administracion/usuarios?'
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
$usuarioEditar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

if ($idEditar !== null && $idEditar !== false) {
    $consultaUsuarioEditar = $conexion->prepare(
        'SELECT u.id_usuario, u.cedula, u.nombre, u.apellido,
             u.correo, u.id_area, a.nombre AS area, u.rol, u.estado
         FROM usuarios AS u
         INNER JOIN areas AS a ON a.id_area = u.id_area
         WHERE u.id_usuario = :id_usuario
         LIMIT 1'
    );
    $consultaUsuarioEditar->execute(['id_usuario' => $idEditar]);
    $usuarioEditar = $consultaUsuarioEditar->fetch();

    if (!$usuarioEditar) {
        http_response_code(404);
        exit('Usuario no encontrado.');
    }
}

// Recibe la búsqueda, los filtros y la página solicitada.
$buscar = trim(textoEntrada($_GET, 'buscar'));
$areaFiltro = filter_input(INPUT_GET, 'area', FILTER_VALIDATE_INT);
if (!$areaFiltro || $areaFiltro < 1) {
    $areaFiltro = null;
}
$estadoFiltro = strtoupper(trim(textoEntrada($_GET, 'estado')));
$rolFiltro = strtoupper(trim(textoEntrada($_GET, 'rol')));
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

if (!in_array($rolFiltro, ['', 'FUNCIONARIO', 'ADMINISTRADOR'], true)) {
    $rolFiltro = '';
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
    'area_actual' => (int) ($usuarioEditar['id_area'] ?? 0),
]);
$areasFormulario = $consultaAreasFormulario->fetchAll();

$consultaAreasDisponibles = $conexion->query(
    'SELECT id_area, nombre
     FROM areas
     ORDER BY nombre ASC'
);
$areasDisponibles = $consultaAreasDisponibles->fetchAll();

$idsAreasDisponibles = array_map('intval', array_column($areasDisponibles, 'id_area'));

if ($areaFiltro !== null && !in_array($areaFiltro, $idsAreasDisponibles, true)) {
    $areaFiltro = null;
}

// Construye las condiciones compartidas por el conteo y el listado.
$condiciones = [];
$parametros = [];

if ($buscar !== '') {
    $terminoBusqueda = patronBusqueda($buscar);

    $condiciones[] = '
        (
            u.cedula LIKE :buscar_cedula ESCAPE "!"
            OR u.nombre LIKE :buscar_nombre ESCAPE "!"
            OR u.apellido LIKE :buscar_apellido ESCAPE "!"
            OR u.correo LIKE :buscar_correo ESCAPE "!"
            OR a.nombre LIKE :buscar_area ESCAPE "!"
            OR CONCAT(u.nombre, " ", u.apellido) LIKE :buscar_completo ESCAPE "!"
        )
    ';

    $parametros['buscar_cedula'] = $terminoBusqueda;
    $parametros['buscar_nombre'] = $terminoBusqueda;
    $parametros['buscar_apellido'] = $terminoBusqueda;
    $parametros['buscar_correo'] = $terminoBusqueda;
    $parametros['buscar_area'] = $terminoBusqueda;
    $parametros['buscar_completo'] = $terminoBusqueda;
}

if ($areaFiltro !== null) {
    $condiciones[] = 'u.id_area = :filtro_area';
    $parametros['filtro_area'] = $areaFiltro;
}

if ($estadoFiltro !== '') {
    $condiciones[] = 'u.estado = :filtro_estado';
    $parametros['filtro_estado'] = $estadoFiltro;
}
if ($rolFiltro !== '') {
    $condiciones[] = 'u.rol = :filtro_rol';
    $parametros['filtro_rol'] = $rolFiltro;
}

$whereSql = '';

if ($condiciones !== []) {
    $whereSql = ' WHERE ' . implode(' AND ', $condiciones);
}

// Cuenta todos los usuarios que cumplen la búsqueda y los filtros.
$sqlTotal = '
    SELECT COUNT(*)
    FROM usuarios AS u
    INNER JOIN areas AS a ON a.id_area = u.id_area
' . $whereSql;

$consultaTotal = $conexion->prepare($sqlTotal);
$consultaTotal->execute($parametros);

$totalUsuarios = (int) $consultaTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalUsuarios / $porPagina));

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset = ($paginaActual - 1) * $porPagina;

// Obtiene solamente los usuarios correspondientes a la página actual.
$sqlUsuarios = '
    SELECT u.id_usuario, u.cedula, u.nombre,
           u.apellido, u.correo, a.nombre AS area, u.rol, u.estado
    FROM usuarios AS u
    INNER JOIN areas AS a ON a.id_area = u.id_area
' . $whereSql . '
    ORDER BY u.fecha_actualizacion DESC, u.id_usuario DESC
    LIMIT :limite OFFSET :offset
';

$consulta = $conexion->prepare($sqlUsuarios);

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

$usuarios = $consulta->fetchAll();
$estaEditando = $usuarioEditar !== null;
require_once __DIR__ . '/../../auth/formularios.php';
$datosRecuperados = recuperarFormulario('usuarios', (int) ($usuarioEditar['id_usuario'] ?? 0), []);
$datosFormulario = array_replace($usuarioEditar ?? [], $datosRecuperados);
$hayImagenUsuario = $estaEditando && tieneImagenUsuario($conexion, (int) $usuarioEditar['id_usuario']);
$seccionActiva = 'usuarios';
$hayFiltros = $buscar !== '' || $areaFiltro !== null || $estadoFiltro !== '' || $rolFiltro !== '';

// Conserva los filtros cuando el usuario cambia de página.
$parametrosUrl = [];

if ($buscar !== '') {
    $parametrosUrl['buscar'] = $buscar;
}

if ($areaFiltro !== null) {
    $parametrosUrl['area'] = $areaFiltro;
}

if ($estadoFiltro !== '') {
    $parametrosUrl['estado'] = $estadoFiltro;
}

if ($rolFiltro !== '') {
    $parametrosUrl['rol'] = $rolFiltro;
}

?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/usuarios/imagen.css">
</head>
<body>
    <div class="estructura-admin">
        <?php require __DIR__ . '/../menu_lateral.php'?>

        <div class="area-principal">
<section class="encabezado-modulo">
                <h1>Administración de usuarios</h1>
                <?php require __DIR__ . '/../usuario_encabezado.php'; ?>
            </section>
            
            <nav class="barra-navegacion">
                <a class="modulo-navegacion activo" href="/sistemaInventario/administracion/usuarios">Usuarios</a>
                <a class="modulo-navegacion" href="/sistemaInventario/administracion/areas">Áreas</a>
            </nav>

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
                            <h2><?= $estaEditando ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
                            <p><?= $estaEditando ? 'Actualiza la información seleccionada.' : 'Registra los datos del usuario' ?></p>
                        </div>

                        <form action="/sistemaInventario/administracion/usuarios/procesar" method="post" enctype="multipart/form-data" class="formulario-crud"
                            <?= $estaEditando ? 'data-confirmar="¿Está seguro de guardar los cambios de este usuario?"' : '' ?>>
                            <input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('usuarios', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_usuarios'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="accion" value="<?= $estaEditando ? 'actualizar' : 'crear' ?>">

                            <?php if ($estaEditando): ?>
                                <input type="hidden" name="id_usuario" value="<?= (int) $datosFormulario['id_usuario'] ?>">
                            <?php endif; ?>

                            <div class="container columnas-usuario">
                                <div class="columna-formulario">
                                    <div class="campo-formulario">
                                        <label for="cedula">Cédula</label>
                                        <input type="text" id="cedula" name="cedula" minlength="1" maxlength="20"
                                        value="<?= htmlspecialchars($datosFormulario['cedula'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        required>
                                    </div>

                                    <div class="campo-formulario">
                                        <label for="nombre">Nombre</label>
                                        <input type="text" name="nombre" id="nombre" minlength="1" maxlength="80"
                                        value="<?= htmlspecialchars($datosFormulario['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        required>
                                    </div>

                                    <div class="campo-formulario">
                                        <label for="apellido">Apellido</label>
                                        <input type="text" name="apellido" id="apellido" minlength="1" maxlength="80"
                                        value="<?= htmlspecialchars($datosFormulario['apellido'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        required>
                                    </div>

                                    <div class="campo-formulario campo-correo">
                                        <label for="correo">Correo</label>
                                        <input type="email" name="correo" id="correo" minlength="1" maxlength="255"
                                        value="<?= htmlspecialchars($datosFormulario['correo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        required>
                                    </div>
                                </div>


                                <div class="columna-formulario">
                                    
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
                                    
                                    <div class="campo-formulario">
                                        <label for="rol">Rol</label>
                                        <select name="rol" id="rol" required>
                                            <option 
                                                value="FUNCIONARIO"
                                                <?= ($datosFormulario['rol'] ?? 'FUNCIONARIO') === 'FUNCIONARIO'
                                                ? 'selected'
                                                : '' ?>
                                            >Funcionario
                                            </option>

                                            <option 
                                                value="ADMINISTRADOR" 
                                                 <?= ($datosFormulario['rol'] ?? 'FUNCIONARIO') === 'ADMINISTRADOR'
                                                 ? 'selected'
                                                 : '' ?>
                                            >Administrador
                                            </option>
                                        </select>
                                    </div>

                                    <div class="campo-formulario" id="campo-contrasena"
                                        <?= ($datosFormulario['rol'] ?? 'FUNCIONARIO') !== 'ADMINISTRADOR'
                                             ? 'hidden' : '' ?>
                                    >
                                        <label for="contrasena">Contraseña</label>
                                        <input type="password" name="contrasena" id="contrasena" minlength="8" maxlength="255" autocomplete="new-password"
                                            <?= !$estaEditando ? 'disabled' : '' ?>
                                        >

                                        <?php if ($estaEditando): ?>
                                            <small>Déjala vacía para conservar la contraseña actual.</small>
                                        <?php else: ?>
                                            <small>Mínimoo 8 cáracteres.</small>
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
                            <div class="imagen-usuario">
                                <div class="imagen-usuario-vista">
                                    <img id="vista-imagen-usuario" alt="Imagen del usuario" <?= $hayImagenUsuario ? 'src="/sistemaInventario/admin/usuarios/imagen.php?usuario=' . (int) $usuarioEditar['id_usuario'] . '"' : 'hidden' ?>>
                                    <span id="sin-imagen-usuario" <?= $hayImagenUsuario ? 'hidden' : '' ?> aria-hidden="true">
                                        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2"><circle cx="24" cy="17" r="8"/><path d="M9 42v-5a15 15 0 0 1 30 0v5"/></svg>
                                    </span>
                                </div>
                                <div class="imagen-usuario-campo">
                                    <label for="imagen">Imagen del usuario <span>(opcional)</span></label>
                                    <input type="file" id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp" aria-describedby="ayuda-imagen estado-imagen">
                                    <small id="ayuda-imagen">JPG, PNG o WebP · Máximo 2 MB y 4096 × 4096 píxeles. <?= $estaEditando ? 'Si no seleccionas otra imagen, se conserva la actual.' : '' ?></small>
                                    <button type="button" class="imagen-cancelar" id="cancelar-imagen" hidden>Cancelar selección</button>
                                </div>
                            </div>
                            </div>
                            <div class="container">
                                <div class="acciones-formulario">
                                    <button class="boton primario" type="submit">
                                        <?= $estaEditando ? 'Guardar cambios' : 'Crear usuario' ?>
                                    </button>

                                    <?php if ($estaEditando): ?>
                                        <a class="boton secundario" href="<?= htmlspecialchars(urlContextoListado('usuarios'), ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </section>


                    <section class="tarjeta tabla-crud">
                        <div class="titulo-tarjeta">
                            <h2>Usuarios registrados</h2>
                            <p>Consulta, edita, activa o desactiva usuarios.</p>
                        
                            <span class="contador">   
                            <?= $totalUsuarios ?>
                            <?= $hayFiltros ? 'resultados' : 'registrados' ?>
                            </span>
                        </div>
                        
                        <div class="buscador-crud">
                            
                            <form method="get" action="/sistemaInventario/administracion/usuarios" class="buscador">
            
                                    <label class="visualmente-oculto" for="buscar">Buscar usuarios</label>

                                    <input type="search" id="buscar" name="buscar" maxlength="100"
                                    placeholder="Buscar cédula, nombre, correo o área"
                                    value="<?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?>">
                                

                                    <div>
                                        <label for="filtro-area">Área</label>

                                        <select name="area" id="filtro-area">
                                            <option value="">Todas las áreas</option>

                                            <?php foreach ($areasDisponibles as $area): ?>
                                            <option value="<?= (int) $area['id_area'] ?>"
                                                <?= $areaFiltro === (int) $area['id_area'] ? 'selected' : '' ?>
                                            >
                                            <?= htmlspecialchars($area['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                            <?php endforeach; ?>
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

                                    <div>
                                        <label for="filtro-rol">Rol</label>

                                        <select name="rol" id="filtro-rol">
                                            <option value="">Todos los roles</option>

                                            <option value="FUNCIONARIO"
                                                <?= $rolFiltro === 'FUNCIONARIO' ? 'selected' : '' ?>
                                            >
                                                Funcionario
                                            </option>
                                            
                                            <option value="ADMINISTRADOR"
                                                <?= $rolFiltro === 'ADMINISTRADOR' ? 'selected' : '' ?>
                                            >
                                                Administrador 
                                            </option>
                                        </select>
                                    </div>


                                    <button type="submit" class="boton primario">Buscar</button>
                    
                                <?php if ($hayFiltros): ?>
                                    <a  href="/sistemaInventario/administracion/usuarios" class="boton secundario">
                                        Limpiar
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>

                                   
                    <?php if ($usuarios === []): ?>

                        <?php if ($hayFiltros): ?>
                            <div class="estado-vacio">No se encontraron usuarios con los filtros seleccionados</div>
                        <?php else: ?>
                            <div class="estado-vacio">
                                Todavía no hay usuarios registrados.
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        
                        <div class="tabla-contenedor">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cédula</th>
                                        <th>Nombre</th>
                                        <th>Apellido</th>
                                        <th>Correo</th>
                                        <th>Área</th>
                                        <th>Rol</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usuarios as $usuario): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($usuario['cedula'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($usuario['apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($usuario['correo'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($usuario['area'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <span class="estado" <?= strtolower($usuario['rol']) ?>>
                                                    <?= htmlspecialchars($usuario['rol'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="estado <?= strtolower($usuario['estado']) ?>">
                                                    <?= htmlspecialchars($usuario['estado'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                           
                                            <td>
                                                <div class="acciones-tabla">
                                                    <a class="boton-tabla editar" href="<?= htmlspecialchars(urlContextoListado('usuarios', ['editar' => (int) $usuario['id_usuario']]), ENT_QUOTES, 'UTF-8') ?>">Editar</a>

                                                    <form action="/sistemaInventario/administracion/usuarios/procesar" method="post" data-confirmar="¿Está seguro que desea cambiar el estado?">
                                                        <input type="hidden" name="volver" value="<?= htmlspecialchars(http_build_query(contextoListado('usuarios', $_GET)), ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_usuarios'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="accion" value="cambiar_estado">
                                                        <input type="hidden" name="id_usuario" value="<?= (int) $usuario['id_usuario'] ?>">
                                                        <input type="hidden" name="estado" value="<?= $usuario['estado'] === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO' ?>">
                                                        <button class="boton-tabla" type="submit">
                                                            <?= $usuario['estado'] === 'ACTIVO' ? 'Desactivar' : 'Activar' ?>
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
    <script type="application/json" id="config-usuarios"><?= json_encode([
        'estaEditando' => $estaEditando,
        'rolOriginal' => $usuarioEditar['rol'] ?? 'FUNCIONARIO',
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <script src="/sistemaInventario/admin/usuarios/formulario.js"></script>
    <script src="/sistemaInventario/admin/js/confirmaciones.js"></script>
</body>
</html>

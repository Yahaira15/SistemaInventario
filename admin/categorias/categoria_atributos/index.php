<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/sesion.php';
require_once __DIR__ . '/../../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

const RUTA_CATEGORIA_ATRIBUTOS = '/sistemaInventario/administracion/categorias/atributos';

if (empty($_SESSION['csrf_categoria_atributos'])) {
    $_SESSION['csrf_categoria_atributos'] = bin2hex(random_bytes(32));
}

$conexion = require __DIR__ . '/../../../config/conexion.php';
$seccionActiva = 'categorias';

$mensajeExito = (string) ($_SESSION['mensajeExito'] ?? '');
$mensajeError = (string) ($_SESSION['mensajeError'] ?? '');
unset($_SESSION['mensajeExito'], $_SESSION['mensajeError']);

$idCategoria = filter_input(INPUT_GET, 'categoria', FILTER_VALIDATE_INT);

if (!$idCategoria || $idCategoria < 1) {
    http_response_code(400);
    exit('Categoría no válida.');
}

$consultaCategoria = $conexion->prepare(
    'SELECT id_categoria, nombre, descripcion, estado
     FROM categorias
     WHERE id_categoria = :id_categoria
     LIMIT 1'
);
$consultaCategoria->execute(['id_categoria' => $idCategoria]);
$categoria = $consultaCategoria->fetch();

if (!$categoria) {
    http_response_code(404);
    exit('Categoría no encontrada.');
}

if ($categoria['estado'] !== 'ACTIVO') {
    $_SESSION['mensajeError'] = 'No puedes configurar atributos de una categoría inactiva.';

    header('Location: /sistemaInventario/administracion/categorias');
    exit;
}

$consultaAsignados = $conexion->prepare(
    'SELECT
        ca.id_categoria_atributo,
        ca.id_atributo,
        ca.requerido,
        ca.orden_visual,
        ca.estado AS estado_asignacion,
        a.nombre,
        a.tipo_dato,
        (SELECT GROUP_CONCAT(u.simbolo ORDER BY u.simbolo SEPARATOR ", ") FROM atributo_unidades u WHERE u.id_atributo = a.id_atributo AND u.estado = "ACTIVO") AS unidad,
        a.estado AS estado_atributo
     FROM categoria_atributos AS ca
     INNER JOIN atributos AS a ON a.id_atributo = ca.id_atributo
     WHERE ca.id_categoria = :id_categoria
     ORDER BY ca.orden_visual ASC, a.nombre ASC'
);
$consultaAsignados->execute(['id_categoria' => $idCategoria]);
$atributosAsignados = $consultaAsignados->fetchAll();

$consultaDisponibles = $conexion->prepare(
    'SELECT a.id_atributo, a.nombre, a.tipo_dato, (SELECT GROUP_CONCAT(u.simbolo ORDER BY u.simbolo SEPARATOR ", ") FROM atributo_unidades u WHERE u.id_atributo = a.id_atributo AND u.estado = "ACTIVO") AS unidad
     FROM atributos AS a
     WHERE a.estado = "ACTIVO"
       AND NOT EXISTS (
           SELECT 1
           FROM categoria_atributos AS ca
           WHERE ca.id_categoria = :id_categoria
             AND ca.id_atributo = a.id_atributo
       )
     ORDER BY a.nombre ASC'
);
$consultaDisponibles->execute(['id_categoria' => $idCategoria]);
$atributosDisponibles = $consultaDisponibles->fetchAll();

$relacionEditar = null;
$idRelacionEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

if ($idRelacionEditar) {
    $consultaEditar = $conexion->prepare(
        'SELECT
            ca.id_categoria_atributo,
            ca.id_atributo,
            ca.requerido,
            ca.orden_visual,
            ca.estado,
            a.nombre
         FROM categoria_atributos AS ca
         INNER JOIN atributos AS a ON a.id_atributo = ca.id_atributo
         WHERE ca.id_categoria_atributo = :id_categoria_atributo
           AND ca.id_categoria = :id_categoria
         LIMIT 1'
    );
    $consultaEditar->execute([
        'id_categoria_atributo' => $idRelacionEditar,
        'id_categoria' => $idCategoria,
    ]);
    $relacionEditar = $consultaEditar->fetch();

    if (!$relacionEditar) {
        http_response_code(404);
        exit('Asignación no encontrada.');
    }
}

$estaEditando = $relacionEditar !== null;
require_once __DIR__ . '/../../../auth/formularios.php';
$datosRecuperados = recuperarFormulario('categoria_atributos', (int) ($relacionEditar['id_categoria_atributo'] ?? 0), ['categoria' => (int) $idCategoria]);
$datosFormulario = array_replace($relacionEditar ?? [], $datosRecuperados);
$formularioVisible = $estaEditando || isset($_GET['crear']);
$urlListado = RUTA_CATEGORIA_ATRIBUTOS . '?categoria=' . (int) $idCategoria;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atributos de <?= htmlspecialchars($categoria['nombre'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/categorias/categoria_atributos/selector.css">
</head>
<body>
    <div class="estructura-admin">
        <?php require __DIR__ . '/../../menu_lateral.php'; ?>

        <div class="area-principal">
        <section class="encabezado-modulo">
                <h1>Atributos de <?= htmlspecialchars($categoria['nombre'], ENT_QUOTES, 'UTF-8') ?></h1>
                <div class="contenedor-volver">
                    <a class="boton volver" href="/sistemaInventario/administracion/categorias">Volver</a>
                </div>
                <?php require __DIR__ . '/../../usuario_encabezado.php'; ?>
            </section>
            <main class="contenido-crud">
                
                <?php if ($mensajeExito !== ''): ?>
                    <div class="alerta exito" role="status"><?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($mensajeError !== ''): ?>
                    <div class="alerta error" role="alert"><?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <div class="column">
                    <section class="tarjeta formulario-area-desplegable" id="formulario-desplegable" <?= $formularioVisible ? '' : 'hidden' ?>>
                        <div class="titulo-tarjeta">
                            <h2><?= $estaEditando ? 'Configurar asignación' : 'Asignar atributo' ?></h2>
                            <p><?= $estaEditando ? 'Modifica el requisito, orden y estado.' : 'Selecciona un atributo disponible.' ?></p>
                        </div>

                        <?php if ($estaEditando): ?>
                            <form action="<?= RUTA_CATEGORIA_ATRIBUTOS ?>/procesar" method="post" class="formulario-crud">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_categoria_atributos'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="accion" value="actualizar">
                                <input type="hidden" name="id_categoria" value="<?= (int) $idCategoria ?>">
                                <input type="hidden" name="id_categoria_atributo" value="<?= (int) $datosFormulario['id_categoria_atributo'] ?>">

                                <div class="campo-formulario">
                                    <label>Atributo</label>
                                    <input type="text" value="<?= htmlspecialchars($datosFormulario['nombre'], ENT_QUOTES, 'UTF-8') ?>" disabled>
                                </div>

                                <label for="requerido">¿Es obligatorio?</label>
                                <select name="requerido" id="requerido" required>
                                    <option value="0" <?= (int) $datosFormulario['requerido'] === 0 ? 'selected' : '' ?>>No</option>
                                    <option value="1" <?= (int) $datosFormulario['requerido'] === 1 ? 'selected' : '' ?>>Sí</option>
                                </select>

                                <label for="estado">Estado</label>
                                <select name="estado" id="estado" required>
                                    <option value="ACTIVO" <?= $datosFormulario['estado'] === 'ACTIVO' ? 'selected' : '' ?>>Activo</option>
                                    <option value="INACTIVO" <?= $datosFormulario['estado'] === 'INACTIVO' ? 'selected' : '' ?>>Inactivo</option>
                                </select>

                                <div class="acciones-formulario">
                                    <button type="submit" class="boton primario">Guardar configuración</button>
                                    <a class="boton secundario" href="<?= RUTA_CATEGORIA_ATRIBUTOS ?>?categoria=<?= (int) $idCategoria ?>">Cancelar</a>
                                </div>
                            </form>
                        <?php elseif ($atributosDisponibles === []): ?>
                            <div class="estado-vacio">No hay atributos activos disponibles para asignar.</div>
                            <a class="boton primario" href="/sistemaInventario/administracion/atributos">Ir al catálogo de atributos</a>
                        <?php else: ?>
                            <form action="<?= RUTA_CATEGORIA_ATRIBUTOS ?>/procesar" method="post" class="formulario-crud">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_categoria_atributos'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="accion" value="asignar">
                                <input type="hidden" name="id_categoria" value="<?= (int) $idCategoria ?>">
                                <div class="container">
                                    <div class="columna-formulario">
                                            <div class="campo-formulario">
                                                <label for="id_atributo">Atributo</label>
                                                <select name="id_atributo" id="id_atributo" required>
                                                    <option value="">Selecciona un atributo</option>
                                                    <?php foreach ($atributosDisponibles as $atributoDisponible): ?>
                                                        <option value="<?= (int) $atributoDisponible['id_atributo'] ?>" <?= (int) ($datosFormulario['id_atributo'] ?? 0) === (int) $atributoDisponible['id_atributo'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($atributoDisponible['nombre'] . ' — ' . $atributoDisponible['tipo_dato'] . ($atributoDisponible['unidad'] !== null ? ' (' . $atributoDisponible['unidad'] . ')' : ''), ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                    </div>
                                    
                                    <div class="columna-formulario">
                                        <div class="campo-formulario">
                                            <label for="requerido">¿Es obligatorio?</label>
                                            <select name="requerido" id="requerido" required>
                                                <option value="0" <?= (int) ($datosFormulario['requerido'] ?? 0) === 0 ? 'selected' : '' ?>>No</option>
                                                <option value="1" <?= (int) ($datosFormulario['requerido'] ?? 0) === 1 ? 'selected' : '' ?>>Sí</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="acciones-formulario">
                                    <button type="submit" class="boton primario">Asignar atributo</button>
                                    <a class="boton secundario" id="cancelar-desplegable" href="<?= htmlspecialchars($urlListado, ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="tarjeta tabla-crud">
                        <div class="contenedor-desplegable encabezado-listado-areas">
                        <div class="titulo-tarjeta">
                            <h2>Atributos asignados</h2>
                            <p>Consulta y configura los atributos de esta categoría.</p>
                         <span class="contador"><?= count($atributosAsignados) ?> asignados</span>
                        </div>
                            <div class="boton-desplegable">
                                <a class="boton boton-agregar-area" id="abrir-desplegable"
                                    href="<?= htmlspecialchars($urlListado . ($formularioVisible ? '' : '&crear=1'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-listado="<?= htmlspecialchars($urlListado, ENT_QUOTES, 'UTF-8') ?>"
                                    data-editando="<?= $estaEditando ? 'true' : 'false' ?>" data-etiqueta="Asignar atributo"
                                    aria-controls="formulario-desplegable" aria-expanded="<?= $formularioVisible ? 'true' : 'false' ?>">
                                    <span aria-hidden="true"><?= $formularioVisible ? '×' : '+' ?></span>
                                    <?= $formularioVisible ? 'Cerrar' : 'Asignar atributo' ?>
                                </a>
                            </div>
                        </div>

                        <?php if ($atributosAsignados === []): ?>
                            <div class="estado-vacio">Esta categoría todavía no tiene atributos asignados.</div>
                        <?php else: ?>
                            <div class="tabla-contenedor">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Orden</th>
                                            <th>Código</th>
                                            <th>Atributo</th>
                                            <th>Unidad</th>
                                            <th>Requerido</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($atributosAsignados as $atributo): ?>
                                            <tr>
                                                <td><?= (int) $atributo['orden_visual'] ?></td>
                                                <td>
                                                    <?= htmlspecialchars($atributo['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if ($atributo['estado_atributo'] === 'INACTIVO'): ?>
                                                        <small>(inactivo globalmente)</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($atributo['tipo_dato'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($atributo['unidad'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= (int) $atributo['requerido'] === 1 ? 'Sí' : 'No' ?></td>
                                                <td><span class="estado <?= strtolower($atributo['estado_asignacion']) ?>"><?= htmlspecialchars($atributo['estado_asignacion'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td>
                                                    <div class="acciones-tabla">
                                                        <a class="boton-tabla editar" href="<?= RUTA_CATEGORIA_ATRIBUTOS ?>?categoria=<?= (int) $idCategoria ?>&editar=<?= (int) $atributo['id_categoria_atributo'] ?>">Editar</a>
                                                        <form action="<?= RUTA_CATEGORIA_ATRIBUTOS ?>/procesar" method="post" data-confirmar="¿Está seguro de cambiar el estado?">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_categoria_atributos'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="cambiar_estado">
                                                            <input type="hidden" name="id_categoria" value="<?= (int) $idCategoria ?>">
                                                            <input type="hidden" name="id_categoria_atributo" value="<?= (int) $atributo['id_categoria_atributo'] ?>">
                                                            <input type="hidden" name="estado" value="<?= $atributo['estado_asignacion'] === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO' ?>">
                                                            <button class="boton-tabla" type="submit"><?= $atributo['estado_asignacion'] === 'ACTIVO' ? 'Desactivar' : 'Activar' ?></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                    </section>
                </div>
            </main>
        </div>
    </div>
    <script src="/sistemaInventario/admin/js/formulario-desplegable.js"></script>
    <script src="/sistemaInventario/admin/categorias/categoria_atributos/selector.js"></script>
    <script src="/sistemaInventario/admin/js/confirmaciones.js"></script>
</body>
</html>

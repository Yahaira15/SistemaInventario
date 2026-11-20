<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/sesion.php';
require_once __DIR__ . '/../../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

const RUTA_OPCIONES = '/sistemaInventario/administracion/atributos/opciones';

if (empty($_SESSION['csrf_atributo_opciones'])) {
    $_SESSION['csrf_atributo_opciones'] = bin2hex(random_bytes(32));
}

$conexion = require __DIR__ . '/../../../config/conexion.php';
$seccionActiva = 'atributos';

$mensajeExito = (string) ($_SESSION['mensajeExito'] ?? '');
$mensajeError = (string) ($_SESSION['mensajeError'] ?? '');
unset($_SESSION['mensajeExito'], $_SESSION['mensajeError']);

$idAtributo = filter_input(INPUT_GET, 'atributo', FILTER_VALIDATE_INT);

if (!$idAtributo) {
    http_response_code(400);
    exit('Atributo no válido.');
}

$consultaAtributo = $conexion->prepare(
    'SELECT id_atributo, nombre, tipo_dato, estado
     FROM atributos
     WHERE id_atributo = :id_atributo
     LIMIT 1'
);
$consultaAtributo->execute(['id_atributo' => $idAtributo]);
$atributo = $consultaAtributo->fetch();

if (!$atributo) {
    http_response_code(404);
    exit('Atributo no encontrado.');
}

if ($atributo['tipo_dato'] !== 'SELECCION') {
    http_response_code(400);
    exit('Este atributo no utiliza opciones de selección.');
}

if ($atributo['estado'] !== 'ACTIVO') {
    $_SESSION['mensajeError'] = 'Activa el atributo antes de administrar sus opciones.';
    header('Location: /sistemaInventario/administracion/atributos');
    exit;
}

$consultaOpciones = $conexion->prepare(
    'SELECT id_atributo_opcion, valor, orden_visual, estado
     FROM atributo_opciones
     WHERE id_atributo = :id_atributo
     ORDER BY orden_visual ASC, valor ASC'
);
$consultaOpciones->execute(['id_atributo' => $idAtributo]);
$opciones = $consultaOpciones->fetchAll();

$opcionEditar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

if ($idEditar) {
    $consultaEditar = $conexion->prepare(
        'SELECT id_atributo_opcion, valor, orden_visual, estado
         FROM atributo_opciones
         WHERE id_atributo_opcion = :id_atributo_opcion
           AND id_atributo = :id_atributo
         LIMIT 1'
    );
    $consultaEditar->execute([
        'id_atributo_opcion' => $idEditar,
        'id_atributo' => $idAtributo,
    ]);
    $opcionEditar = $consultaEditar->fetch();

    if (!$opcionEditar) {
        http_response_code(404);
        exit('Opción no encontrada.');
    }
}

$estaEditando = $opcionEditar !== null;
require_once __DIR__ . '/../../../auth/formularios.php';
$datosRecuperados = recuperarFormulario('atributo_opciones', (int) ($opcionEditar['id_atributo_opcion'] ?? 0), ['atributo' => (int) $idAtributo]);
$datosFormulario = array_replace($opcionEditar ?? [], $datosRecuperados);
$formularioVisible = $estaEditando || isset($_GET['crear']);
$urlListado = RUTA_OPCIONES . '?atributo=' . (int) $idAtributo;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opciones de <?= htmlspecialchars($atributo['nombre'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
</head>
<body>
    <div class="estructura-admin">
        <?php require __DIR__ . '/../../menu_lateral.php'; ?>
        <div class="area-principal">
<section class="encabezado-modulo">
                    <h1>Opciones de <?= htmlspecialchars($atributo['nombre'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="contenedor-volver">
                        <a class="boton volver" href="/sistemaInventario/administracion/atributos">Volver</a>
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
                            <h2><?= $estaEditando ? 'Editar opción' : 'Nueva opción' ?></h2>
                        </div>
                        <form action="<?= RUTA_OPCIONES ?>/procesar" method="post" class="formulario-crud">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_atributo_opciones'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="accion" value="<?= $estaEditando ? 'actualizar' : 'crear' ?>">
                            <input type="hidden" name="id_atributo" value="<?= (int) $idAtributo ?>">
                            <?php if ($estaEditando): ?>
                                <input type="hidden" name="id_atributo_opcion" value="<?= (int) $datosFormulario['id_atributo_opcion'] ?>">
                            <?php endif; ?>

                            <label for="valor">Opción</label>
                            <input type="text" id="valor" name="valor" minlength="1" maxlength="100" value="<?= htmlspecialchars($datosFormulario['valor'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>

                            <?php if ($estaEditando): ?>
                                <label for="estado">Estado</label>
                                <select id="estado" name="estado" required>
                                    <option value="ACTIVO" <?= $datosFormulario['estado'] === 'ACTIVO' ? 'selected' : '' ?>>Activo</option>
                                    <option value="INACTIVO" <?= $datosFormulario['estado'] === 'INACTIVO' ? 'selected' : '' ?>>Inactivo</option>
                                </select>
                            <?php endif; ?>

                            <div class="acciones-formulario">
                                <button class="boton primario" type="submit"><?= $estaEditando ? 'Guardar cambios' : 'Crear opción' ?></button>
                                <a class="boton secundario" id="cancelar-desplegable" href="<?= htmlspecialchars($urlListado, ENT_QUOTES, 'UTF-8') ?>">Cancelar</a>
                            </div>
                        </form>
                    </section>

                    <section class="tarjeta tabla">
                        <div class="contenedor-desplegable encabezado-listado-areas">
                        <div class="titulo-tarjeta">
                            <h2>Opciones registradas</h2>
                            <span class="contador"><?= count($opciones) ?> opciones</span>         
                        </div>
                            <div class="boton-desplegable">
                                <a class="boton boton-agregar-area" id="abrir-desplegable"
                                    href="<?= htmlspecialchars($urlListado . ($formularioVisible ? '' : '&crear=1'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-listado="<?= htmlspecialchars($urlListado, ENT_QUOTES, 'UTF-8') ?>"
                                    data-editando="<?= $estaEditando ? 'true' : 'false' ?>" data-etiqueta="Crear"
                                    aria-controls="formulario-desplegable" aria-expanded="<?= $formularioVisible ? 'true' : 'false' ?>">
                                    <span aria-hidden="true"><?= $formularioVisible ? '×' : '+' ?></span>
                                    <?= $formularioVisible ? 'Cerrar' : 'Crear' ?>
                                </a>
                            </div>
                        </div>
                        <?php if ($opciones === []): ?>
                            <div class="estado-vacio">Este atributo todavía no tiene opciones.</div>
                        <?php else: ?>
                            <div class="tabla-contenedor">
                                <table>
                                    <thead><tr><th>Orden</th><th>Opción</th><th>Estado</th><th>Acciones</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($opciones as $opcion): ?>
                                            <tr>
                                                <td><?= (int) $opcion['orden_visual'] ?></td>
                                                <td><?= htmlspecialchars($opcion['valor'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><span class="estado <?= strtolower($opcion['estado']) ?>"><?= htmlspecialchars($opcion['estado'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td><div class="acciones-tabla">
                                                    <a class="boton-tabla editar" href="<?= RUTA_OPCIONES ?>?atributo=<?= (int) $idAtributo ?>&editar=<?= (int) $opcion['id_atributo_opcion'] ?>">Editar</a>
                                                    <form action="<?= RUTA_OPCIONES ?>/procesar" method="post" data-confirmar="¿Está seguro de cambiar el estado?">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_atributo_opciones'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="accion" value="cambiar_estado">
                                                        <input type="hidden" name="id_atributo" value="<?= (int) $idAtributo ?>">
                                                        <input type="hidden" name="id_atributo_opcion" value="<?= (int) $opcion['id_atributo_opcion'] ?>">
                                                        <input type="hidden" name="estado" value="<?= $opcion['estado'] === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO' ?>">
                                                        <button class="boton-tabla" type="submit"><?= $opcion['estado'] === 'ACTIVO' ? 'Desactivar' : 'Activar' ?></button>
                                                    </form>
                                                </div></td>
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
    <script src="/sistemaInventario/admin/js/confirmaciones.js"></script>
</body>
</html>

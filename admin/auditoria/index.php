<?php

declare(strict_types=1);

require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();

$conexion = require __DIR__ . '/../../config/conexion.php';

require __DIR__ . '/filtros.php';

$seccionActiva = 'auditoria';

require_once __DIR__ . '/presentacion.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditoría | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/admin/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/css/crud.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/inventario/inventario.css">
    <link rel="stylesheet" href="/sistemaInventario/admin/auditoria/modal.css"
>
</head>
<body>
<div class="estructura-admin">
    <?php require __DIR__ . '/../menu_lateral.php'; ?>

    <div class="area-principal">
        <section class="encabezado-modulo">
            <h1>Auditoría</h1>
            <?php require __DIR__ . '/../usuario_encabezado.php'; ?>
        </section>
        <main class="contenido-crud">
            <section class="tarjeta tabla-crud">
                <div class="titulo-tarjeta encabezado-listado-inventario">
                    <div>
                        <h2>Historial de operaciones</h2>
                        <p>Operaciones registradas en los módulos del sistema desde su integración con auditoría.</p>
                    
                    </div>  
                    
                    <button
                        type="submit"
                        form="form-filtros-auditoria"
                        class="boton exportar boton-exportar-excel"
                        formaction="/sistemaInventario/administracion/auditoria/exportable"
                        title="Exportar todos los eventos que coincidan con estos filtros"
                    >
                    <svg class="icono-excel" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <path d="M14 2v6h6M8 12h8v6H8zM8 15h8M12 12v6" />
                    </svg>
                    Exportar Excel</button>
                </div>

                <!-- FORMULARIO PARA RALIZAR LOS FILTROS DE LA TABLA DE AUDITORIA -->
                <div class="panel-filtros-auditoria">
                    <form 
                        id="form-filtros-auditoria"
                        method="get"
                        action="/sistemaInventario/administracion/auditoria"
                        class="filtros-auditoria"
                    >
                        <div>
                            <label for="auditoria-desde">Desde</label>
                            <input 
                                type="date"
                                id="auditoria-desde"
                                name="desde"
                                value="<?= escaparAuditoria($filtros['desde']) ?>"
                            >
                        </div>

                        <div>
                            <label for="auditoria-hasta">Hasta</label>
                            <input 
                                type="date"
                                id="auditoria-hasta"
                                name="hasta"
                                value="<?= escaparAuditoria($filtros['hasta']) ?>"
                            >
                        </div>

                        <div>
                            <label for="auditoria-modulo">Módulo</label>
                            <select id="auditoria-modulo" name="modulo">
                                <option value="">Todos los módulos</option>

                                <?php foreach($modulosAuditoria as $valor => $nombre): ?>
                                    <option 
                                        value="<?= escaparAuditoria($valor) ?>"
                                        <?= $filtros['modulo'] === $valor ? 'selected' : '' ?>
                                    >
                                        <?= escaparAuditoria($nombre) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="auditoria-accion">Acción</label>
                            <select name="accion" id="auditoria-accion">
                                <option value="">Todas las acciones</option>

                                <?php foreach($accionesAuditoria as $valor => $nombre): ?>
                                    <option 
                                        value="<?= escaparAuditoria($valor) ?>"
                                        <?= $filtros['accion'] === $valor ? 'selected' : '' ?>
                                    >
                                        <?= escaparAuditoria($nombre) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="auditoria-administrador">Administrador</label>
                            <input 
                                type="search" 
                                id="auditoria-administrador"
                                name="administrador"
                                placeholder="Nombre del administrador"
                                maxlength="161"
                                value="<?= escaparAuditoria($filtros['administrador']) ?>"
                            >
                        </div>

                        <div>
                            <label for="auditoria-codigo">Código del equipo</label>
                            <input 
                                type="search"
                                id="auditoria-codigo"
                                name="codigo"
                                placeholder="Ejemplo: ADP-PC001"
                                maxlength="80"
                                value="<?= escaparAuditoria($filtros['codigo']) ?>"
                            >
                        </div>

                        <div class="acciones-filtros-auditoria">
                            <button type="submit" class="boton primario">Filtrar</button>
                            <a 
                                href="/sistemaInventario/administracion/auditoria"
                                class="boton secundario"
                            >Limpiar</a>
                        </div>
                    </form>
                    
                    <?php if($erroresFiltros): ?>
                        <p class="alerta error" role="alert">
                            <?= escaparAuditoria(
                                implode(' ', array_unique($erroresFiltros))
                            ) ?>
                        </p>
                    <?php endif; ?>

                    <p class="resultados-auditoria">
                        Resultados: <strong><?= $total ?></strong>
                    </p>
                </div>

                <div class="tabla-contenedor">
                    <table>
                        <thead>
                            <tr>
                                <th>Evento</th>
                                <th>Fecha</th>
                                <th>Administrador</th>
                                <th>Módulo</th>
                                <th>Acción</th>
                                <th>Registro</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($eventos as $evento): ?>
                            <tr>
                                <td><?= escaparAuditoria($evento['id_evento']) ?></td>
                                <td><?= escaparAuditoria($evento['fecha']) ?></td>
                                <td><?= escaparAuditoria($evento['administrador_nombre']) ?></td>
                                <td><?= escaparAuditoria($evento['modulo']) ?></td>
                                <td><?= escaparAuditoria($evento['accion']) ?></td>
                                <td>
                                    <?php if (in_array($evento['entidad'], ['productos', 'producto_asignaciones'], true)): ?>
                                    <strong><?= escaparAuditoria(codigoRegistroAuditoria($evento)) ?></strong>
                                    <br>
                                    <?php endif; ?>
                                    <?= escaparAuditoria(nombreRegistroAuditoria($evento)) ?>
                                    <br>
                                    <small>
                                        <?= escaparAuditoria(
                                            $evento['entidad'] === 'producto_asignaciones'
                                                ? 'Asignación'
                                                : (['productos' => 'Producto', 'usuarios' => 'Usuario', 'areas' => 'Área', 'categorias' => 'Categoría', 'atributos' => 'Atributo', 'atributo_opciones' => 'Opción', 'categoria_atributos' => 'Configuración'][$evento['entidad']] ?? $evento['entidad'])
                                        ) ?> #<?= escaparAuditoria($evento['id_registro']) ?>
                                    </small>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="boton administracion"
                                        data-ver-cambios
                                        data-evento="<?= escaparAuditoria($evento['id_evento']) ?>"
                                        data-titulo="<?= escaparAuditoria(
                                            in_array(
                                                $evento['entidad'],
                                                ['productos', 'producto_asignaciones'],
                                                true
                                            )
                                                ? codigoRegistroAuditoria($evento)
                                                : nombreRegistroAuditoria($evento)
                                        ) ?>"
                                        >Ver cambios</button>

                                    <template class="contenido-cambios">
                                        <?php $contextoEvento = contextoEventoAuditoria($evento); ?>
                                        <div class="resumen-auditoria <?= $contextoEvento ? 'resumen-auditoria-dividido' : '' ?>">
                                            <?php if ($contextoEvento): ?>
                                                <section class="resumen-auditoria-columna" aria-label="Detalle del producto">
                                                    <h3>Detalle del producto</h3>
                                                    <dl>
                                                        <?php foreach ($contextoEvento as $etiqueta => $valor): ?>
                                                            <div>
                                                                <dt><?= escaparAuditoria($etiqueta) ?></dt>
                                                                <dd><?= escaparAuditoria($valor) ?></dd>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                </section>
                                            <?php endif; ?>
                                            <section class="resumen-auditoria-columna" aria-label="Detalle de la transacción">
                                                <h3>Detalle de la transacción</h3>
                                                <dl>
                                                    <div>
                                                        <dt>Administrador</dt>
                                                        <dd><?= escaparAuditoria($evento['administrador_nombre']) ?></dd>
                                                    </div>
                                                    <div>
                                                        <dt>Acción</dt>
                                                        <dd><?= escaparAuditoria($accionesAuditoria[$evento['accion']] ?? $evento['accion']) ?></dd>
                                                    </div>
                                                    <div>
                                                        <dt>Fecha</dt>
                                                        <dd><?= escaparAuditoria($evento['fecha']) ?></dd>
                                                    </div>
                                                </dl>
                                            </section>
                                        </div>

                                        <?= presentarDatosAuditoria(
                                            $evento['datos_anteriores'],
                                            $evento['datos_nuevos']
                                        ) ?>
                                    </template>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$eventos): ?>
                            <tr>
                                <td colspan="7">
                                    No se encontraron operaciones para los filtros seleccionados.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPaginas > 1): ?>
                    <nav class="paginacion" aria-label="Páginas de auditoría">
                        <?php if ($pagina > 1): ?>
                            <a href="<?= escaparAuditoria(urlPaginaAuditoria($pagina - 1, $filtros)) ?>"
                               class="pagina-enlace" aria-label="Página anterior">‹</a>
                        <?php endif; ?>

                        <?php foreach (paginasAuditoria($pagina, $totalPaginas) as $numeroPagina): ?>
                            <?php if ($numeroPagina === null): ?>
                                <span class="paginacion-elipsis" aria-hidden="true">…</span>
                            <?php else: ?>
                                <a href="<?= escaparAuditoria(urlPaginaAuditoria($numeroPagina, $filtros)) ?>"
                                   class="pagina-enlace <?= $numeroPagina === $pagina ? 'activa' : '' ?>"
                                   aria-label="Página <?= $numeroPagina ?>"
                                   <?= $numeroPagina === $pagina ? 'aria-current="page"' : '' ?>
                                ><?= $numeroPagina ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if ($pagina < $totalPaginas): ?>
                            <a href="<?= escaparAuditoria(urlPaginaAuditoria($pagina + 1, $filtros)) ?>"
                               class="pagina-enlace" aria-label="Página siguiente">›</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
        </main> 
    </div>
</div>
<dialog id="modal-auditoria" aria-labelledby="titulo-modal-auditoria">
    <div class="modal-auditoria-encabezado">
        <h2 id="titulo-modal-auditoria">Detalle de la operación</h2>

        <form method="dialog">
            <button type="submit" class="cerrar" autofocus>
                ×
            </button>
        </form>
    </div>

    <div id="detalle-auditoria"></div>
</dialog>

<script src="/sistemaInventario/admin/auditoria/modal.js"></script>
</body>
</html>

<?php

declare(strict_types=1);
require_once __DIR__ . '/../auditoria/cambios.php';

// Inicia la sesión y restringe este procesador a administradores autenticados.
require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/../inventario/desactivacion.php';

iniciarSesionSegura();
exigirAdministrador();
require_once __DIR__ . '/../../auth/formularios.php';
unset($_SESSION['mensajeError'], $_SESSION['mensajeExito']);

const RUTA_CATEGORIAS = '/sistemaInventario/administracion/categorias';

/*
  Regresa al listado mediante el patrón POST/Redirect/GET.
  Esto evita que el navegador reenvíe el formulario al recargar la página.
 */
function volverACategorias(): never
{
    header('Location: ' . urlRetornoFormulario('categorias', RUTA_CATEGORIAS, []));
    exit;
}

// Las operaciones que modifican usuarios solo se aceptan mediante POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

// Comprueba que la solicitud provenga de un formulario legítimo del módulo.
$csrfToken = textoEntrada($_POST, 'csrf_token');

if (
    empty($_SESSION['csrf_categorias'])
    || !hash_equals($_SESSION['csrf_categorias'], $csrfToken)
) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

// Limita la solicitud a las acciones conocidas por este procesador.
$accion = textoEntrada($_POST, 'accion');
$accionesPermitidas = ['crear', 'actualizar', 'cambiar_estado'];

if (!in_array($accion, $accionesPermitidas, true)) {
    http_response_code(400);
    exit('Acción no válida.');
}

$conexion = require __DIR__ . '/../../config/conexion.php';

/*
  CREACIÓN Y ACTUALIZACIÓN
  Estas acciones comparten la recepción y validación de los datos.
 */
if ($accion === 'crear' || $accion === 'actualizar') {
    $idCategoria = $accion === 'actualizar'
        ? filter_input(INPUT_POST, 'id_categoria', FILTER_VALIDATE_INT)
        : null;

    // Normaliza los valores antes de validarlos y enviarlos a la base de datos.
    $nombre = trim(textoEntrada($_POST, 'nombre'));
    $descripcion = trim(textoEntrada($_POST, 'descripcion'));
    $estado = $accion === 'actualizar'
        ? textoEntrada($_POST, 'estado') : 'ACTIVO';

    // El prefijo es opcional, pero debe recibirse como texto
    $prefijoRecibido = $_POST['prefijo_codigo'] ?? '';
        
    if (!is_string($prefijoRecibido)) {
        $_SESSION['mensajeError'] = 'El formato del prefijo no es válido.';
        volverACategorias();
    }

    // Convierte a mayúsculas y elimina espacios de los extremos.
    $prefijoCodigo = mb_strtoupper(trim($prefijoRecibido), 'UTF-8');

    // Si se proporciona, admite letras, números y guiones.
    // Debe comenzar con una letra y tener máximo 20 caracteres.
    if (
        $prefijoCodigo !== ''
        && !preg_match('/^[A-Z][A-Z0-9-]{0,19}$/D', $prefijoCodigo)
    ) {
        $_SESSION['mensajeError'] =
            'El prefijo debe comenzar con una letra y contener '
            . 'máximo 20 caracteres: letras, números o guiones, sin espacios.';

        volverACategorias();
    }

    // Valida campos obligatorios, longitudes, identificador y estado permitido.
    if (
        ($accion === 'actualizar' && !$idCategoria)
        || $nombre === ''
        || mb_strlen($nombre) < 2
        || mb_strlen($nombre) > 80
        || $descripcion === ''
        || mb_strlen($descripcion) > 120
        || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)
    ) {
        $_SESSION['mensajeError'] = 'Revisa el nombre y la descripción.';
        volverACategorias();
    }

    try {
        $conexion->beginTransaction();
        $antesAuditoria = consultarRegistroAuditoria($conexion, 'categorias', $idCategoria ?: null);

        if ($accion === 'actualizar') {
            // Usa el mismo bloqueo que la generación automática de códigos.
            $consultaActual = $conexion->prepare(
                'SELECT prefijo_codigo
                 FROM categorias
                 WHERE id_categoria = :id_categoria
                 FOR UPDATE'
            );

            $consultaActual->execute(['id_categoria' => $idCategoria]);
            $categoriaActual = $consultaActual->fetch(PDO::FETCH_ASSOC);

            if (!$categoriaActual) {
                throw new DomainException('La categoría no existe.');
            }

            $prefijoActual = (string) ($categoriaActual['prefijo_codigo'] ?? '');
            if ($estado === 'INACTIVO') {
                exigirCatalogoSinAsignaciones($conexion, 'categoria', $idCategoria);
            }

            if ($prefijoCodigo !== $prefijoActual) {
                $consultaCodigos = $conexion->prepare(
                    'SELECT id_codigo
                    FROM codigos_inventario
                    WHERE id_categoria = :id_categoria
                    LIMIT 1
                    FOR UPDATE '
                );
            
                $consultaCodigos->execute(['id_categoria' => $idCategoria]);

                if ($consultaCodigos->fetchColumn() !== false) {
                    throw new DomainException(
                        'No puedes cambiar el prefijo porque esta categoría '
                        . 'ya tiene códigos registrados.'
                    );
                }
            }
        }

        /* Comprueba el nombre de la categoría antes de guardarlo
            si ya esxiste la categoria muestra un mensaje de error
        */
        if ($accion === 'crear') {
            $verificarNombre = $conexion->prepare(
                'SELECT id_categoria
             FROM categorias
             WHERE nombre = :nombre
             LIMIT 1'
            );
            $verificarNombre->execute(['nombre' => $nombre]);
        } else {
            $verificarNombre = $conexion->prepare(
                'SELECT id_categoria
                 FROM categorias
                 WHERE nombre = :nombre
                    AND id_categoria <> :id_categoria
                    LIMIT 1'
            );
            $verificarNombre->execute([
                'nombre' => $nombre,
                'id_categoria' => $idCategoria
            ]);
        }

        if ($verificarNombre->fetchColumn()) {
            throw new DomainException('Ya existe una categoría con este nombre');
        }

        if ($accion === 'crear') {
            // Las categroias siempre comienzan con estado ACTIVO.
            $guardar = $conexion->prepare(
                'INSERT INTO categorias
                     (nombre, descripcion, estado, prefijo_codigo)
                 VALUES 
                     (:nombre, :descripcion, "ACTIVO", :prefijo_codigo)'
            );
            $guardar->execute([
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'prefijo_codigo' => $prefijoCodigo === '' ? null : $prefijoCodigo
            ]);

            $idCategoria = (int) $conexion->lastInsertId();
            $_SESSION['mensajeExito'] = 'Categoría creada correctamente.';
        } else {
        
            // Actualiza solamente el registro identificado por su clave primaria.
            $guardar = $conexion->prepare(
                'UPDATE categorias
                 SET nombre = :nombre,
                     descripcion = :descripcion,
                     estado = :estado,
                     prefijo_codigo = :prefijo_codigo
                 WHERE id_categoria = :id_categoria'
            );
            $guardar->execute([
                'id_categoria' => $idCategoria,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'estado' => $estado,
                'prefijo_codigo' => $prefijoCodigo === '' ? null : $prefijoCodigo
            ]);

            $_SESSION['mensajeExito'] = $guardar->rowCount() === 1
                ? 'Categoría  actualizada correctamente.'
                : 'No se realizaron cambios en la categoría.';
        }

        registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'categorias', (int) $idCategoria, $antesAuditoria);
        $conexion->commit();

    } catch (DomainException $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        unset($_SESSION['mensajeExito']);
        $_SESSION['mensajeError'] = $error->getMessage();

    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        unset($_SESSION['mensajeExito']);

        if (
            $error instanceof PDOException
            && (int) ($error->errorInfo[1] ?? 0) === 1062
        ) {
            $_SESSION['mensajeError'] = str_contains(
                $error->getMessage(),
                'uq_categoria_prefijo'
            )
                ? 'Ese prefijo ya pertenece a otra categoría.'
                : 'Ya existe una categoría con ese nombre.';
        } else {
            error_log('Error al guardar la categoría: ' . $error->getMessage());

            $_SESSION['mensajeError'] =
                'No fue posible guardar los datos de la categoría.';
        }
    }

    volverACategorias();

}

/*
 * CAMBIO DE ESTADO
 * Si el flujo llega hasta aquí, la acción validada es cambiar_estado.
 */
$idCategoria = filter_input(INPUT_POST, 'id_categoria', FILTER_VALIDATE_INT);
$estado = textoEntrada($_POST, 'estado');

if (!$idCategoria || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado.';
    volverACategorias();
}

try {
    $conexion->beginTransaction();
    $antesAuditoria = consultarRegistroAuditoria($conexion, 'categorias', $idCategoria ?: null);
    if ($estado === 'INACTIVO') {
        exigirCatalogoSinAsignaciones($conexion, 'categoria', $idCategoria);
    }
    $actualizar = $conexion->prepare(
        'UPDATE categorias
         SET estado = :estado
         WHERE id_categoria = :id_categoria'
    );
    $actualizar->execute([
        'estado' => $estado,
        'id_categoria' => $idCategoria
    ]);

    registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'categorias', (int) $idCategoria, $antesAuditoria);
    $conexion->commit();
    if ($actualizar->rowCount() === 1) {
        $_SESSION['mensajeExito'] = 'Estado actualizado correctamente.';
    } else {
        $_SESSION['mensajeError'] = 'La categoría no existe o ya tenía ese estado.';
    }
} catch (DomainException $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    $_SESSION['mensajeError'] = $error->getMessage();
} catch (Throwable $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('Error al cambiar el estado de la categoría: ' . $error->getMessage());
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado.';
}

volverACategorias();

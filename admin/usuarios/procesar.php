<?php

declare(strict_types=1);
require_once __DIR__ . '/../auditoria/cambios.php';

// Inicia la sesión y restringe este procesador a administradores autenticados.
require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/servicio.php';
require_once __DIR__ . '/imagenes.php';

iniciarSesionSegura();
exigirAdministrador();
require_once __DIR__ . '/../../auth/formularios.php';
unset($_SESSION['mensajeError'], $_SESSION['mensajeExito']);

const RUTA_USUARIOS = '/sistemaInventario/administracion/usuarios';

/*
  Regresa al listado mediante el patrón POST/Redirect/GET.
  Esto evita que el navegador reenvíe el formulario al recargar la página.
 */
function volverAUsuarios(): never
{
    header('Location: ' . urlRetornoFormulario('usuarios', RUTA_USUARIOS, []));
    exit;
}

function protegerAdministradores(
    PDO $conexion,
    int $idUsuario,
    ?string $nuevoRol,
    string $nuevoEstado
): void {
    if (!$conexion->inTransaction()) {
        throw new LogicException('La protección requiere una transacción.');
    }

    $consulta = $conexion->query(
        'SELECT u.id_usuario
            FROM usuarios AS u
            INNER JOIN cuentas_acceso AS c
            ON c.id_usuario = u.id_usuario
            WHERE u.rol = "ADMINISTRADOR"
            AND u.estado = "ACTIVO"
            AND c.estado = "ACTIVO"
            ORDER BY u.id_usuario
            FOR UPDATE'
    );

    $administradores = array_map(
        'intval',
        $consulta->fetchAll(PDO::FETCH_COLUMN)
    );

    $idEjecutor = (int) ($_SESSION['administrador_id'] ?? 0);

    if (!in_array($idEjecutor, $administradores, true)) {
        throw new DomainException('Tu usuario ya no esta habilitado para administrar ');
    }

    // null significa que esta operacion no cambia el rol.
    $retiraAcceso = $nuevoEstado != 'ACTIVO'
        || ($nuevoRol !== null && $nuevoRol !== 'ADMINISTRADOR');

    if (!$retiraAcceso) {
        return;
    }

    if ($idUsuario === $idEjecutor) {
        throw new DomainException(
            'No puedes desactivar tu usuario ni quitar tu propio rol.'
        );
    }

    if (
        in_array($idUsuario, $administradores, true)
        && count($administradores) <= 1
    ) {
        throw new DomainException(
            'Debe permanecer al menos un administrador activo.'
        );
    }

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
    empty($_SESSION['csrf_usuarios'])
    || !hash_equals($_SESSION['csrf_usuarios'], $csrfToken)
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
  Estas acciones comparten la recepción y validación de los datos personales.
 */
if ($accion === 'crear' || $accion === 'actualizar') {
    $idUsuario = $accion === 'actualizar'
        ? filter_input(INPUT_POST, 'id_usuario', FILTER_VALIDATE_INT)
        : null;

    // Normaliza los valores antes de validarlos y enviarlos a la base de datos.
    $cedula = strtoupper(trim(textoEntrada($_POST, 'cedula')));
    $nombre = trim(textoEntrada($_POST, 'nombre'));
    $apellido = trim(textoEntrada($_POST, 'apellido'));
    $correo = strtolower(trim(textoEntrada($_POST, 'correo')));
    $idArea = filter_input(INPUT_POST, 'id_area', FILTER_VALIDATE_INT);
    $rol = strtoupper(trim(textoEntrada($_POST, 'rol')));
    $contrasena = textoEntrada($_POST, 'contrasena');
    $estado = $accion === 'actualizar'
        ? textoEntrada($_POST, 'estado') : 'ACTIVO';

    if (!preg_match('/^\d{4,20}$/', $cedula)) {
        $_SESSION['mensajeError'] = 'La cédula contiene caracteres no permitidos.';
        volverAUsuarios();
    }

    if (!preg_match("/^[\p{L} '\-]+$/u", $nombre)) {
        $_SESSION['mensajeError'] = 'El nombre contiene caracteres no permitidos.';
        volverAUsuarios();
    }

    if (!preg_match("/^[\p{L} '\-]+$/u", $apellido)) {
        $_SESSION['mensajeError'] = 'El apellido contiene caracteres no permitidos.';
        volverAUsuarios();
    }

    $nombre = preg_replace('/\s+/u', ' ', $nombre);
    $apellido = preg_replace('/\s+/u', ' ', $apellido);

    // Valida campos obligatorios, longitudes, identificador y estado permitido.
    if (
        ($accion === 'actualizar' && !$idUsuario)
        || $cedula === ''
        || mb_strlen($cedula) > 20
        || $nombre === ''
        || mb_strlen($nombre) > 80
        || $apellido === ''
        || mb_strlen($apellido) > 80
        || $correo === ''
        || mb_strlen($correo) > 255
        || !$idArea
        || !in_array($rol, ['FUNCIONARIO', 'ADMINISTRADOR'], true)
        || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)
        || strlen($contrasena) > 255
    ) {
        $_SESSION['mensajeError'] = 'Revisa los datos del usuario.';
        volverAUsuarios();
    }

    // FILTER_VALIDATE_EMAIL comprueba la estructura del correo en el servidor.
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['mensajeError'] = 'Debes ingresar un correo válido.';
        volverAUsuarios();
    }

    if ($contrasena !== '' && strlen($contrasena) < 8) {
        $_SESSION['mensajeError'] = 'La contraseña debe tener al menos 8 caracteres.';
        volverAUsuarios();
    }

    $esUsuarioActual =
        $accion === 'actualizar'
        && $idUsuario === (int) ($_SESSION['administrador_id'] ?? 0);

    if ($esUsuarioActual && $estado === 'INACTIVO') {
        $_SESSION['mensajeError'] = 'No puedes desactivar tu propio usuario.';
        volverAUsuarios();
    }

    if ($esUsuarioActual && $rol !== 'ADMINISTRADOR') {
        $_SESSION['mensajeError'] = 'No pudes quitar tu propio rol de administrador.';
        volverAUsuarios();
    }

    // Evita que el administrador conectado pierda el acceso al desactivarse.
    if (
        $idUsuario === (int) ($_SESSION['administrador_id'] ?? 0)
        && $estado === 'INACTIVO'
    ) {
        $_SESSION['mensajeError'] = 'No puedes desactivar tu propio usuario.';
        volverAUsuarios();
    }

    try {
        $imagenNueva = leerImagenUsuario($_FILES['imagen'] ?? null);
        $conexion->beginTransaction();

        if ($accion === 'actualizar') {
            protegerAdministradores(
                $conexion,
                $idUsuario,
                $rol,
                $estado
            );
        }

        $antesAuditoria = consultarRegistroAuditoria($conexion, 'usuarios', $idUsuario ?: null);

        // Bloquea el área mientras se guarda el usuario. Así no puede ser
        // desactivada entre la validación y la escritura del registro.
        $verificarArea = $conexion->prepare(
            'SELECT a.id_area
             FROM areas AS a
             WHERE a.id_area = :id_area
               AND (
                   a.estado = "ACTIVO"
                   OR EXISTS (
                       SELECT 1
                       FROM usuarios AS u
                       WHERE u.id_usuario = :id_usuario
                         AND u.id_area = a.id_area
                   )
               )
             LIMIT 1
             FOR UPDATE'
        );
        $verificarArea->execute([
            'id_area' => $idArea,
            'id_usuario' => $idUsuario ?? 0,
        ]);

        if ($verificarArea->fetchColumn() === false) {
            throw new DomainException('Selecciona un área activa y válida.');
        }

        $rolAnterior = null;
        $cuentaExistente = null;

        // Verificar que la cédula del usuario no se repita antes de guardarlo
        if ($accion === 'crear') {
            $verificarCedula = $conexion->prepare(
                'SELECT id_usuario
                 FROM usuarios
                 WHERE cedula = :cedula
                 LIMIT 1'
            );
            $verificarCedula->execute([
                'cedula' => $cedula
            ]);
        } else {
            $verificarCedula = $conexion->prepare(
                'SELECT id_usuario
                 FROM usuarios
                 WHERE cedula = :cedula
                    AND id_usuario <> :id_usuario
                 LIMIT 1'
            );
            $verificarCedula->execute([
                'cedula' => $cedula,
                'id_usuario' => $idUsuario
            ]);
        }

        if ($verificarCedula->fetchColumn() !== false) {
            throw new DomainException(
                'Ya existe un usuario registrado con esta cédula.'
            );
        }

        if ($accion === 'actualizar') {
            $antesAuditoria = consultarRegistroAuditoria($conexion, 'usuarios', $idUsuario);

    $buscarUsuario = $conexion->prepare(
                'SELECT rol
                 FROM usuarios
                 WHERE id_usuario = :id_usuario
                 LIMIT 1
                 FOR UPDATE'
            );
            $buscarUsuario->execute([
                'id_usuario' => $idUsuario,
            ]);

            $rolAnterior = $buscarUsuario->fetchColumn();

            if ($rolAnterior === false) {
                throw new DomainException(
                    'El usuario que intentas editar no existe'
                );
            }

            if ($estado === 'INACTIVO') {
                exigirUsuarioSinAsignaciones($conexion, $idUsuario);
            }

            $buscarCuenta = $conexion->prepare(
                'SELECT id_cuenta
                 FROM cuentas_acceso
                 WHERE id_usuario = :id_usuario
                 LIMIT 1
                 FOR UPDATE'
            );
            $buscarCuenta->execute([
                'id_usuario' => $idUsuario,
            ]);

            $cuentaExistente = $buscarCuenta->fetchColumn();
        }

        $requiereContrasena =
            $rol === 'ADMINISTRADOR'
            && (
                $accion === 'crear'
                || $rolAnterior !== 'ADMINISTRADOR'
                || $cuentaExistente === false
            );

        if ($requiereContrasena && strlen($contrasena) < 8) {
            throw new DomainException(
                'Debes asignar una contraseña de al menos 8 caracteres.'
            );
        }

        if ($accion === 'crear') {
            $guardarUsuario = $conexion->prepare(
                'INSERT INTO usuarios
                    (
                        cedula,
                        nombre,
                        apellido,
                        correo,
                        id_area,
                        rol,
                        estado
                    )
                 VALUES
                    (
                        :cedula,
                        :nombre,
                        :apellido,
                        :correo,
                        :id_area,
                        :rol,
                        "ACTIVO"
                    )'
            );

            $guardarUsuario->execute([
                'cedula' => $cedula,
                'nombre' => $nombre,
                'apellido' => $apellido,
                'correo' => $correo,
                'id_area' => $idArea,
                'rol' => $rol,
            ]);

            $idUsuario = (int) $conexion->lastInsertId();
        } else {
            $guardarUsuario = $conexion->prepare(
                'UPDATE usuarios
                 SET cedula = :cedula,
                     nombre = :nombre,
                     apellido = :apellido,
                     correo = :correo,
                     id_area = :id_area,
                     rol = :rol,
                     estado = :estado
                 WHERE id_usuario = :id_usuario'
            );

            $guardarUsuario->execute([
                'id_usuario' => $idUsuario,
                'cedula' => $cedula,
                'nombre' => $nombre,
                'apellido' => $apellido,
                'correo' => $correo,
                'id_area' => $idArea,
                'rol' => $rol,
                'estado' => $estado,
            ]);
        }

        if ($rol === 'ADMINISTRADOR') {
            if ($cuentaExistente === false || $accion === 'crear') {
                $crearCuenta = $conexion->prepare(
                    'INSERT INTO cuentas_acceso
                        (id_usuario, contrasena, estado)
                     VALUES
                        (:id_usuario, :contrasena, "ACTIVO")'
                );

                $crearCuenta->execute([
                    'id_usuario' => $idUsuario,
                    'contrasena' => password_hash(
                        $contrasena,
                        PASSWORD_DEFAULT
                    ),
                ]);
            } elseif ($contrasena !== '') {
                $actualizarContrasena = $conexion->prepare(
                    'UPDATE cuentas_acceso
                     SET contrasena = :contrasena
                     WHERE id_usuario = :id_usuario'
                );

                $actualizarContrasena->execute([
                    'id_usuario' => $idUsuario,
                    'contrasena' => password_hash(
                        $contrasena,
                        PASSWORD_DEFAULT
                    ),
                ]);
            }
        } elseif ($cuentaExistente !== false && $cuentaExistente !== null) {
            $eliminarCuenta = $conexion->prepare(
                'DELETE FROM cuentas_acceso
                 WHERE id_usuario = :id_usuario'
            );

            $eliminarCuenta->execute([
                'id_usuario' => $idUsuario,
            ]);
        }

        $extraAuditoria = !empty($contrasena) && $rol === 'ADMINISTRADOR' ? ['credenciales_actualizadas' => 'Sí'] : [];
        if ($imagenNueva !== null) {
            guardarImagenUsuario($conexion, (int) $idUsuario, $imagenNueva);
            $extraAuditoria['imagen_actualizada'] = 'Sí';
        }
        registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'usuarios', (int) $idUsuario, $antesAuditoria, $extraAuditoria);
        $conexion->commit();

        $_SESSION['mensajeExito'] = $accion === 'crear'
            ? 'Usuario creado correctamente.'
            : 'Usuario actualizado correctamente.';
    } catch (DomainException $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        $_SESSION['mensajeError'] = $error->getMessage();
    } catch (PDOException $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        if ($error->getCode() === '23000') {
            $_SESSION['mensajeError'] =
                'La cédula o el correo ya están registrados.';
        } else {
            error_log(
                'Error al guardar el usuario: '
                . $error->getMessage()
            );

            $_SESSION['mensajeError'] =
                'No fue posible guardar los datos del usuario.';
        }
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        error_log(
            'Error inesperado al guardar el usuario: '
            . $error->getMessage()
        );

        $_SESSION['mensajeError'] =
            'No fue posible guardar los datos del usuario.';
    }

    volverAUsuarios();
}

/*
 * CAMBIO DE ESTADO
 */

$idUsuario = filter_input(
    INPUT_POST,
    'id_usuario',
    FILTER_VALIDATE_INT
);

$estado = strtoupper(
    trim(textoEntrada($_POST, 'estado'))
);

if (
    !$idUsuario
    || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)
) {
    $_SESSION['mensajeError'] =
        'No fue posible cambiar el estado.';
    volverAUsuarios();
}

if (
    $idUsuario === (int) ($_SESSION['administrador_id'] ?? 0)
    && $estado === 'INACTIVO'
) {
    $_SESSION['mensajeError'] =
        'No puedes desactivar tu propio usuario.';
    volverAUsuarios();
}

try {
    $conexion->beginTransaction();

    protegerAdministradores(
        $conexion,
        $idUsuario,
        null,
        $estado
    );

    $antesAuditoria = consultarRegistroAuditoria($conexion, 'usuarios', $idUsuario);

    $buscarUsuario = $conexion->prepare(
        'SELECT estado
         FROM usuarios
         WHERE id_usuario = :id_usuario
         FOR UPDATE'
    );

    $buscarUsuario->execute([
        'id_usuario' => $idUsuario
    ]);

    $estadoActual = $buscarUsuario->fetchColumn();

    if ($estadoActual === false) {
        throw new DomainException('El usuario no existe.');
    }

    if ($estadoActual === $estado) {
        throw new DomainException(
            'El usuario ya tenía el estado seleccionado.'
        );
    }

    if ($estado === 'INACTIVO') {
        exigirUsuarioSinAsignaciones($conexion, $idUsuario);
    }

    $actualizarEstado = $conexion->prepare(
        'UPDATE usuarios
         SET estado = :estado
         WHERE id_usuario = :id_usuario'
    );

    $actualizarEstado->execute([
        'estado' => $estado,
        'id_usuario' => $idUsuario
    ]);

    registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'usuarios', (int) $idUsuario, $antesAuditoria, (!empty($contrasena) && ($rol ?? '') === 'ADMINISTRADOR' && $accion !== 'cambiar_estado' ? ['credenciales_actualizadas' => 'Sí'] : []));
    $conexion->commit();

    $_SESSION['mensajeExito'] = 'Estado actualizado correctamente.';

} catch (DomainException $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    error_log(
        'Error al cambiar el estado del usuario: '
        . $error->getMessage()
    );

    $_SESSION['mensajeError'] = $error->getMessage();
} catch (Throwable $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    error_log(
        'Error al cambiar el estado del usuario: '
        . $error->getMessage()
    );

    $_SESSION['mensajeError'] =
        'No fue posible cambiar el estado. Actualiza la página';
}

volverAUsuarios();

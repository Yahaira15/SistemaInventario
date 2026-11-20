<?php

declare(strict_types=1);
require_once __DIR__ . '/../auditoria/cambios.php';

// Inicia la sesión y restringe este procesador a administradores autenticados.
require_once __DIR__ . '/../../auth/sesion.php';
require_once __DIR__ . '/../../auth/proteger.php';

iniciarSesionSegura();
exigirAdministrador();
require_once __DIR__ . '/../../auth/formularios.php';
require_once __DIR__ . '/unidades.php';
unset($_SESSION['mensajeError'], $_SESSION['mensajeExito']);

const RUTA_ATRIBUTOS = '/sistemaInventario/administracion/atributos';

/*
  Regresa al listado mediante el patrón POST/Redirect/GET.
  Esto evita que el navegador reenvíe el formulario al recargar la página.
 */
function volverAAtributos(): never
{
    header('Location: ' . urlRetornoFormulario('atributos', RUTA_ATRIBUTOS, []));
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
    empty($_SESSION['csrf_atributos'])
    || !hash_equals($_SESSION['csrf_atributos'], $csrfToken)
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
    $idAtributo = $accion === 'actualizar'
        ? filter_input(INPUT_POST, 'id_atributo', FILTER_VALIDATE_INT)
        : null;

    // Normaliza los valores antes de validarlos y enviarlos a la base de datos.
    $nombre = trim(textoEntrada($_POST, 'nombre'));
    $descripcion = trim(textoEntrada($_POST, 'descripcion'));
    $tipoDato = strtoupper(trim(textoEntrada($_POST, 'tipoDato')));
    $unidad = ''; // La columna antigua ya no define las unidades permitidas.
    try {
        $unidades = $tipoDato === 'NUMERO' ? normalizarUnidades($_POST['unidades'] ?? []) : [];
    } catch (DomainException $error) {
        $_SESSION['mensajeError'] = $error->getMessage();
        volverAAtributos();
    }
    $longitudEntrada = $_POST['longitud_maxima'] ?? '';

    if (!is_scalar($longitudEntrada)) {
        $_SESSION['mensajeError'] = 'La longitud máxima no es válida.';
        volverAAtributos();
    }

    $longitudEntrada = trim((string) $longitudEntrada);

    $longitudMaxima = $longitudEntrada === ''
        ? null
        : filter_var(
            $longitudEntrada,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 255]]
        );

    if ($longitudMaxima === false) {
        $_SESSION['mensajeError'] = 'La longitud máxima debe ser un número entero entre 1 y 255.';
        volverAAtributos();
    }

    // Configuración interna: admite enteros y hasta cuatro cifras decimales.
    // NULL elimina el máximo de negocio; se mantiene la capacidad del almacenamiento.
    $valorMinimo = $tipoDato === 'NUMERO' ? 0 : null;
    $valorMaximo = null;
    $decimales = $tipoDato === 'NUMERO' ? 4 : null;
    $estado = $accion === 'actualizar' ? strtoupper(trim(textoEntrada($_POST, 'estado'))) : 'ACTIVO';

    $opcionesEntrada = $_POST['opciones'] ?? [];
    $opcionesSeleccion = [];
    $opcionesInvalidas = !is_array($opcionesEntrada);

    if (is_array($opcionesEntrada)) {
        $opcionesNormalizadas = [];

        foreach ($opcionesEntrada as $opcionEntrada) {
            if (!is_scalar($opcionEntrada)) {
                $opcionesInvalidas = true;
                break;
            }

            $opcion = trim((string) $opcionEntrada);

            if ($opcion === '') {
                continue;
            }

            if (mb_strlen($opcion) > 100) {
                $opcionesInvalidas = true;
                break;
            }

            $claveOpcion = mb_strtolower($opcion, 'UTF-8');

            if (isset($opcionesNormalizadas[$claveOpcion])) {
                $opcionesInvalidas = true;
                break;
            }

            $opcionesNormalizadas[$claveOpcion] = true;
            $opcionesSeleccion[] = $opcion;
        }
    }
        
    $tiposPermitidos = [
        'TEXTO',
        'NUMERO',
        'FECHA',
        'BOOLEANO',
        'SELECCION',
    ];

    if ($tipoDato === 'SELECCION' && $opcionesInvalidas) {
        $_SESSION['mensajeError'] = 'Las opciones deben ser textos válidos, sin repetirse y con un máximo de 100 caracteres.';
        volverAAtributos();
    }

    if ($accion === 'crear' && $tipoDato === 'SELECCION' && $opcionesSeleccion === []) {
        $_SESSION['mensajeError'] = 'Agrega al menos una opción para el atributo de selección.';
        volverAAtributos();
    }
    
    // Valida campos obligatorios, longitudes, identificador y estado permitido.
    
    if (
        ($accion === 'actualizar' && !$idAtributo)
        || mb_strlen($nombre) < 2
        || mb_strlen($nombre) > 80
        || mb_strlen($descripcion) > 255
        || !in_array($tipoDato, $tiposPermitidos, true)
        || mb_strlen($unidad) > 20
        || ($longitudEntrada !== '' && $longitudMaxima === false)
        || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)
    ) {
        $_SESSION['mensajeError'] =
            'Revisa los datos ingresados.';

        volverAAtributos();
    }
    
    try {
        if ($tipoDato !== 'NUMERO') {
            $unidad = '';
            $decimales = null;
            $valorMinimo = null;
            $valorMaximo = null;
        }

        if ($tipoDato !== 'TEXTO') {
            $longitudMaxima = null;
        }

        if ($accion === 'crear') {
            // Los atributos siempre comienzan con estado ACTIVO.
            $conexion->beginTransaction();
            $antesAuditoria = consultarRegistroAuditoria($conexion, 'atributos', $idAtributo ?: null);

            $guardar = $conexion->prepare(
                'INSERT INTO atributos
                     (nombre, descripcion, tipo_dato, unidad,
                      longitud_maxima, valor_minimo, valor_maximo, decimales, estado)
                 VALUES 
                     (:nombre, :descripcion, :tipo_dato, :unidad,
                      :longitud_maxima, :valor_minimo, :valor_maximo, :decimales, "ACTIVO")'
            );
            $guardar->execute([
                'nombre' => $nombre,
                'descripcion' => $descripcion !== ''
                 ? $descripcion
                 : null,
                'tipo_dato' => $tipoDato,
                'unidad' => $unidad !== ''
                 ? $unidad
                 : null,
                'longitud_maxima' => $longitudMaxima,
                'valor_minimo' => $valorMinimo,
                'valor_maximo' => $valorMaximo,
                'decimales' => $decimales
            ]);

            $idAtributo = (int) $conexion->lastInsertId();
            if ($tipoDato === 'NUMERO') {
                guardarUnidadesAtributo($conexion, $idAtributo, $unidades);
            }
            if ($tipoDato === 'SELECCION') {
                $idAtributoCreado = $idAtributo;
                $guardarOpcion = $conexion->prepare(
                    'INSERT INTO atributo_opciones
                        (id_atributo, valor, orden_visual, estado)
                     VALUES
                        (:id_atributo, :valor, :orden_visual, "ACTIVO")'
                );

                foreach ($opcionesSeleccion as $indice => $opcion) {
                    $guardarOpcion->execute([
                        'id_atributo' => $idAtributoCreado,
                        'valor' => $opcion,
                        'orden_visual' => $indice + 1,
                    ]);
                }
            }

            registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'atributos', (int) $idAtributo, $antesAuditoria);
            $conexion->commit();

            $_SESSION['mensajeExito'] = 'Atributo creado correctamente.';
        } else {
            // Confirma que el registro siga existiendo antes de actualizar
            $conexion->beginTransaction();
            $antesAuditoria = consultarRegistroAuditoria($conexion, 'atributos', $idAtributo ?: null);
            $verificarAtributo = $conexion->prepare(
                'SELECT id_atributo, tipo_dato
                 FROM atributos
                 WHERE id_atributo = :id_atributo
                 LIMIT 1
                 FOR UPDATE'
            );
            $verificarAtributo->execute(['id_atributo' => $idAtributo]);

            $atributoActual = $verificarAtributo->fetch();

            if (!$atributoActual) {
                $conexion->rollBack();
                $_SESSION['mensajeError'] = 'El atributo no existe.';
                volverAAtributos();
            }

            if (
                $atributoActual['tipo_dato'] === 'TEXTO'
                && $tipoDato === 'TEXTO'
            ) {
                $limiteNuevo = $longitudMaxima ?? 255;

                $consultarLongitud = $conexion->prepare(
                    'SELECT COALESCE(MAX(CHAR_LENGTH(pav.valor_texto)), 0)
                    FROM producto_atributo_valores AS pav
                    INNER JOIN categoria_atributos AS ca
                        ON ca.id_categoria_atributo = pav.id_categoria_atributo
                    WHERE ca.id_atributo = :id_atributo'
                );

                $consultarLongitud->execute([
                    'id_atributo' => $idAtributo
                ]);

                $longitudUtilizada = (int) $consultarLongitud->fetchColumn();

                if ($longitudUtilizada > $limiteNuevo) {
                    throw new DomainException(
                        'No puedes establecer un máximo de '
                        . $limiteNuevo
                        . ' caracteres: existen valores guardados de hasta '
                        . $longitudUtilizada
                        . ' caracteres.'
                    );
                }
            }

            if ($atributoActual['tipo_dato'] !== $tipoDato) {
                $consultaUnidades = $conexion->prepare('SELECT COUNT(*) FROM atributo_unidades WHERE id_atributo = ?');
                $consultaUnidades->execute([$idAtributo]);
                if ((int) $consultaUnidades->fetchColumn() > 0) {
                    throw new DomainException('No puedes cambiar el tipo de un atributo con unidades registradas.');
                }
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
                    'id_atributo_opciones' => $idAtributo,
                    'id_atributo_asignaciones' => $idAtributo,
                    'id_atributo_valores' => $idAtributo
                ]);

                $dependencias = $consultaDependencias->fetch();
                $motivos = [];

                if ((int) $dependencias['tiene_opciones'] === 1) {
                    $motivos[] = 'opciones registradas';
                }

                if ((int) $dependencias['tiene_asignaciones'] === 1) {
                    $motivos[] = 'asignaciones a categorías';
                }

                if ((int) $dependencias['tiene_valores'] === 1) {
                    $motivos[] = 'valores de productos';
                }

                if ($motivos !== []) {
                    $conexion->rollBack();

                    $_SESSION['mensajeError'] = 'No puedes cambiar el tipo de dato del atributo porque tiene '
                            . implode(', ', $motivos)
                            . '.';

                    volverAAtributos();
                }
            }

            if ($atributoActual['tipo_dato'] !== 'SELECCION' && $tipoDato === 'SELECCION') {
                $guardarOpcion = $conexion->prepare(
                    'INSERT INTO atributo_opciones
                        (id_atributo, valor, orden_visual, estado)
                     VALUES (:id_atributo, :valor, :orden_visual, "ACTIVO")'
                );
                foreach ($opcionesSeleccion as $indice => $opcion) {
                    $guardarOpcion->execute([
                        'id_atributo' => $idAtributo,
                        'valor' => $opcion,
                        'orden_visual' => $indice + 1,
                    ]);
                }
            }

            // Actualiza solamente el registro identificado por su clave primaria.
            $guardar = $conexion->prepare(
                'UPDATE atributos
                 SET nombre = :nombre,
                     descripcion = :descripcion,
                     tipo_dato = :tipo_dato,
                     longitud_maxima = :longitud_maxima,
                     valor_minimo = :valor_minimo,
                     valor_maximo = :valor_maximo,
                     decimales = :decimales,
                     estado = :estado
                 WHERE id_atributo = :id_atributo'
            );
            $guardar->execute([
                'id_atributo' => $idAtributo,
                'nombre' => $nombre,
                'descripcion' => $descripcion !== '' ? $descripcion : null,
                'tipo_dato' => $tipoDato,
                'longitud_maxima' => $longitudMaxima,
                'valor_minimo' => $valorMinimo,
                'valor_maximo' => $valorMaximo,
                'decimales' => $decimales,
                'estado' => $estado
            ]);
            if ($tipoDato === 'NUMERO') {
                guardarUnidadesAtributo($conexion, (int) $idAtributo, $unidades);
            }
            registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'atributos', (int) $idAtributo, $antesAuditoria);
            $conexion->commit();

            $_SESSION['mensajeExito'] = ($tipoDato === 'NUMERO' || $guardar->rowCount() === 1)
                ? 'Atributo actualizado correctamente.'
                : 'No se realizaron cambios en el atributo.';
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

        // 23000 icluye violacioes de las restricciones UNIQUE del nombre
        if ($error->getCode() === '23000') {
            $_SESSION['mensajeError'] = 'Revisa los datos duplicados y sus relaciones.';
        } else {
            error_log('Error al guardar el atributo: ' . $error->getMessage());
            $_SESSION['mensajeError'] = 'No fue posible guardar los datos del atributo.';
        }
    }

    volverAAtributos();
}

/*
 * CAMBIO DE ESTADO
 * Si el flujo llega hasta aquí, la acción validada es cambiar_estado.
 */
$idAtributo = filter_input(INPUT_POST, 'id_atributo', FILTER_VALIDATE_INT);
$estado = textoEntrada($_POST, 'estado');

if (!$idAtributo || !in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado';
    volverAAtributos();
}

try {
    $conexion->beginTransaction();
    $antesAuditoria = consultarRegistroAuditoria($conexion, 'atributos', $idAtributo ?: null);
    $bloquearAtributo = $conexion->prepare(
        'SELECT tipo_dato FROM atributos
         WHERE id_atributo = :id_atributo FOR UPDATE'
    );
    $bloquearAtributo->execute(['id_atributo' => $idAtributo]);
    $tipoDatoActual = $bloquearAtributo->fetchColumn();

    if ($tipoDatoActual === false) {
        $conexion->rollBack();
        $_SESSION['mensajeError'] = 'El atributo no existe.';
        volverAAtributos();
    }

    if ($estado === 'ACTIVO' && $tipoDatoActual === 'SELECCION') {
        $consultaOpcionesActivas = $conexion->prepare(
            'SELECT COUNT(*) FROM atributo_opciones
             WHERE id_atributo = :id_atributo AND estado = "ACTIVO"'
        );
        $consultaOpcionesActivas->execute(['id_atributo' => $idAtributo]);
        if ((int) $consultaOpcionesActivas->fetchColumn() === 0) {
            $conexion->rollBack();
            $_SESSION['mensajeError'] = 'No puedes activar un atributo de selección sin opciones activas.';
            volverAAtributos();
        }
    }

    $actualizar = $conexion->prepare(
        'UPDATE atributos
         SET estado = :estado
         WHERE id_atributo = :id_atributo'
    );
    $actualizar->execute([
        'estado' => $estado,
        'id_atributo' => $idAtributo
    ]);
    registrarCambioAuditoria($conexion, (int) $_SESSION['administrador_id'], 'atributos', (int) $idAtributo, $antesAuditoria);
    $conexion->commit();

    if ($actualizar->rowCount() === 1) {
        $_SESSION['mensajeExito'] = 'Estado actualizado correctamente.';
    } else {
        $_SESSION['mensajeError'] = 'El atributo no existe o ya tenía ese estado.';
    }
} catch (Throwable $error) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('Error al cambiar el estado del atributo: ' . $error->getMessage());
    $_SESSION['mensajeError'] = 'No fue posible cambiar el estado.';
}

volverAAtributos();

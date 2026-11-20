<?php

declare(strict_types=1);

require_once __DIR__ . '/enviar_correo.php';
require_once __DIR__ . '/../sesion.php';
require_once __DIR__ . '/../limites.php';
require_once __DIR__ . '/../entrada.php';

iniciarSesionSegura();

if (!isset($_SESSION['csrf_recuperacion'])) {
    $_SESSION['csrf_recuperacion'] = bin2hex(random_bytes(32));
}

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = strtolower(trim(textoEntrada($_POST, 'correo')));
    $csrfToken = textoEntrada($_POST, 'csrf_token');

    if (!hash_equals($_SESSION['csrf_recuperacion'], $csrfToken)) {
        $mensaje = 'La solicitud no es válida. Recarga la página.';
    } elseif (
        $correo === ''
        || strlen($correo) > 255
        || !filter_var($correo, FILTER_VALIDATE_EMAIL)
    ) {
        $mensaje = 'Ingrese un correo válido.';
    } else {
        $conexion = require __DIR__ . '/../../config/conexion.php';
        eliminarIntentosAntiguos($conexion);
        $solicitudPermitida = registrarSolicitudRecuperacion($conexion, $correo);

        $buscarCuenta = $conexion->prepare(
            'SELECT c.id_cuenta
             FROM cuentas_acceso AS c
             INNER JOIN usuarios AS u ON u.id_usuario = c.id_usuario
             WHERE u.correo = :correo
               AND u.estado = "ACTIVO"
               AND u.rol = "ADMINISTRADOR"
               AND c.estado = "ACTIVO"
             LIMIT 1'
        );
        $idCuenta = false;

        if ($solicitudPermitida) {
            $buscarCuenta->execute(['correo' => $correo]);
            $idCuenta = $buscarCuenta->fetchColumn();
        }

        if ($idCuenta) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            try {
                $conexion->beginTransaction();

                $cancelarAnteriores = $conexion->prepare(
                    'UPDATE recuperacion_contrasena
                     SET estado = "EXPIRADO"
                     WHERE id_cuenta = :id_cuenta AND estado = "PENDIENTE"'
                );
                $cancelarAnteriores->execute(['id_cuenta' => $idCuenta]);

                $crearSolicitud = $conexion->prepare(
                    'INSERT INTO recuperacion_contrasena
                        (id_cuenta, token_hash, fecha_vencimiento, estado)
                     VALUES
                        (:id_cuenta, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE), "PENDIENTE")'
                );
                $crearSolicitud->execute([
                    'id_cuenta' => $idCuenta,
                    'token_hash' => $tokenHash,
                ]);

                $conexion->commit();
            } catch (Throwable $error) {
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }

                error_log('No se pudo crear la recuperación: ' . $error->getMessage());
                $mensaje = 'No fue posible procesar la solicitud.';
            }

            if ($mensaje === '') {
                $configCorreo = require __DIR__ . '/../../config/correo.php';
                $enlaceRecuperacion =
                    rtrim($configCorreo['base_url'], '/')
                    . '/restablecer?token='
                    . urlencode($token);

                try {
                    enviarCorreoRecuperacion($correo, $enlaceRecuperacion);
                } catch (Throwable $error) {
                    error_log('No se pudo enviar el correo de recuperación: ' . $error->getMessage());

                    // Un enlace que no llegó a su destinatario no debe quedar activo.
                    try {
                        $expirarSolicitud = $conexion->prepare(
                            'UPDATE recuperacion_contrasena
                             SET estado = "EXPIRADO"
                             WHERE token_hash = :token_hash AND estado = "PENDIENTE"'
                        );
                        $expirarSolicitud->execute(['token_hash' => $tokenHash]);
                    } catch (Throwable $errorBaseDatos) {
                        error_log(
                            'No se pudo expirar el token no enviado: '
                            . $errorBaseDatos->getMessage()
                        );
                    }
                }
            }
        }

        // No revela si el correo proporcionado pertenece a una cuenta.
        if ($mensaje === '') {
            $mensaje = 'Si el correo corresponde a una cuenta activa, recibirás un enlace de recuperación.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recuperar contraseña | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/auth/recuperacion/styles.css">
</head>
<body class="login-body">
    <main class="login-shell">
        <?php $pasoRecuperacion = 1; require __DIR__ . '/panel.php'; ?>
        <section class="login-panel" aria-labelledby="titulo-formulario">
        <div class="login-card">
        <a class="volver" href="/sistemaInventario/"><span aria-hidden="true">←</span> Volver al inicio de sesión</a>
        <span class="login-etiqueta">RECUPERACIÓN DE ACCESO</span>
        <h1 id="titulo-formulario">¿Olvidaste tu contraseña?</h1>
        <p class="login-subtitle">Ingresa el correo asociado a tu cuenta y te enviaremos un enlace para restablecerla.</p>

        <?php if ($mensaje !== ''): ?>
            <div class="mensaje" role="status"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_recuperacion'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="correo">Correo electrónico</label>
            <input type="email" id="correo" name="correo" maxlength="255" autocomplete="email" placeholder="nombre@empresa.com" value="<?= htmlspecialchars($correo ?? '', ENT_QUOTES, 'UTF-8') ?>" required autofocus>
            <button type="submit">Enviar enlace <span aria-hidden="true">→</span></button>
        </form>
        <p class="recuperacion-ayuda">Si no encuentras el correo, revisa la carpeta de spam.</p>
        <footer class="login-pie">ADP · Sistema de Inventario</footer>
        </div>
        </section>
    </main>
</body>
</html>

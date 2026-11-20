<?php

declare(strict_types=1);

require_once __DIR__ . '/../sesion.php';
require_once __DIR__ . '/../entrada.php';
require_once __DIR__ . '/servicio.php';

// Conserva el token CSRF y protege la cookie de sesión.
iniciarSesionSegura();

// El token llega por GET al abrir el enlace y por POST al enviar la nueva clave.
$entradaToken = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$token = trim(textoEntrada($entradaToken, 'token'));
$mensaje = '';
$completado = false;
$solicitud = null;

// Crea la protección CSRF usada por el formulario.
if (!isset($_SESSION['csrf_restablecer'])) {
    $_SESSION['csrf_restablecer'] = bin2hex(random_bytes(32));
}

// Solo consulta tokens con el formato hexadecimal esperado.
if (strlen($token) === 64 && ctype_xdigit($token)) {

    // Conectamos con la base de datos y consultamos la ultima solicitud
    $conexion = require __DIR__ . '/../../config/conexion.php';

    // Busca una solicitud pendiente, no utilizada y dentro de su vigencia.
    $buscarSolicitud = $conexion->prepare(
        'SELECT r.id_recuperacion, r.id_cuenta
         FROM recuperacion_contrasena AS r
         WHERE r.token_hash = :token_hash
           AND r.estado = "PENDIENTE"
           AND r.fecha_vencimiento >= NOW()
           AND r.fecha_utilizacion IS NULL
         LIMIT 1'
    );
    $buscarSolicitud->execute(['token_hash' => hash('sha256', $token)]);
    $solicitud = $buscarSolicitud->fetch();
}

if (!$solicitud) {
    $mensaje = 'El enlace no es válido o ya venció.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lee y valida las dos copias de la nueva contraseña.
    $contrasena = textoEntrada($_POST, 'contrasena');
    $confirmacion = textoEntrada($_POST, 'confirmacion');
    $csrfToken = textoEntrada($_POST, 'csrf_token');

    if (!hash_equals($_SESSION['csrf_restablecer'], $csrfToken)) {
        $mensaje = 'La solicitud no es válida. Recarga la página.';
    } elseif (strlen($contrasena) < 8) {
        $mensaje = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($contrasena !== $confirmacion) {
        $mensaje = 'Las contraseñas no coinciden.';
    } else {
        try {
            restablecerContrasena($conexion, (int) $solicitud['id_recuperacion'], (int) $solicitud['id_cuenta'], $contrasena);
            $completado = true;
            $mensaje = 'Tu contraseña fue actualizada correctamente.';
        } catch (DomainException $error) {
            $solicitud = null;
            $mensaje = $error->getMessage();
        } catch (Throwable $error) {
            error_log('No se pudo restablecer la contraseña: ' . $error->getMessage());
            $mensaje = 'No fue posible actualizar la contraseña.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva contraseña | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/styles.css">
    <link rel="stylesheet" href="/sistemaInventario/auth/recuperacion/styles.css">
</head>
<body class="login-body">
    <main class="login-shell">
        <?php $pasoRecuperacion = 3; require __DIR__ . '/panel.php'; ?>
        <section class="login-panel" aria-labelledby="titulo-formulario">
        <div class="login-card">
        <a class="volver" href="/sistemaInventario/"><span aria-hidden="true">←</span> Volver al inicio de sesión</a>
        <span class="login-etiqueta">RECUPERACIÓN DE ACCESO</span>
        <h1 id="titulo-formulario"><?= $completado ? 'Contraseña actualizada' : ($solicitud ? 'Crea tu nueva contraseña' : 'Solicita un nuevo enlace') ?></h1>
        <p class="login-subtitle"><?= $completado ? 'Ya puedes volver a ingresar al sistema de inventario.' : ($solicitud ? 'Elige una contraseña de al menos 8 caracteres y confírmala para continuar.' : 'El enlace de recuperación ya no está disponible. Solicita otro para recuperar el acceso a tu cuenta.') ?></p>

        <?php if ($mensaje !== ''): ?>
            <div class="mensaje <?= $completado ? 'exito' : '' ?>" role="status">
                <?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($solicitud && !$completado): ?>
            <!-- El token se conserva oculto mientras se envía la nueva contraseña. -->
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_restablecer'], ENT_QUOTES, 'UTF-8') ?>">

                <label for="contrasena">Nueva contraseña</label>
                <input type="password" id="contrasena" name="contrasena" minlength="8" maxlength="255" autocomplete="new-password" placeholder="Mínimo 8 caracteres" aria-describedby="ayuda-contrasena" required autofocus>

                <label for="confirmacion">Confirmar contraseña</label>
                <input type="password" id="confirmacion" name="confirmacion" minlength="8" maxlength="255" autocomplete="new-password" placeholder="Escribe de nuevo tu contraseña" required>

                <button type="submit">Actualizar contraseña</button>
            </form>
            <p class="recuperacion-ayuda" id="ayuda-contrasena">Usa una contraseña que no utilices en otras cuentas.</p>
        <?php elseif ($completado): ?>
            <a class="boton-enlace" href="/sistemaInventario/">Iniciar sesión</a>
        <?php else: ?>
            <a class="boton-enlace" href="/sistemaInventario/recuperar">Solicitar otro enlace</a>
        <?php endif; ?>
        <footer class="login-pie">ADP · Sistema de Inventario</footer>
        </div>
        </section>
    </main>
</body>
</html>

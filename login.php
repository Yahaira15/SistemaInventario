<?php

declare(strict_types=1);
require_once __DIR__ . '/auth/entrada.php';

// Inicia la sesión para saber si el administrador ya está autenticado.
require_once __DIR__ . '/auth/sesion.php';
iniciarSesionSegura();

// Evita mostrar nuevamente el login cuando ya existe una sesión válida.
if (!empty($_SESSION['administrador_id'])) {
    header('Location: /sistemaInventario/administracion');
    exit;
}

if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}

// El procesador vuelve con ?error=1 cuando las credenciales no son válidas.
$tipoError = textoEntrada($_GET, 'error');
$error = $tipoError !== '';
$errorLimite = $tipoError === 'limite';
$cedulaAnterior = $error ? (string) ($_SESSION['cedula_login'] ?? '') : '';
unset($_SESSION['cedula_login']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión | Sistema de Inventario</title>
    <link rel="stylesheet" href="/sistemaInventario/styles.css">
    <script src="/sistemaInventario/js/alertas.js" defer></script>
</head>
<body class="login-body">
    <main class="login-shell">
        <section class="login-brand" aria-labelledby="titulo-sistema">
            <span class="login-etiqueta">GESTIÓN DE EQUIPOS</span>
            <h2 id="titulo-sistema">Tu inventario,<br>en un solo lugar.</h2>
            <p>Administra los equipos, sus asignaciones y los movimientos de tu organización.</p>
            <div class="login-ilustracion" aria-hidden="true">
                <svg viewBox="0 0 320 210" fill="none">
                    <rect x="44" y="24" width="226" height="142" rx="16" fill="white" stroke="currentColor" stroke-width="2"/>
                    <path d="M44 136h226" stroke="currentColor" stroke-width="2"/>
                    <path d="M131 166v21m53-21v21m-74 0h116" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    <rect x="64" y="46" width="46" height="70" rx="7" fill="#fff0eb"/>
                    <path d="M76 61h22m-22 13h15m-15 13h22" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    <rect x="126" y="48" width="57" height="28" rx="6" fill="#ffe3da"/>
                    <rect x="193" y="48" width="57" height="28" rx="6" fill="#fff0eb"/>
                    <path d="M128 94h121m-121 16h84" stroke="#e4e7ec" stroke-width="7" stroke-linecap="round"/>
                    <rect x="238" y="105" width="48" height="76" rx="9" fill="white" stroke="currentColor" stroke-width="2"/>
                    <path d="M254 168h15" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    <circle cx="49" cy="165" r="22" fill="#c90000"/>
                    <path d="m39 165 7 7 13-14" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="login-modulos"><span>Equipos</span><span>Asignaciones</span><span>Trazabilidad</span></div>
        </section>

        <section class="login-panel" aria-labelledby="titulo-login">
            <div class="login-card">
                <span class="login-etiqueta">ACCESO ADMINISTRATIVO</span>
                <h1 id="titulo-login">Bienvenido de nuevo</h1>
                <p class="login-subtitle">Ingresa tus credenciales para continuar.</p>

                <?php if ($errorLimite): ?>
                    <div class="alert" role="alert">Demasiados intentos fallidos. Intenta nuevamente en 15 minutos.</div>
                <?php elseif ($error): ?>
                    <div class="alert" role="alert">La cédula o la contraseña son incorrectas.</div>
                <?php endif; ?>

                <!-- Los datos se envían por POST para no exponer la contraseña en la URL. -->
                <form action="/sistemaInventario/autenticar" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_login'], ENT_QUOTES, 'UTF-8') ?>">
                    <label for="cedula">Número de cédula</label>
                    <input type="text" id="cedula" name="cedula" maxlength="20"
                        value="<?= htmlspecialchars($cedulaAnterior, ENT_QUOTES, 'UTF-8') ?>"
                        inputmode="numeric" autocomplete="username" placeholder="Ingresa tu cédula" required autofocus>

                    <label for="contrasena">Contraseña</label>
                    <input type="password" id="contrasena" name="contrasena"
                        maxlength="255" autocomplete="current-password" placeholder="Ingresa tu contraseña" required>

                    <p class="consulta-link">
                        ¿Olvidaste tu contraseña?
                        <a href="/sistemaInventario/recuperar">Recuperar</a>
                    </p>

                    <button type="submit">Iniciar sesión <span aria-hidden="true">→</span></button>
                </form>
                <footer class="login-pie">ADP · Sistema de Inventario</footer>
            </div>
        </section>
    </main>
</body>
</html>

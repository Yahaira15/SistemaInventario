<?php

declare(strict_types=1);
require_once __DIR__ . '/auth/entrada.php';

// Abre la sesión donde se guardarán los datos del administrador autenticado.
require_once __DIR__ . '/auth/sesion.php';
require_once __DIR__ . '/auth/limites.php';
iniciarSesionSegura();

// Este archivo solo procesa formularios enviados por POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /sistemaInventario/');
    exit;
}

// Recupera y normaliza los valores enviados desde login.php.
$cedula = trim(textoEntrada($_POST, 'cedula'));
$contrasenaIngresada = textoEntrada($_POST, 'contrasena');
$csrfToken = textoEntrada($_POST, 'csrf_token');
if (!empty($_SESSION['csrf_login']) && hash_equals($_SESSION['csrf_login'], $csrfToken)) {
    $_SESSION['cedula_login'] = $cedula;
}

// Detiene el proceso cuando falta alguna credencial.
if (
    !preg_match('/^[0-9]{4,20}$/D', $cedula)
    || $contrasenaIngresada === ''
    || empty($_SESSION['csrf_login'])
    || !hash_equals($_SESSION['csrf_login'], $csrfToken)
) {
    header('Location: /sistemaInventario/?error=1');
    exit;
}

// Obtiene la instancia PDO creada por el archivo central de conexión.
$conexion = require __DIR__ . '/config/conexion.php';
eliminarIntentosAntiguos($conexion);

if (loginEstaBloqueado($conexion, $cedula)) {
    header('Location: /sistemaInventario/?error=limite');
    exit;
}

// Busca una cuenta administrativa activa asociada a la cédula indicada.
// :cedula es un parámetro preparado que evita inyecciones SQL.
$consulta = $conexion->prepare(
    'SELECT u.id_usuario, u.nombre, u.apellido, u.rol,
            c.id_cuenta, c.contrasena
     FROM usuarios AS u
     INNER JOIN cuentas_acceso AS c ON c.id_usuario = u.id_usuario
     WHERE u.cedula = :cedula
       AND u.rol = "ADMINISTRADOR"
       AND u.estado = "ACTIVO"
       AND c.estado = "ACTIVO"
     LIMIT 1'
);
$consulta->execute(['cedula' => $cedula]);
$administrador = $consulta->fetch();

// password_verify compara la contraseña escrita con el hash almacenado.
if (!$administrador || !password_verify($contrasenaIngresada, $administrador['contrasena'])) {
    registrarFalloLogin($conexion, $cedula);

    $error = loginEstaBloqueado($conexion, $cedula) ? 'limite' : 'credenciales';
    header('Location: /sistemaInventario/?error=' . $error);
    exit;
}

limpiarFallosLogin($conexion, $cedula);

// Cambia el identificador de sesión para prevenir la fijación de sesión.
session_regenerate_id(true);

// Guarda solamente los datos necesarios para identificar al administrador.
$_SESSION['administrador_id'] = (int) $administrador['id_usuario'];
$_SESSION['cuenta_id'] = (int) $administrador['id_cuenta'];
$_SESSION['administrador_nombre'] = $administrador['nombre'] . ' ' . $administrador['apellido'];
$_SESSION['rol'] = $administrador['rol'];
$_SESSION['ultima_actividad'] = time();
$_SESSION['csrf_logout'] = bin2hex(random_bytes(32));
unset($_SESSION['csrf_login'], $_SESSION['cedula_login']);

// Registra la fecha y hora del último acceso correcto.
$actualizarAcceso = $conexion->prepare(
    'UPDATE cuentas_acceso SET ultimo_acceso = NOW() WHERE id_cuenta = :id_cuenta'
);
$actualizarAcceso->execute(['id_cuenta' => $administrador['id_cuenta']]);

// Finaliza el login enviando al usuario al área protegida.
header('Location: /sistemaInventario/administracion');
exit;

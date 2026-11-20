<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$vistas = [
    'dashboard/index.php', 'areas/index.php', 'atributos/index.php',
    'categorias/index.php', 'usuarios/index.php', 'productos/index.php',
    'inventario/index.php', 'inventario/bajas/index.php', 'auditoria/index.php',
];
if (isset($argv[1])) {
    if (!in_array($argv[1], $vistas, true)) throw new RuntimeException('Vista no permitida');
    // La sesión de prueba vive en la carpeta temporal del usuario, no en XAMPP.
    ini_set('session.save_path', sys_get_temp_dir());
    require_once __DIR__ . '/../auth/sesion.php';
    iniciarSesionSegura();
    $db = require __DIR__ . '/../config/conexion.php';
    $cuenta = $db->query('SELECT u.id_usuario, c.id_cuenta FROM usuarios u
        JOIN cuentas_acceso c ON c.id_usuario=u.id_usuario
        WHERE u.rol="ADMINISTRADOR" AND u.estado="ACTIVO" AND c.estado="ACTIVO" LIMIT 1')->fetch();
    if (!$cuenta) throw new RuntimeException('Se necesita una cuenta activa para verificar las vistas');
    $_SESSION['administrador_id'] = $cuenta['id_usuario'];
    $_SESSION['cuenta_id'] = $cuenta['id_cuenta'];
    $_SESSION['rol'] = 'ADMINISTRADOR';
    $_SESSION['ultima_actividad'] = time();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ($argv[2] ?? '') === 'malformados'
        ? array_fill_keys(['buscar', 'estado', 'rol', 'dato', 'categoria', 'area', 'pagina', 'alerta'], ['x'])
        : [];
    set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
        if (!(error_reporting() & $nivel)) return false;
        throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
    });
    ob_start();
    try {
        require __DIR__ . '/../admin/' . $argv[1];
        $html = ob_get_clean();
        if (stripos($html, '<!DOCTYPE html>') === false || !str_contains($html, 'estructura-admin')) {
            throw new RuntimeException('Vista incompleta');
        }
    } finally {
        restore_error_handler();
        destruirSesionActual();
    }
    echo "OK: vista protegida renderizada sin advertencias\n";
    exit;
}
foreach ($vistas as $vista) {
    foreach (['normal', 'malformados'] as $caso) {
    $proceso = proc_open([PHP_BINARY, __FILE__, $vista, $caso], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    if (!is_resource($proceso)) throw new RuntimeException('No se pudo iniciar la prueba de vista');
    $salida = stream_get_contents($tuberias[1]);
    $error = stream_get_contents($tuberias[2]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);
    $estado = proc_close($proceso);
    if ($estado !== 0 || !str_contains($salida, 'OK:')) {
        throw new RuntimeException($vista . ': ' . $error . $salida);
    }
    echo 'OK: ', $vista, ' (', $caso, ")\n";
    }
}

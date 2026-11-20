<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../auth/recuperacion/servicio.php';
$db = require __DIR__ . '/../config/conexion.php';
// Tablas privadas de esta conexión; nunca se cambian contraseñas reales.
$db->exec('CREATE TEMPORARY TABLE cuentas_acceso (id_cuenta INT PRIMARY KEY, contrasena VARCHAR(255) NOT NULL) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE recuperacion_contrasena (
    id_recuperacion INT PRIMARY KEY, id_cuenta INT, estado VARCHAR(20),
    fecha_utilizacion DATETIME NULL, fecha_vencimiento DATETIME NOT NULL) ENGINE=InnoDB');
$anterior = password_hash('Anterior123', PASSWORD_DEFAULT);
$db->prepare('INSERT INTO cuentas_acceso VALUES (1, ?)')->execute([$anterior]);
$db->exec('INSERT INTO recuperacion_contrasena VALUES
    (1,1,"PENDIENTE",NULL,DATE_ADD(NOW(),INTERVAL 30 MINUTE)),
    (2,1,"PENDIENTE",NULL,DATE_SUB(NOW(),INTERVAL 1 SECOND)),
    (3,1,"EXPIRADO",NULL,DATE_ADD(NOW(),INTERVAL 30 MINUTE)),
    (4,999,"PENDIENTE",NULL,DATE_ADD(NOW(),INTERVAL 30 MINUTE)),
    (5,1,"PENDIENTE",NOW(),DATE_ADD(NOW(),INTERVAL 30 MINUTE))');
function verificarRestablecimiento(bool $ok, string $mensaje): void {
    if (!$ok) throw new RuntimeException($mensaje);
    echo "OK: $mensaje\n";
}
foreach ([2 => 'vencido al guardar', 3 => 'cancelado', 5 => 'ya utilizado'] as $id => $caso) {
    try {
        restablecerContrasena($db, $id, 1, 'Nueva1234');
        throw new RuntimeException('Se aceptó un enlace ' . $caso);
    } catch (DomainException $error) {
        verificarRestablecimiento(!$db->inTransaction() && $db->query('SELECT contrasena FROM cuentas_acceso WHERE id_cuenta=1')->fetchColumn() === $anterior, 'Enlace ' . $caso . ' conserva la contraseña');
    }
}
try {
    restablecerContrasena($db, 1, 999, 'Nueva1234');
    throw new RuntimeException('Se aceptó un enlace de otra cuenta');
} catch (DomainException $error) {
    verificarRestablecimiento(true, 'No permite utilizar el enlace para otra cuenta');
}
try {
    restablecerContrasena($db, 4, 999, 'Nueva1234');
    throw new RuntimeException('Se aceptó una cuenta inexistente');
} catch (DomainException $error) {
    verificarRestablecimiento($db->query('SELECT estado FROM recuperacion_contrasena WHERE id_recuperacion=4')->fetchColumn() === 'PENDIENTE', 'Fallo al actualizar cuenta revierte el consumo del enlace');
}
restablecerContrasena($db, 1, 1, 'Nueva1234');
verificarRestablecimiento(password_verify('Nueva1234', $db->query('SELECT contrasena FROM cuentas_acceso WHERE id_cuenta=1')->fetchColumn()), 'Enlace vigente actualiza la contraseña');
verificarRestablecimiento($db->query('SELECT fecha_utilizacion FROM recuperacion_contrasena WHERE id_recuperacion=1 AND estado="UTILIZADO"')->fetchColumn() !== false, 'Registra el uso del enlace');
try {
    restablecerContrasena($db, 1, 1, 'Otra12345');
    throw new RuntimeException('Se reutilizó un enlace');
} catch (DomainException $error) {
    verificarRestablecimiento(password_verify('Nueva1234', $db->query('SELECT contrasena FROM cuentas_acceso WHERE id_cuenta=1')->fetchColumn()), 'Segundo uso rechazado sin cambiar la contraseña');
}

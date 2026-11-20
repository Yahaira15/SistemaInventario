<?php
declare(strict_types=1);

const MAX_IMAGEN_USUARIO = 2 * 1024 * 1024;

function validarImagenUsuario(string $contenido): array
{
    if ($contenido === '' || strlen($contenido) > MAX_IMAGEN_USUARIO) {
        throw new DomainException('La imagen debe tener un tamaño máximo de 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
    $dimensiones = @getimagesizefromstring($contenido);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
        || !$dimensiones || ($dimensiones['mime'] ?? '') !== $mime) {
        throw new DomainException('Selecciona una imagen JPG, PNG o WebP válida.');
    }
    if ($dimensiones[0] < 1 || $dimensiones[1] < 1 || $dimensiones[0] > 4096 || $dimensiones[1] > 4096) {
        throw new DomainException('La imagen no puede superar los 4096 píxeles de ancho o alto.');
    }
    return ['mime' => $mime, 'contenido' => $contenido];
}

function leerImagenUsuario(mixed $archivo): ?array
{
    if ($archivo === null) return null;
    if (!is_array($archivo) || !is_int($archivo['error'] ?? null)) {
        throw new DomainException('La carga de la imagen no es válida.');
    }
    if ($archivo['error'] === UPLOAD_ERR_NO_FILE) return null;
    if (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new DomainException('La imagen debe tener un tamaño máximo de 2 MB.');
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        throw new DomainException('No se pudo subir la imagen. Selecciónala de nuevo e inténtalo otra vez.');
    }
    $temporal = $archivo['tmp_name'] ?? null;
    if (!is_string($temporal) || !is_uploaded_file($temporal)) {
        throw new DomainException('La carga de la imagen no es válida.');
    }
    $tamano = filesize($temporal);
    if ($tamano === false || $tamano > MAX_IMAGEN_USUARIO) {
        throw new DomainException('La imagen debe tener un tamaño máximo de 2 MB.');
    }
    $contenido = file_get_contents($temporal);
    if ($contenido === false) throw new RuntimeException('No se pudo leer la imagen recibida.');
    return validarImagenUsuario($contenido);
}

function guardarImagenUsuario(PDO $db, int $idUsuario, array $imagen): void
{
    if (!$db->inTransaction()) throw new LogicException('La imagen debe guardarse dentro de la transacción del usuario.');
    $q = $db->prepare('INSERT INTO usuario_imagenes (id_usuario, mime, contenido) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE mime=VALUES(mime), contenido=VALUES(contenido), fecha_actualizacion=CURRENT_TIMESTAMP');
    $q->bindValue(1, $idUsuario, PDO::PARAM_INT);
    $q->bindValue(2, $imagen['mime']);
    $q->bindValue(3, $imagen['contenido'], PDO::PARAM_LOB);
    $q->execute();
}

function tieneImagenUsuario(PDO $db, int $idUsuario): bool
{
    $q = $db->prepare('SELECT 1 FROM usuario_imagenes WHERE id_usuario = ?');
    $q->execute([$idUsuario]);
    return $q->fetchColumn() !== false;
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

function enviarCorreoRecuperacion(
    string $correoDestino,
    string $enlace
): void {
    $config = require __DIR__ . '/../../config/correo.php';

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['usuario'];
    $mail->Password = $config['contrasena'];
    $mail->Port = $config['puerto'];
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 15;

    $seguridad = strtolower(trim((string) ($config['seguridad'] ?? 'tls')));

    $mail->SMTPSecure = match ($seguridad) {
        'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
        'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
        'ninguna', 'none', '' => '',
        default => throw new InvalidArgumentException(
            'El tipo de seguridad SMTP configurado no es válido.'
        ),
    };

    $mail->setFrom($config['remitente'], $config['nombre_remitente']);
    $mail->addAddress($correoDestino);

    $enlaceHtml = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');

    $mail->isHTML(true);
    $mail->Subject = 'Recuperación de contraseña';
    $mail->Body = <<<HTML
        <h2>Recuperación de contraseña</h2>
        <p>Recibimos una solicitud para cambiar la contraseña de tu cuenta.</p>
        <p><a href="{$enlaceHtml}">Restablecer contraseña</a></p>
        <p>Este enlace estará disponible durante 30 minutos.</p>
        <p>Si no realizaste esta solicitud, puedes ignorar este mensaje.</p>
        HTML;
    $mail->AltBody =
        "Recibimos una solicitud para cambiar tu contraseña.\n\n"
        . "Abre este enlace:\n{$enlace}\n\n"
        . "El enlace estará disponible durante 30 minutos.\n"
        . "Si no realizaste esta solicitud, ignora este mensaje.";

    $mail->send();
}

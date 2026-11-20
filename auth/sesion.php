<?php

declare(strict_types=1);

function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $usaHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $usaHttps,
        'cookie_path' => '/sistemaInventario/',
        'use_strict_mode' => true,
    ]);
}

function destruirSesionActual(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parametros = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parametros['path'],
            'domain' => $parametros['domain'],
            'secure' => $parametros['secure'],
            'httponly' => $parametros['httponly'],
            'samesite' => $parametros['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

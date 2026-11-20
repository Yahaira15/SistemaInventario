<?php

declare(strict_types=1);

/** Cada retorno tiene un identificador propio, incluso para el mismo equipo. */
function guardarResultadoInventario(array $resultado): string
{
    $token = bin2hex(random_bytes(16));
    $pendientes = $_SESSION['inventario_resultados'] ?? [];
    // Acota el almacenamiento a los 20 retornos más recientes de la sesión.
    $pendientes = array_slice($pendientes, -19, null, true);
    $pendientes[$token] = $resultado;
    $_SESSION['inventario_resultados'] = $pendientes;
    return $token;
}

function consultarResultadoInventario(mixed $token): array
{
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/', $token)) {
        return [];
    }
    return $_SESSION['inventario_resultados'][$token] ?? [];
}

/** Solo elimina el retorno indicado y perteneciente al equipo del formulario. */
function descartarResultadoInventario(mixed $token, int $producto): void
{
    $resultado = consultarResultadoInventario($token);
    if ($resultado && ($resultado['id_producto'] ?? null) === $producto) {
        unset($_SESSION['inventario_resultados'][$token]);
    }
}

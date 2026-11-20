<?php
declare(strict_types=1);

/** Los campos de texto HTTP no deben aceptar listas ni objetos. */
function textoEntrada(array $entrada, string $campo): string
{
    return is_string($entrada[$campo] ?? null) ? $entrada[$campo] : '';
}

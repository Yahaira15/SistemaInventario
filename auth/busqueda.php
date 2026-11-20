<?php

declare(strict_types=1);

function patronBusqueda(string $texto): string
{
    return '%' . strtr($texto, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

/** Normaliza tanto enlaces antiguos con un ID como selección múltiple. */
function normalizarCategoriasFiltro(mixed $entrada): array
{
    $ids = [];
    foreach (is_array($entrada) ? $entrada : [$entrada] as $valor) {
        if (!is_scalar($valor)) continue;
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false) $ids[$id] = $id;
    }
    return array_values($ids);
}

/** Solo parámetros de listado; nunca acepta una URL de retorno externa. */
function contextoListado(string $modulo, array $entrada): array
{
    $campos = match ($modulo) {
        'productos' => ['buscar', 'categoria', 'estado', 'pagina'],
        'usuarios' => ['buscar', 'area', 'estado', 'rol', 'pagina'],
        'atributos' => ['buscar', 'dato', 'estado', 'pagina'],
        'areas', 'categorias' => ['buscar', 'estado', 'pagina'],
        default => [],
    };
    $salida = [];
    foreach ($campos as $campo) {
        if ($modulo === 'productos' && $campo === 'categoria' && is_array($entrada[$campo] ?? null)) {
            $ids = normalizarCategoriasFiltro($entrada[$campo]);
            if ($ids) $salida[$campo] = $ids;
            continue;
        }
        if (isset($entrada[$campo]) && is_scalar($entrada[$campo]) && (string)$entrada[$campo] !== '') {
            $salida[$campo] = mb_substr((string)$entrada[$campo], 0, 100);
        }
    }
    return $salida;
}

function urlContextoListado(string $modulo, array $extra = []): string
{
    $parametros = array_replace(contextoListado($modulo, $_GET), $extra);
    return '/sistemaInventario/administracion/' . $modulo . ($parametros ? '?' . http_build_query($parametros) : '');
}

<?php

declare(strict_types=1);

function normalizarUnidades(mixed $entrada): array
{
    if (!is_array($entrada) || count($entrada) > 50) {
        throw new DomainException('Unidades: ingresa hasta 50 unidades.');
    }
    $unidades = [];
    foreach ($entrada as $valor) {
        if (!is_scalar($valor)) {
            throw new DomainException('Unidad no válida.');
        }
        $simbolo = trim((string) $valor);
        if ($simbolo === '') {
            continue;
        }
        if (mb_strlen($simbolo) > 50) {
            throw new DomainException('Cada unidad admite hasta 50 caracteres.');
        }
        $clave = mb_strtolower($simbolo, 'UTF-8');
        if (isset($unidades[$clave])) {
            throw new DomainException('No repitas unidades en el mismo atributo.');
        }
        $unidades[$clave] = $simbolo;
    }
    return array_values($unidades);
}

// El llamador mantiene una transacción y bloquea el atributo antes de editarlo.
// Nunca renombra ni elimina unidades: quitar una conserva los vínculos históricos.
function guardarUnidadesAtributo(PDO $c, int $id, array $simbolos): void
{
    $q = $c->prepare('SELECT id_atributo_unidad FROM atributo_unidades WHERE id_atributo = ?');
    $q->execute([$id]);
    if ($simbolos === [] && $q->fetchColumn() !== false) {
        throw new DomainException('Conserva al menos una unidad permitida para este atributo.');
    }
    $q->closeCursor();
    $c->prepare('UPDATE atributo_unidades SET estado = "INACTIVO" WHERE id_atributo = ?')->execute([$id]);
    $buscar = $c->prepare('SELECT id_atributo_unidad FROM atributo_unidades WHERE id_atributo = ? AND simbolo = ?');
    $activar = $c->prepare('UPDATE atributo_unidades SET estado = "ACTIVO" WHERE id_atributo_unidad = ?');
    $crear = $c->prepare('INSERT INTO atributo_unidades (id_atributo, simbolo, estado) VALUES (?, ?, "ACTIVO")');
    $vistos = [];
    foreach ($simbolos as $simbolo) {
        $buscar->execute([$id, $simbolo]);
        $unidad = $buscar->fetchColumn();
        if ($unidad !== false) {
            if (isset($vistos[$unidad])) {
                throw new DomainException('No repitas unidades equivalentes.');
            }
            $activar->execute([$unidad]);
        } else {
            $crear->execute([$id, $simbolo]);
            $unidad = $c->lastInsertId();
        }
        $vistos[$unidad] = true;
    }
}

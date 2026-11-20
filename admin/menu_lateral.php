<?php

declare(strict_types=1);

$seccionActiva = $seccionActiva ?? '';

?>
<aside class="menu-lateral">
    <div class="menu-marca">
        <img src="/sistemaInventario/img/logoADP.png" alt="ADP">
        <span>Sistema de Inventario</span>
    </div>

    <nav class="navegacion-lateral" aria-label="Módulos administrativos">
        <a class="<?= $seccionActiva === 'dashboard' ? 'activo' : '' ?>" href="/sistemaInventario/administracion">Dashboard</a>
        <a class="<?= $seccionActiva === 'inventario' ? 'activo' : '' ?>" href="/sistemaInventario/administracion/inventario">Inventario</a>
        <a class="<?= $seccionActiva === 'productos' ? 'activo' : '' ?>" href="/sistemaInventario/administracion/productos">Productos</a>
        <a class="<?= in_array($seccionActiva, ['usuarios', 'areas'], true) ? 'activo' : '' ?>" href="/sistemaInventario/administracion/usuarios">Usuarios</a>
        <a class="<?= in_array($seccionActiva, ['categorias', 'atributos'], true) ? 'activo' : '' ?>" href="/sistemaInventario/administracion/categorias">Categorías</a>
        <a class="<?= $seccionActiva === 'auditoria' ? 'activo' : '' ?>" href="/sistemaInventario/administracion/auditoria"> Auditoría</a>
    </nav>

</aside>

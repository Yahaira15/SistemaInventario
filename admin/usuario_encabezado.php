<?php
if (empty($_SESSION['csrf_logout'])) {
    $_SESSION['csrf_logout'] = bin2hex(random_bytes(32));
}
$nombreEncabezado = trim((string) ($_SESSION['administrador_nombre'] ?? 'Administrador'));
$nombreEncabezado = $nombreEncabezado !== '' ? $nombreEncabezado : 'Administrador';
$partesNombre = preg_split('/\s+/u', $nombreEncabezado) ?: [$nombreEncabezado];
$inicialesEncabezado = mb_substr($partesNombre[0], 0, 1, 'UTF-8');
if (count($partesNombre) > 1) {
    $inicialesEncabezado .= mb_substr($partesNombre[count($partesNombre) - 1], 0, 1, 'UTF-8');
}
$inicialesEncabezado = mb_strtoupper($inicialesEncabezado, 'UTF-8');
$idEncabezado = (int) ($_SESSION['administrador_id'] ?? 0);
$imagenEncabezado = false;
if ($idEncabezado > 0) {
    require_once __DIR__ . '/usuarios/imagenes.php';
    $conexionEncabezado = isset($conexion) && $conexion instanceof PDO
        ? $conexion : require __DIR__ . '/../config/conexion.php';
    $imagenEncabezado = tieneImagenUsuario($conexionEncabezado, $idEncabezado);
}
?>
<details class="usuario-encabezado">
    <summary aria-label="<?= htmlspecialchars('Abrir menú de ' . $nombreEncabezado, ENT_QUOTES, 'UTF-8') ?>">
        <span class="usuario-identidad">
            <strong><?= htmlspecialchars($nombreEncabezado, ENT_QUOTES, 'UTF-8') ?></strong>
            <span>Administrador</span>
        </span>
        <span class="usuario-avatar" aria-hidden="true">
            <span><?= htmlspecialchars($inicialesEncabezado, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($imagenEncabezado): ?>
                <img src="/sistemaInventario/admin/usuarios/imagen.php?usuario=<?= $idEncabezado ?>" alt="" class="usuario-avatar-imagen">
            <?php endif; ?>
        </span>
    </summary>
    <div class="usuario-desplegable">
        <div class="usuario-resumen">
            <span class="usuario-etiqueta">MI CUENTA</span>
            <strong><?= htmlspecialchars($nombreEncabezado, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="usuario-estado">Sesión activa</span>
        </div>
        <form action="/sistemaInventario/salir" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_logout'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5H5v14h4M14 8l4 4-4 4M9 12h9"/></svg>
                Cerrar sesión
            </button>
        </form>
    </div>
</details>
<script src="/sistemaInventario/admin/js/usuario-encabezado.js" defer></script>

<script src="/sistemaInventario/admin/js/paginacion.js" defer></script>

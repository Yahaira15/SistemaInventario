<?php
if (empty($_SESSION['csrf_logout'])) {
    $_SESSION['csrf_logout'] = bin2hex(random_bytes(32));
}
?>
<details class="usuario-encabezado">
    <summary aria-label="Opciones de usuario">
        <strong><?= htmlspecialchars((string) ($_SESSION['administrador_nombre'] ?? 'Administrador'), ENT_QUOTES, 'UTF-8') ?></strong>
        <span class="usuario-flecha" aria-hidden="true"></span>
    </summary>
    <div class="usuario-desplegable">
        <form action="/sistemaInventario/salir" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_logout'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </div>
</details>
<script src="/sistemaInventario/admin/js/usuario-encabezado.js" defer></script>

<section class="login-brand recuperacion-brand" aria-labelledby="titulo-recuperacion">
    <img class="recuperacion-logo" src="/sistemaInventario/img/logoADP.png" alt="ADP">
    <span class="login-etiqueta">SISTEMA DE INVENTARIO</span>
    <h2 id="titulo-recuperacion">Recupera el acceso<br>a tu cuenta.</h2>
    <p>Vuelve a administrar tus equipos y asignaciones en unos pocos pasos.</p>
    <ol class="recuperacion-pasos" aria-label="Pasos de recuperación">
        <li <?= $pasoRecuperacion === 1 ? 'aria-current="step"' : '' ?>><span>1</span><div><strong>Ingresa tu correo</strong><p>Usa el correo asociado a tu cuenta.</p></div></li>
        <li><span>2</span><div><strong>Revisa tu bandeja de entrada</strong><p>Abre el enlace que recibirás por correo.</p></div></li>
        <li <?= $pasoRecuperacion === 3 ? 'aria-current="step"' : '' ?>><span>3</span><div><strong>Crea una nueva contraseña</strong><p>Guarda tu contraseña y vuelve a ingresar.</p></div></li>
    </ol>
</section>

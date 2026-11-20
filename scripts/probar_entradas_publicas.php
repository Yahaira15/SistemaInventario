<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../auth/entrada.php';
foreach ([null, [], ['1234'], ['campo' => '1234'], 1234, true] as $valor) {
    if (textoEntrada(['campo' => $valor], 'campo') !== '') throw new RuntimeException('Se aceptó un campo que no es texto');
}
if (textoEntrada(['campo' => '001234'], 'campo') !== '001234') throw new RuntimeException('Se perdieron ceros iniciales');
$casos = [
    ['/consultas', ['cedula' => ['1234']], 'Ingresa una cédula válida.'],
    ['/consultas', ['cedula' => 'abc'], 'Ingresa una cédula válida.'],
    ['/consultas', ['cedula' => '123'], 'Ingresa una cédula válida.'],
    ['/consultas', ['cedula' => str_repeat('1', 21)], 'Ingresa una cédula válida.'],
    ['/consultas', ['cedula' => ''], 'Ingresa una cédula válida.'],
    ['/restablecer?token%5B%5D=1234', null, 'El enlace no es válido o ya venció.'],
    ['/?error%5B%5D=1', null, 'name="cedula"'],
    ['/autenticar', ['cedula' => ['1234'], 'contrasena' => ['x'], 'csrf_token' => ['x']], 'name="cedula"'],
    ['/recuperar', ['correo' => ['correo@example.test'], 'csrf_token' => ['x']], 'La solicitud no es válida.'],
    ['/salir', ['csrf_token' => ['x']], 'Solicitud no válida.'],
];
foreach ($casos as [$ruta, $datos, $esperado]) {
    $contexto = stream_context_create(['http' => [
        'method' => $datos === null ? 'GET' : 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $datos === null ? '' : http_build_query($datos),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $html = file_get_contents('http://localhost/sistemaInventario' . $ruta, false, $contexto);
    if ($html === false || !str_contains($html, $esperado) || preg_match('/Warning:|Fatal error:|Array to string conversion/', $html)) {
        throw new RuntimeException('Falló la validación pública: ' . $ruta);
    }
    echo 'OK: ', $ruta, " rechaza entradas inválidas sin advertencias\n";
}

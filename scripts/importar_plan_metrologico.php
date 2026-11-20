<?php
/** Uso: php -d extension=zip scripts/importar_plan_metrologico.php archivo.xlsx [--insertar --administrador=ID] */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../admin/productos/servicio.php';

function analizarPlan(string $archivo): array
{
    $reader = new PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $reader->setReadDataOnly(true);
    $book = $reader->load($archivo);
    $equipos = []; $avisos = [];
    // El usuario excluyó expresamente todas las hojas anteriores.
    $hojas = ['AÑO 2026'];
    foreach ($hojas as $nombre) {
        $s = $book->getSheetByName($nombre);
        if (!$s) throw new RuntimeException('No existe la hoja requerida AÑO 2026.');
        for ($fila = 6; $fila <= $s->getHighestRow(); ++$fila) {
            $v = [];
            foreach (range('A', 'K') as $col) $v[$col] = trim((string) $s->getCell($col . $fila)->getValue());
            $resumen = $nombre === 'Hoja3';
            if ($resumen) {
                if (!ctype_digit($v['A']) || $v['B'] === '') continue;
                $nombreEquipo=$v['B']; $marca=$v['C']; $modelo=$v['D']; $serie=$v['E']; $rango=$v['F'];
            } else {
                if ($v['A'] === '' || $v['B'] === '' || str_starts_with($v['A'], '=')) continue;
                $nombreEquipo=$v['A']; $marca=$v['B']; $modelo=$v['C']; $serie=$v['D']; $rango=$v['E'];
            }
            $serieValida = !in_array(mb_strtoupper($serie), ['', 'N/A', 'NO REGISTRA'], true);
            $moderna = in_array($nombre, ['AÑO 2026','AÑO 2025'], true);
            $interno = $moderna && is_numeric($v['H']) ? $v['H'] : '';
            $clave = $serieValida ? 'serie:' . mb_strtoupper($serie) : 'sinserie:' . mb_strtoupper(implode('|', [$nombreEquipo,$marca,$modelo,$rango,$interno]));
            if (!$serieValida && $nombre !== 'AÑO 2026') continue;
            if (isset($equipos[$clave])) {
                $equipos[$clave]['referencias'][] = "$nombre!$fila";
                if ($nombre === 'AÑO 2026') $avisos[] = "Duplicado $nombre!$fila de " . $equipos[$clave]['origen'];
                continue;
            }
            $ubicacion = $resumen ? '' : ($moderna ? $v['I'] : $v['H']);
            $trabajo = $resumen ? '' : ($moderna ? $v['J'] : $v['I']);
            $frecuencia = $resumen ? '' : ($moderna ? $v['K'] : $v['J']);
            if ($moderna && $v['J'] === '12 MESES' && $v['K'] === '') {
                $ubicacion=$v['H']; $trabajo=$v['I']; $frecuencia=$v['J'];
                $avisos[]="Columnas desplazadas corregidas en $nombre!$fila";
            }
            $campos = ['Fabricante'=>$marca,'Modelo'=>$modelo,'Número de serie'=>$serie,'Magnitud o rango'=>$rango,
                'Mide'=>$resumen ? '' : $v['F'], 'Ubicación'=>$ubicacion,'Tipo de trabajo'=>$trabajo,
                'Frecuencia de calibración'=>$frecuencia,'Código interno de origen'=>$interno,
                'Observaciones metrológicas'=>$moderna ? trim((string)$s->getCell('AO'.$fila)->getValue()) : '',
                'Origen del registro'=>"$nombre!$fila"];
            if ($resumen) {
                foreach (['G'=>'Última calibración registrada','H'=>'Vencimiento registrado'] as $col=>$etiqueta) {
                    if (is_numeric($v[$col])) $campos[$etiqueta]=PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v[$col])->format('Y-m-d');
                }
            }
            if ($nombre !== 'AÑO 2026') $avisos[]="Herramienta adicional de hoja histórica: $nombre!$fila; ubicación y condición requieren revisión.";
            $equipos[$clave]=['clave'=>$clave,'descripcion'=>$nombreEquipo,'campos'=>$campos,'origen'=>"$nombre!$fila",'referencias'=>["$nombre!$fila"]];
        }
    }
    return ['equipos'=>array_values($equipos),'avisos'=>$avisos];
}

try {
    if (!isset($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Indica el archivo XLSX existente.');
    $plan=analizarPlan($argv[1]);
    $destino=__DIR__.'/plan_metrologico_analizado.json';
    file_put_contents($destino,json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    echo count($plan['equipos']), " herramientas únicas. Análisis: $destino\n";
    if (!in_array('--insertar',$argv,true)) exit(0);
    $admin=0;
    foreach ($argv as $arg) if (str_starts_with($arg,'--administrador=')) $admin=(int)substr($arg,16);
    if ($admin < 1) throw new RuntimeException('Indica --administrador=ID para registrar la auditoría.');
    $db=require __DIR__.'/../config/conexion.php';
    $q=$db->prepare('SELECT id_usuario FROM usuarios WHERE id_usuario=? AND rol="ADMINISTRADOR" AND estado="ACTIVO"');
    $q->execute([$admin]);
    if (!$q->fetchColumn()) throw new RuntimeException('Administrador no habilitado.');
    // Bloqueo exclusivo de esta importación; cada equipo se guarda mediante el servicio habitual.
    if ((int)$db->query("SELECT GET_LOCK('importar_plan_metrologico', 5)")->fetchColumn() !== 1) throw new RuntimeException('Hay otra importación en curso.');
    try {
        $db->beginTransaction();
        $q=$db->prepare('SELECT id_categoria,estado,prefijo_codigo FROM categorias WHERE nombre=? FOR UPDATE');
        $q->execute(['HERRAMIENTAS METROLÓGICAS']); $cat=$q->fetch();
        if (!$cat) {
            $db->prepare('INSERT INTO categorias(nombre,descripcion,estado,prefijo_codigo) VALUES (?, ?, "ACTIVO", ?)')->execute(['HERRAMIENTAS METROLÓGICAS','Instrumentos del plan metrológico GM-FT02-01','ADP-MET']);
            $cat=['id_categoria'=>(int)$db->lastInsertId(),'estado'=>'ACTIVO','prefijo_codigo'=>'ADP-MET'];
            registrarCambioAuditoria($db,$admin,'categorias',$cat['id_categoria'],null);
        }
        if ($cat['estado']!=='ACTIVO' || !$cat['prefijo_codigo']) throw new RuntimeException('Categoría inactiva o sin prefijo.');
        $q=$db->prepare('SELECT id_area FROM areas WHERE nombre=? AND estado="ACTIVO"');
        $q->execute(['Mantenimiento']); $area=$q->fetchColumn();
        if (!$area) {
            $db->prepare('INSERT INTO areas(nombre,estado) VALUES (?,"ACTIVO")')->execute(['Mantenimiento']);
            $area=(int)$db->lastInsertId(); registrarCambioAuditoria($db,$admin,'areas',$area,null);
        }
        $relaciones=[]; $nombres=[];
        foreach ($plan['equipos'] as $e) foreach ($e['campos'] as $nombre=>$valor) $nombres[$nombre]=true;
        foreach (array_keys($nombres) as $orden=>$nombre) {
            $q=$db->prepare('SELECT * FROM atributos WHERE nombre=?'); $q->execute([$nombre]); $a=$q->fetch();
            if (!$a) {
                $db->prepare('INSERT INTO atributos(nombre,tipo_dato,longitud_maxima,estado) VALUES (?,"TEXTO",255,"ACTIVO")')->execute([$nombre]);
                $a=['id_atributo'=>(int)$db->lastInsertId(),'tipo_dato'=>'TEXTO','estado'=>'ACTIVO'];
                registrarCambioAuditoria($db,$admin,'atributos',$a['id_atributo'],null);
            }
            if ($a['tipo_dato']!=='TEXTO' || $a['estado']!=='ACTIVO') throw new RuntimeException("Revisar atributo existente: $nombre");
            $q=$db->prepare('SELECT id_categoria_atributo,estado FROM categoria_atributos WHERE id_categoria=? AND id_atributo=?');
            $q->execute([$cat['id_categoria'],$a['id_atributo']]); $r=$q->fetch();
            if (!$r) {
                $db->prepare('INSERT INTO categoria_atributos(id_categoria,id_atributo,requerido,orden_visual,estado) VALUES (?,?,0,?,"ACTIVO")')->execute([$cat['id_categoria'],$a['id_atributo'],$orden]);
                $r=['id_categoria_atributo'=>(int)$db->lastInsertId(),'estado'=>'ACTIVO'];
                registrarCambioAuditoria($db,$admin,'categoria_atributos',$r['id_categoria_atributo'],null);
            }
            if ($r['estado']!=='ACTIVO') throw new RuntimeException("Relación inactiva: $nombre");
            $relaciones[$nombre]=$r['id_categoria_atributo'];
        }
        $db->commit();
        $resultado=[];
        foreach ($plan['equipos'] as $e) {
            $token=hash('sha256','GM-FT02-01|'.$e['clave']);
            $q=$db->prepare('SELECT id_producto FROM productos WHERE token_creacion=?'); $q->execute([$token]); $existente=$q->fetchColumn();
            if (!$existente && !str_starts_with($e['clave'],'sinserie:')) {
                $q=$db->prepare('SELECT DISTINCT p.id_producto FROM productos p JOIN producto_atributo_valores v ON v.id_producto=p.id_producto JOIN categoria_atributos ca ON ca.id_categoria_atributo=v.id_categoria_atributo JOIN atributos a ON a.id_atributo=ca.id_atributo WHERE a.nombre IN ("Número de serie","Serial","Serie") AND UPPER(TRIM(v.valor_texto))=?');
                $q->execute([mb_strtoupper($e['campos']['Número de serie'])]); $ids=$q->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids)>1) throw new RuntimeException('Serie duplicada en BD: '.$e['clave']);
                $existente=$ids[0]??false;
            }
            $valores=[];
            foreach ($e['campos'] as $nombre=>$valor) if ($valor!=='') $valores[$relaciones[$nombre]]=$valor;
            $id=$existente ?: guardarProducto($db,['id_categoria'=>$cat['id_categoria'],'id_area'=>$area,'generar_codigo'=>'1','descripcion'=>$e['descripcion'],'token_creacion'=>$token,'atributos'=>$valores],null,$admin);
            $p=consultarProducto($db,(int)$id);
            $resultado[]=['id_producto'=>$id,'codigo'=>$p['codigo'],'origen'=>$e['origen'],'accion'=>$existente?'existente':'insertado'];
            file_put_contents(__DIR__.'/resultado_importacion_metrologica.json',json_encode($resultado,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            echo $p['codigo'], ' ', $existente?'existente':'insertado', "\n";
        }
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        $db->query("SELECT RELEASE_LOCK('importar_plan_metrologico')");
    }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }

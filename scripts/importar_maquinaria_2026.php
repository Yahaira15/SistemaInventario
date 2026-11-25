<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../admin/productos/servicio.php';

// El estilo se carga expresamente: el usuario excluyó los equipos en rojo.
function analizarMaquinaria(string $archivo): array
{
    $reader=new PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $reader->setLoadSheetsOnly(['MANTENIMIENTO MAQUINARIA 26']);
    $book=$reader->load($archivo);
    $s=$book->getSheetByName('MANTENIMIENTO MAQUINARIA 26');
    if (!$s || $s->getConditionalStylesCollection()) throw new RuntimeException('Revisar hoja o formato condicional antes de importar.');
    $plan=['incluidos'=>[],'excluidos'=>[],'series_repetidas'=>[]]; $numeros=[]; $series=[];
    $mapa=['D'=>'Ubicación','E'=>'Fabricante','F'=>'Número de serie','G'=>'Modelo','H'=>'Cantidad en documento','I'=>'Vida útil estimada','J'=>'Criterio de aceptación','K'=>'Criterio de rechazo','L'=>'Justificación de vida útil','M'=>'Estado en documento','AL'=>'Observaciones de mantenimiento'];
    for ($r=6;$r<=$s->getHighestRow();++$r) {
        $numero=trim((string)$s->getCell('A'.$r)->getValue());
        $descripcion=trim((string)$s->getCell('B'.$r)->getValue());
        if (!ctype_digit($numero) || $descripcion==='') continue;
        if (isset($numeros[$numero])) throw new RuntimeException('Número de equipo repetido: '.$numero);
        $numeros[$numero]=true;
        $color=$s->getStyle('B'.$r)->getFill()->getStartColor()->getARGB();
        $e=['numero'=>$numero,'descripcion'=>$descripcion,'fila'=>$r,'color'=>$color,'campos'=>[]];
        if (in_array($color,['FF953735','FFFF0000'],true)) { $plan['excluidos'][]=$e; continue; }
        if (!in_array($color,['FF92D050','FFFFFFFF'],true)) throw new RuntimeException('Revisar color del equipo en fila '.$r.': '.$color);
        foreach ($mapa as $col=>$nombre) $e['campos'][$nombre]=trim((string)$s->getCell($col.$r)->getValue());
        // Dos ubicaciones son enlaces a fichas, no ubicaciones físicas.
        if (str_contains($e['campos']['Ubicación'],'.xls#')) {
            $e['campos']['Referencia de ficha de mantenimiento']=$e['campos']['Ubicación'];
            $e['campos']['Ubicación']='';
        }
        $e['campos']['Número de equipo en documento']=$numero;
        $e['campos']['Origen del registro']='GM-FT13-01 | MANTENIMIENTO MAQUINARIA 26!'.$r;
        foreach ($e['campos'] as $nombre=>$valor) if (mb_strlen($valor)>255) throw new RuntimeException('Valor demasiado largo en fila '.$r.': '.$nombre);
        $serie=mb_strtoupper($e['campos']['Número de serie']);
        if (!in_array($serie,['','-','N/A'],true)) $series[$serie][]=$numero;
        $e['token']=hash('sha256','GM-FT13-01|2026|equipo:'.$numero);
        $plan['incluidos'][]=$e;
    }
    foreach ($series as $serie=>$items) if (count($items)>1) $plan['series_repetidas'][(string)$serie]=$items;
    $book->disconnectWorksheets();
    return $plan;
}

try {
    if (!isset($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Indica un XLSX existente.');
    $plan=analizarMaquinaria($argv[1]);
    file_put_contents(__DIR__.'/maquinaria_2026_analizada.json',json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    echo count($plan['incluidos']), ' incluidos; ',count($plan['excluidos'])," excluidos en rojo.\n";
    if (!in_array('--insertar',$argv,true)) exit(0);
    $admin=0;
    foreach ($argv as $arg) if (str_starts_with($arg,'--administrador=')) $admin=(int)substr($arg,16);
    $db=require __DIR__.'/../config/conexion.php';
    $q=$db->prepare('SELECT id_usuario FROM usuarios WHERE id_usuario=? AND rol="ADMINISTRADOR" AND estado="ACTIVO"'); $q->execute([$admin]);
    if (!$q->fetchColumn()) throw new RuntimeException('Administrador no habilitado.');
    if ((int)$db->query("SELECT GET_LOCK('importar_maquinaria_2026',5)")->fetchColumn()!==1) throw new RuntimeException('Otra importación en curso.');
    try {
        $db->beginTransaction();
        $q=$db->prepare('SELECT id_categoria,estado,prefijo_codigo FROM categorias WHERE nombre=? FOR UPDATE'); $q->execute(['HERRAMIENTAS']); $cat=$q->fetch();
        if (!$cat || $cat['estado']!=='ACTIVO' || !$cat['prefijo_codigo']) throw new RuntimeException('Revisar categoría HERRAMIENTAS.');
        $q=$db->prepare('SELECT id_area FROM areas WHERE nombre=? AND estado="ACTIVO"'); $q->execute(['Mantenimiento']); $area=$q->fetchColumn();
        if (!$area) throw new RuntimeException('No existe área Mantenimiento activa.');
        // Se revisan coincidencias previas antes de modificar catálogos o productos.
        foreach ($plan['incluidos'] as $e) {
            $serie=mb_strtoupper($e['campos']['Número de serie']);
            if (in_array($serie,['','-','N/A'],true)) continue;
            $q=$db->prepare('SELECT DISTINCT p.id_producto FROM productos p JOIN producto_atributo_valores v ON v.id_producto=p.id_producto JOIN categoria_atributos ca ON ca.id_categoria_atributo=v.id_categoria_atributo JOIN atributos a ON a.id_atributo=ca.id_atributo WHERE a.nombre IN ("Número de serie","Serial","Serie") AND UPPER(TRIM(v.valor_texto))=? AND (p.token_creacion IS NULL OR p.token_creacion NOT IN ('.implode(',',array_fill(0,count($plan['incluidos']),'?')).'))');
            $q->execute(array_merge([$serie],array_column($plan['incluidos'],'token')));
            if ($q->fetchColumn()) throw new RuntimeException('Serie ya registrada en otra carga: '.$serie.'. Revisar antes de continuar.');
        }
        $nombres=[]; $relaciones=[];
        foreach ($plan['incluidos'] as $e) foreach ($e['campos'] as $nombre=>$valor) $nombres[$nombre]=true;
        $q=$db->prepare('SELECT COALESCE(MAX(orden_visual),0) FROM categoria_atributos WHERE id_categoria=?'); $q->execute([$cat['id_categoria']]); $orden=(int)$q->fetchColumn();
        foreach (array_keys($nombres) as $nombre) {
            $q=$db->prepare('SELECT * FROM atributos WHERE nombre=?'); $q->execute([$nombre]); $a=$q->fetch();
            if (!$a) {
                $db->prepare('INSERT INTO atributos(nombre,tipo_dato,longitud_maxima,estado) VALUES (?,"TEXTO",255,"ACTIVO")')->execute([$nombre]);
                $a=['id_atributo'=>(int)$db->lastInsertId(),'tipo_dato'=>'TEXTO','estado'=>'ACTIVO','longitud_maxima'=>255];
                registrarCambioAuditoria($db,$admin,'atributos',$a['id_atributo'],null);
            }
            if ($a['tipo_dato']!=='TEXTO' || $a['estado']!=='ACTIVO') throw new RuntimeException('Revisar atributo '.$nombre);
            foreach ($plan['incluidos'] as $e) if (isset($e['campos'][$nombre]) && $a['longitud_maxima'] && mb_strlen($e['campos'][$nombre])>(int)$a['longitud_maxima']) throw new RuntimeException('Longitud excedida: '.$nombre);
            $q=$db->prepare('SELECT id_categoria_atributo,estado FROM categoria_atributos WHERE id_categoria=? AND id_atributo=?'); $q->execute([$cat['id_categoria'],$a['id_atributo']]); $rel=$q->fetch();
            if (!$rel) {
                $db->prepare('INSERT INTO categoria_atributos(id_categoria,id_atributo,requerido,orden_visual,estado) VALUES (?,?,0,?,"ACTIVO")')->execute([$cat['id_categoria'],$a['id_atributo'],++$orden]);
                $rel=['id_categoria_atributo'=>(int)$db->lastInsertId(),'estado'=>'ACTIVO']; registrarCambioAuditoria($db,$admin,'categoria_atributos',$rel['id_categoria_atributo'],null);
            }
            if ($rel['estado']!=='ACTIVO') throw new RuntimeException('Relación inactiva: '.$nombre);
            $relaciones[$nombre]=$rel['id_categoria_atributo'];
        }
        $db->commit();
        $resultado=[];
        foreach ($plan['incluidos'] as $e) {
            $q=$db->prepare('SELECT id_producto FROM productos WHERE token_creacion=?'); $q->execute([$e['token']]); $existente=$q->fetchColumn();
            $valores=[]; foreach ($e['campos'] as $nombre=>$valor) if ($valor!=='') $valores[$relaciones[$nombre]]=$valor;
            $id=$existente ?: guardarProducto($db,['id_categoria'=>$cat['id_categoria'],'id_area'=>$area,'generar_codigo'=>'1','descripcion'=>$e['descripcion'],'token_creacion'=>$e['token'],'atributos'=>$valores],null,$admin);
            $p=consultarProducto($db,(int)$id);
            $resultado[]=['numero'=>$e['numero'],'fila'=>$e['fila'],'id_producto'=>(int)$id,'codigo'=>$p['codigo'],'accion'=>$existente?'existente':'insertado'];
            file_put_contents(__DIR__.'/resultado_importacion_maquinaria_2026.json',json_encode($resultado,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            echo $e['numero'],' ',$p['codigo'],' ',$existente?'existente':'insertado',"\n";
        }
        // Verifica los atributos conservados y que ninguna fila roja tenga token de carga.
        foreach ($resultado as $i=>$res) {
            $guardados=[]; foreach (valoresProducto($db,$res['id_producto']) as $v) $guardados[$v['id_categoria_atributo']]=$v['valor_texto'];
            foreach ($plan['incluidos'][$i]['campos'] as $nombre=>$valor) if ($valor!=='' && ($guardados[$relaciones[$nombre]]??null)!==$valor) throw new RuntimeException('Verificación falló: '.$res['codigo'].' '.$nombre);
        }
        foreach ($plan['excluidos'] as $e) {
            $q=$db->prepare('SELECT id_producto FROM productos WHERE token_creacion=?'); $q->execute([hash('sha256','GM-FT13-01|2026|equipo:'.$e['numero'])]);
            if ($q->fetchColumn()) throw new RuntimeException('Se encontró un equipo excluido en la carga.');
        }
        echo 'Verificados ',count($resultado)," productos y sus atributos; excluidos sin insertar.\n";
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        $db->query("SELECT RELEASE_LOCK('importar_maquinaria_2026')");
    }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }

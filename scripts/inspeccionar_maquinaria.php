<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../vendor/autoload.php';
$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($argv[1]);
echo json_encode($book->getSheetNames(),JSON_UNESCAPED_UNICODE),"\n";
$s=$book->getSheetByName('MANTENIMIENTO MAQUINARIA 26');
if (!$s) throw new RuntimeException('Hoja no encontrada');
echo 'Dimensiones: ',$s->getHighestRow(),' ',$s->getHighestColumn(),"\n";
foreach ($s->getRowIterator() as $row) {
 $cells=[];
 foreach ($row->getCellIterator() as $c) {
  $v=$c->getValue(); if ($v===null || $v==='') continue;
  $st=$c->getStyle();
  $cells[]=['celda'=>$c->getCoordinate(),'valor'=>$v,'relleno'=>$st->getFill()->getStartColor()->getARGB(),'tipo'=>$st->getFill()->getFillType(),'fuente'=>$st->getFont()->getColor()->getARGB()];
 }
 if ($row->getRowIndex()>=6 && is_numeric($s->getCell('A'.$row->getRowIndex())->getValue())) {
  $r=$row->getRowIndex(); $v=[];
  foreach (['A','B','D','E','F','G','H','M','AL'] as $col) $v[$col]=(string)$s->getCell($col.$r)->getValue();
  $v['fila']=$r; $v['color']=$s->getStyle('B'.$r)->getFill()->getStartColor()->getARGB();
  $v['rojos']=array_values(array_filter($cells,fn($c)=>in_array($c['relleno'],['FF953735','FFFF0000'])||$c['fuente']==='FFFF0000'));
  echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),"\n";
 }
}
echo 'Condicionales: ',json_encode(array_keys($s->getConditionalStylesCollection())),"\n";

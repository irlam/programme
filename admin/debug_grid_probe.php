<?php
declare(strict_types=1);
require __DIR__ . '/../api/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD']!=='POST'){
  echo '<form method="post" enctype="multipart/form-data" style="font-family:system-ui">';
  echo '<h3>Grid Probe</h3><input type="file" name="file" accept=".xlsx"><button>Analyze</button></form>'; exit;
}
$f=$_FILES['file']['tmp_name']??null; if(!$f) die('no file');
if(!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) die('PhpSpreadsheet missing');

$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx'); $reader->setReadDataOnly(false);
$ss=$reader->load($f); $sheet=$ss->getSheet(0); $rows=$sheet->toArray(null,true,true,false);

// detect date row
function toYmd($v){ if($v===null||$v==='')return null; if(is_numeric($v)&&$v>0){ try{ $dt=\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v); return $dt->format('Y-m-d'); }catch(\Throwable $e){} } $ts=strtotime((string)$v); return $ts?date('Y-m-d',$ts):null; }
$dri=null; $dc=[]; $best=0;
for($ri=0;$ri<min(20,count($rows));$ri++){ $dates=[]; foreach(($rows[$ri]??[]) as $ci=>$v){ $y=toYmd($v); if($y)$dates[$ci]=$y; } if(count($dates)>=5){ $idx=array_keys($dates); sort($idx); $run=1;$bestRun=1;$start=$idx[0];$bestStart=$start;$prev=$idx[0]; for($k=1;$k<count($idx);$k++){ if($idx[$k]===$prev+1){$run++;}else{ if($run>$bestRun){$bestRun=$run;$bestStart=$start;} $run=1;$start=$idx[$k]; }$prev=$idx[$k]; } if($run>$bestRun){$bestRun=$run;$bestStart=$start;} if($bestRun>$best){$best=$bestRun;$dri=$ri;$dc=[]; for($c=$bestStart;$c<$bestStart+$bestRun;$c++) $dc[$c]=$dates[$c]; } } }
if($dri===null) die('no date row');

$cf=$sheet->getMergeCells(); $merged=[];
foreach($cf as $rng){ [$s,$e]=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($rng); [$c1,$r1]=$s; [$c2,$r2]=$e;
  for($r=$r1;$r<=$r2;$r++){ for($c=$c1;$c<=$c2;$c++){ $merged["$r:$c"]=true; } } }

$cfir=min(array_keys($dc)); $clast=max(array_keys($dc));
echo "<style>table{border-collapse:collapse;font-family:system-ui;font-size:12px}td,th{border:1px solid #ccc;padding:4px}</style>";
echo "<h4>Date row $dri, cols $cfir..$clast</h4><table><tr><th>r\\c</th>";
for($c=$cfir;$c<=$clast;$c++){ echo "<th>$c<br>".htmlspecialchars($dc[$c])."</th>"; } echo "</tr>";
for($r=$dri+1;$r<min($dri+41,count($rows));$r++){
  echo "<tr><td>$r</td>";
  for($c=$cfir;$c<=$clast;$c++){
    $cell=$sheet->getCellByColumnAndRow($c+1,$r+1);
    $val=trim((string)$cell->getValue());
    $st=$cell->getStyle(); $fill=$st->getFill(); $type=strtolower((string)$fill->getFillType());
    $argb=strtoupper($fill->getStartColor()->getARGB() ?? ''); $rgb=strtoupper($fill->getStartColor()->getRGB() ?? '');
    $b=$st->getBorders();
    $hasBorder=((string)$b->getLeft()->getBorderStyle()!=='none'||(string)$b->getRight()->getBorderStyle()!=='none'||(string)$b->getTop()->getBorderStyle()!=='none'||(string)$b->getBottom()->getBorderStyle()!=='none');
    $bold=$st->getFont()->getBold()?1:0; $fmt=(string)$st->getNumberFormat()->getFormatCode();
    $mh = !empty($merged[($r+1).':'.($c+1)]) ? 'M' : '';
    $on = ($val!=='')||$mh||($type && $type!=='none')||($argb && $argb!=='FFFFFFFF' && $argb!=='00000000')||$hasBorder||$bold||($fmt && strtolower($fmt)!=='general');
    $tag=$on?'ON':'';
    echo "<td>".htmlspecialchars($val)." <small>$tag $mh $type $rgb</small></td>";
  }
  echo "</tr>";
}
echo "</table>";

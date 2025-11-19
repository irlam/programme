<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');

$root = dirname(__DIR__);  // /httpdocs
$libs = $root . '/libs';
$dst  = $libs . '/phpspreadsheet/src/PhpSpreadsheet';
$tmp  = $root . '/tmp';
@mkdir($tmp, 0775, true);
@mkdir($dst, 0775, true);

function row($k,$ok,$d=''){echo "<tr><td>$k</td><td style='color:".($ok?'#86efac':'#fca5a5')."'>".($ok?'OK':'FAIL')."</td><td style='color:#9ca3af'>$d</td></tr>";}

echo '<!doctype html><meta charset="utf-8"><title>Pin PhpSpreadsheet</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:16px}
td{padding:8px;border-bottom:1px solid #1f2937} a{color:#93c5fd}</style>
<h2>PhpSpreadsheet Pin (v1.29.0)</h2><table>';

$url = 'https://github.com/PHPOffice/PhpSpreadsheet/archive/refs/tags/1.29.0.zip';
$zip = $tmp.'/phpss_1.29.0.zip';
$bin = @file_get_contents($url);
row('download v1.29.0', $bin!==false, $url);
if ($bin===false) { exit('</table><p>Download failed.</p>'); }
file_put_contents($zip,$bin);

$z = new ZipArchive();
row('open zip', $z->open($zip)===true);
if ($z->open($zip)!==true) { exit('</table><p>Zip open failed.</p>'); }

$prefix = null;
for ($i=0;$i<$z->numFiles;$i++){
  $st=$z->statIndex($i);
  if (preg_match('~^PhpSpreadsheet-1\.29\.0/src/PhpSpreadsheet/~',$st['name'])) { $prefix='PhpSpreadsheet-1.29.0/src/PhpSpreadsheet/'; break; }
}
row('locate src prefix', (bool)$prefix, $prefix ?? 'not found');

if ($prefix) {
  // wipe dst
  if (is_dir($dst)) {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dst,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); }
  }
  @mkdir($dst,0775,true);

  for ($i=0;$i<$z->numFiles;$i++){
    $st=$z->statIndex($i); $name=$st['name'];
    if (strncmp($name,$prefix,strlen($prefix))!==0) continue;
    $rel=substr($name,strlen($prefix));
    if ($rel===''||substr($rel,-1)==='/'){ @mkdir($dst.'/'.$rel,0775,true); continue; }
    @mkdir(dirname($dst.'/'.$rel),0775,true);
    file_put_contents($dst.'/'.$rel, $z->getFromIndex($i));
  }
  row('install /libs/phpspreadsheet/src/PhpSpreadsheet', true);
}

$z->close();

echo '</table><p>Done. Test: <a href="/api/templates/tasklist.php?debug=1">tasklist.php?debug=1</a> • <a href="/api/templates/lookahead.php?debug=1">lookahead.php?debug=1</a></p>
<p style="color:#9ca3af">If OPcache is enabled, clear it or wait a minute.</p>';

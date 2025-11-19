<?php
declare(strict_types=1);

/*
  MarkBaker PHPMatrix add-on installer
  - Downloads https://github.com/MarkBaker/PHPMatrix (master/main)
  - Installs into /libs/markbaker-matrix/{src,classes/src}
  Run this only if you want full legacy compatibility for older PhpSpreadsheet releases.
*/

$ROOT = dirname(__DIR__);
$LIBS = $ROOT . '/libs';
$TMP  = $ROOT . '/tmp/phpss_matrix';
@mkdir($TMP, 0775, true);

function mkdir_p(string $p): bool { return is_dir($p) || @mkdir($p, 0775, true); }
function rrmdir(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
function rcopy(string $src,string $dst): bool {
  if (is_file($src)) { mkdir_p(dirname($dst)); return copy($src,$dst); }
  if (!is_dir($src)) return false;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
  foreach($it as $item){
    $tgt=$dst.DIRECTORY_SEPARATOR.$it->getSubPathName();
    if($item->isDir()){ if(!is_dir($tgt)&&!@mkdir($tgt,0775,true)) return false; }
    else { mkdir_p(dirname($tgt)); if(!@copy($item->getPathname(),$tgt)) return false; }
  }
  return true;
}
function http_get(string $url,string $to): array {
  if (function_exists('curl_init')) {
    $ch=curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
      CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_CONNECTTIMEOUT=>20,
      CURLOPT_TIMEOUT=>180, CURLOPT_USERAGENT=>'MatrixAddon/1.0'
    ]);
    $data=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if(!$data || $code<200 || $code>=300) return [false,"HTTP $code $err"];
    if(@file_put_contents($to,$data)===false) return [false,"write failed"];
    return [true,"downloaded ".strlen($data)." bytes"];
  }
  $data=@file_get_contents($url);
  if($data===false) return [false,"download failed (allow_url_fopen?)"];
  if(@file_put_contents($to,$data)===false) return [false,"write failed"];
  return [true,"downloaded ".strlen($data)." bytes"];
}
function unzip_to(string $zip,string $dest): array {
  if(!class_exists('ZipArchive')) return [false,'ZipArchive extension missing (enable php-zip)'];
  $za=new ZipArchive();
  if($za->open($zip)!==true) return [false,'cannot open zip'];
  if(!is_dir($dest)) @mkdir($dest,0775,true);
  if(!$za->extractTo($dest)){ $za->close(); return [false,'extract failed']; }
  $za->close(); return [true,'extracted'];
}
function first_dir(string $root): ?string {
  $dirs=glob(rtrim($root,'/').'/*', GLOB_ONLYDIR);
  if(!$dirs) return null;
  usort($dirs, fn($a,$b)=>strlen($a)<=>strlen($b));
  return $dirs[0];
}
function find_src_subdir(string $root, array $candidates): ?string {
  $top = first_dir($root) ?: $root;
  foreach ($candidates as $rel) {
    $try = $top . '/' . $rel;
    if (is_dir($try)) return $try;
  }
  // deep scan fallback
  $needle = basename(reset($candidates)); // 'src' or 'classes/src'
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    if ($f->isDir()) {
      $p = str_replace('\\','/',$f->getPathname());
      if (substr($p, -strlen($needle)) === $needle) return $p;
    }
  }
  return null;
}

/* Ensure target folders */
$targets = [
  "$LIBS/markbaker-matrix/src",
  "$LIBS/markbaker-matrix/classes/src",
];
$rows=[];
foreach($targets as $d){ $rows[]=["mkdir $d", mkdir_p($d)?'OK':'FAIL']; }

/* Download & install */
$log=[];
$urls = [
  'https://github.com/MarkBaker/PHPMatrix/archive/refs/heads/master.zip',
  'https://github.com/MarkBaker/PHPMatrix/archive/refs/heads/main.zip',
];
$zip = $TMP.'/phpmatrix.zip';
$okDl=false;
foreach($urls as $u){
  [$ok,$msg]=http_get($u,$zip);
  if($ok){ $log[]=["download PHPMatrix","OK ($u)"]; $okDl=true; break; }
  else { $log[]=["download PHPMatrix","FAIL ($u) $msg"]; }
}
if($okDl){
  $dest=$TMP.'/phpmatrix';
  rrmdir($dest);
  [$okEx,$msgEx]=unzip_to($zip,$dest);
  $log[]=["extract PHPMatrix",$okEx?'OK':"FAIL ($msgEx)"];
  if($okEx){
    // Try both 'src' and 'classes/src'
    $srcA = find_src_subdir($dest, ['src']);
    $srcB = find_src_subdir($dest, ['classes/src']);
    $okAny=false;
    if($srcA){ rrmdir("$LIBS/markbaker-matrix/src"); $okA=rcopy($srcA,"$LIBS/markbaker-matrix/src"); $log[]=["install PHPMatrix src", $okA?"OK → $LIBS/markbaker-matrix/src":"FAIL (copy)"]; if($okA)$okAny=true; }
    if($srcB){ rrmdir("$LIBS/markbaker-matrix/classes/src"); $okB=rcopy($srcB,"$LIBS/markbaker-matrix/classes/src"); $log[]=["install PHPMatrix classes/src", $okB?"OK → $LIBS/markbaker-matrix/classes/src":"FAIL (copy)"]; if($okB)$okAny=true; }
    if(!$okAny){ $log[]=["install PHPMatrix","FAIL (no src found)"]; }
  }
}

?>
<!doctype html>
<meta charset="utf-8">
<title>PHPMatrix Add-on Installer</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:16px}
 table{border-collapse:collapse} td{padding:6px 10px;border-bottom:1px solid #1f2937}
 .ok{color:#86efac}.fail{color:#fca5a5}.pill{background:#1f2937;border:1px solid #1f2937;padding:6px 10px;border-radius:999px;color:#e5e7eb;text-decoration:none}
</style>
<h2>MarkBaker/PHPMatrix Add-on Installer</h2>
<table>
  <?php foreach($rows as [$a,$s]): ?>
    <tr><td><?=htmlspecialchars($a)?></td><td class="<?=stripos($s,'ok')!==false?'ok':'fail'?>"><?=htmlspecialchars($s)?></td></tr>
  <?php endforeach; ?>
  <?php foreach($log as [$a,$s]): ?>
    <tr><td><?=htmlspecialchars($a)?></td><td class="<?=stripos($s,'ok')!==false?'ok':'fail'?>"><?=htmlspecialchars($s)?></td></tr>
  <?php endforeach; ?>
</table>
<p>
  Next: <a class="pill" href="/admin/phpss_check.php">Open health check</a>
  &nbsp;•&nbsp; <a class="pill" href="/admin/import.html">Importer</a>
</p>
<p style="opacity:.8">Security: delete this installer when finished.</p>

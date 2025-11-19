<?php
declare(strict_types=1);

/*
  composer/pcre add-on installer
  - Downloads https://github.com/composer/pcre (master/main)
  - Installs into /libs/composer-pcre/src
  - Ensures autoloader maps Composer\\Pcre\\ → /libs/composer-pcre/src
*/

$ROOT = dirname(__DIR__); // /httpdocs
$LIBS = $ROOT . '/libs';
$TMP  = $ROOT . '/tmp/phpss_pcre';
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
      CURLOPT_TIMEOUT=>180, CURLOPT_USERAGENT=>'PCREAddon/1.0'
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

$rows=[];
$rows[]=['mkdir /libs/composer-pcre/src', mkdir_p($LIBS.'/composer-pcre/src')?'OK':'FAIL'];

// 1) Download composer/pcre
$urls = [
  'https://github.com/composer/pcre/archive/refs/heads/master.zip',
  'https://github.com/composer/pcre/archive/refs/heads/main.zip',
];
$zip = $TMP.'/composer-pcre.zip';
$okDl=false; $log=[];
foreach($urls as $u){
  [$ok,$msg]=http_get($u,$zip);
  if($ok){ $log[]=["download composer/pcre","OK ($u)"]; $okDl=true; break; }
  else { $log[]=["download composer/pcre","FAIL ($u) $msg"]; }
}
if($okDl){
  $dest=$TMP.'/composer-pcre';
  rrmdir($dest);
  [$okEx,$msgEx]=unzip_to($zip,$dest);
  $log[]=["extract composer/pcre",$okEx?'OK':"FAIL ($msgEx)"];
  if($okEx){
    // Find the top folder then /src inside it
    $top = first_dir($dest) ?: $dest;
    $src = $top.'/src';
    if(!is_dir($src)){
      // fallback: deep scan
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS));
      $found=null;
      foreach($it as $f){ if($f->isDir() && basename($f->getPathname())==='src'){ $found=$f->getPathname(); break; } }
      $src = $found ?: $src;
    }
    if(is_dir($src)){
      rrmdir($LIBS.'/composer-pcre/src');
      $okCp = rcopy($src, $LIBS.'/composer-pcre/src');
      $log[]=['install composer/pcre', $okCp? 'OK → '.$LIBS.'/composer-pcre/src' : 'FAIL (copy)'];
    } else {
      $log[]=['install composer/pcre','FAIL (src not found)'];
    }
  }
}

// 2) Ensure autoloader maps Composer\\Pcre\\
$auto = $LIBS.'/autoload-phpss.php';
$autoOk='SKIP';
if (is_file($auto)) {
  $txt = file_get_contents($auto);
  if (strpos($txt, "'Composer\\\\Pcre\\\\'") === false && strpos($txt, '"Composer\\\\Pcre\\\\"') === false) {
    // insert just after opening $map = [
    $patched = preg_replace(
      '/(\$map\s*=\s*\[)/',
      "$1\n    'Composer\\\\Pcre\\\\'          => [__DIR__ . '/composer-pcre/src/'],",
      $txt,
      1,
      $count
    );
    if ($count > 0 && @file_put_contents($auto, $patched)!==false) {
      $autoOk='OK (mapping added)';
    } else {
      $autoOk='FAIL (could not patch autoloader)';
    }
  } else {
    $autoOk='OK (mapping exists)';
  }
} else {
  $autoOk='FAIL (autoload-phpss.php missing)';
}

?><!doctype html>
<meta charset="utf-8">
<title>composer/pcre Add-on Installer</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:16px}
 table{border-collapse:collapse} td{padding:6px 10px;border-bottom:1px solid #1f2937}
 .ok{color:#86efac}.fail{color:#fca5a5}.pill{background:#1f2937;border:1px solid #1f2937;padding:6px 10px;border-radius:999px;color:#e5e7eb;text-decoration:none}
</style>
<h2>composer/pcre Add-on Installer</h2>
<table>
  <?php foreach($rows as [$a,$s]): ?>
    <tr><td><?=htmlspecialchars($a)?></td><td class="<?=stripos($s,'ok')!==false?'ok':'fail'?>"><?=htmlspecialchars($s)?></td></tr>
  <?php endforeach; ?>
  <?php foreach($log as [$a,$s]): ?>
    <tr><td><?=htmlspecialchars($a)?></td><td class="<?=stripos($s,'ok')!==false?'ok':'fail'?>"><?=htmlspecialchars($s)?></td></tr>
  <?php endforeach; ?>
  <tr><td>autoloader mapping</td><td class="<?=stripos($autoOk,'OK')!==false?'ok':'fail'?>"><?=htmlspecialchars($autoOk)?></td></tr>
</table>
<p>
  Next: <a class="pill" href="/admin/phpss_check.php">Open health check</a>
  &nbsp;•&nbsp; <a class="pill" href="/admin/import.html">Importer</a>
</p>
<p style="opacity:.8">If you still see <em>Class "Composer\Pcre\Preg" not found</em>, clear OPcache or restart PHP-FPM in Plesk, then try again. Delete this installer afterward for security.</p>

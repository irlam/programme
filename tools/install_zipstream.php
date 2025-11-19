<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');

$root = dirname(__DIR__);           // /httpdocs
$libs = $root . '/libs';
$zsrc = $libs . '/zipstream/src';
$tmp  = $root . '/tmp';
@mkdir($tmp, 0775, true);

function row($k,$ok,$d=''){echo "<tr><td>$k</td><td style='color:".($ok?'#86efac':'#fca5a5')."'>".($ok?'OK':'FAIL')."</td><td style='color:#9ca3af'>$d</td></tr>";}

echo '<!doctype html><meta charset="utf-8"><title>ZipStream Installer</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:16px}
td{padding:8px;border-bottom:1px solid #1f2937}</style><h2>ZipStream Installer</h2><table>';

row('mkdir /libs', is_dir($libs) || @mkdir($libs,0775,true));
row('mkdir /libs/zipstream', is_dir(dirname($zsrc)) || @mkdir(dirname($zsrc),0775,true));

$zipUrl='https://github.com/maennchen/ZipStream-PHP/archive/refs/heads/master.zip';
$zipPath=$tmp.'/zipstream.zip';
$bin=@file_get_contents($zipUrl);
$dl=$bin!==false && @file_put_contents($zipPath,$bin)!==false;
row('download ZipStream-PHP',$dl,$zipUrl);

$installed=false;
if($dl){
  $z=new ZipArchive();
  if($z->open($zipPath)===true){
    if(is_dir($zsrc)){
      $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zsrc,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
      foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}
      @rmdir($zsrc);
    }
    @mkdir($zsrc,0775,true);
    $prefix=null;
    for($i=0;$i<$z->numFiles;$i++){ $st=$z->statIndex($i); if(preg_match('~^ZipStream-PHP-[^/]+/src/~',$st['name'])){$prefix=preg_replace('~/src/.*$~','/src/',$st['name']);break;}}
    if($prefix){
      for($i=0;$i<$z->numFiles;$i++){
        $st=$z->statIndex($i); $name=$st['name'];
        if(strncmp($name,$prefix,strlen($prefix))!==0) continue;
        $rel=substr($name,strlen($prefix));
        if($rel===''||substr($rel,-1)==='/'){ @mkdir($zsrc.'/'.$rel,0775,true); continue; }
        @mkdir(dirname($zsrc.'/'.$rel),0775,true);
        @file_put_contents($zsrc.'/'.$rel,$z->getFromIndex($i));
      }
      $installed=true;
      row('install ZipStream src → /libs/zipstream/src',true);
    } else { row('locate src in archive',false,'src/ not found'); }
    $z->close();
  } else { row('extract ZipStream-PHP',false,'zip open failed'); }
} else { row('download ZipStream-PHP',false,'HTTP error'); }

$autoload=$libs.'/autoload-phpss.php';
row('autoload-phpss.php found',is_file($autoload));
if(is_file($autoload)&&$installed){
  $code=file_get_contents($autoload);
  if(strpos($code,"'ZipStream\\\\'")===false){
    $patched=preg_replace('/\\$map\\s*=\\s*\\[(.*?)\\];/s',function($m){
      $chunk=rtrim($m[1]);
      if(strpos($chunk,"'ZipStream\\\\'")!==false) return $m[0];
      return "\$map = [{$chunk}\n  'ZipStream\\\\' => __DIR__ . '/zipstream/src/',\n];";
    },$code,1);
    if($patched && $patched!==$code){ file_put_contents($autoload,$patched); row('autoloader map ZipStream\\',true); }
    else { row('autoloader map ZipStream\\',false,'could not patch (already?)'); }
  } else { row('autoloader map ZipStream\\',true,'already present'); }
}

echo '</table><p>Try: <a href="/api/templates/tasklist.php?debug=1">tasklist.php?debug=1</a> • <a href="/api/templates/lookahead.php?debug=1">lookahead.php?debug=1</a></p>
<p style="color:#9ca3af">If still failing, clear PHP OPcache in Plesk or wait 1–2 minutes.</p>';

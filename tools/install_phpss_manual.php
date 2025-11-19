<?php
declare(strict_types=1);
$root = dirname(__DIR__); // /httpdocs

function mk($p){ if(!is_dir($p)) @mkdir($p,0775,true); return is_dir($p); }
function put($p,$c){ mk(dirname($p)); return file_put_contents($p,$c)!==false; }

$dirs = [
  '/libs',
  '/libs/phpspreadsheet/src/PhpSpreadsheet',
  '/libs/psr-simple-cache/src',
  '/libs/markbaker-complex/src',
  '/libs/markbaker-complex/classes/src',
  '/libs/markbaker-matrix/src',
  '/libs/markbaker-matrix/classes/src',
  '/libs/phpoffice-math/src',
];

$auto = <<<'PHP'
<?php
spl_autoload_register(function($class){
  $map = [
    'PhpOffice\\PhpSpreadsheet\\' => [__DIR__.'/phpspreadsheet/src/PhpSpreadsheet/'],
    'PhpOffice\\Math\\'           => [__DIR__.'/phpoffice-math/src/'],
    'Psr\\SimpleCache\\'          => [__DIR__.'/psr-simple-cache/src/'],
    'Complex\\'                   => [__DIR__.'/markbaker-complex/src/', __DIR__.'/markbaker-complex/classes/src/'],
    'Matrix\\'                    => [__DIR__.'/markbaker-matrix/src/', __DIR__.'/markbaker-matrix/classes/src/'],
  ];
  foreach($map as $prefix=>$bases){
    $len=strlen($prefix);
    if(strncmp($class,$prefix,$len)!==0) continue;
    $rel=str_replace('\\','/',substr($class,$len)).'.php';
    foreach($bases as $b){ $f=$b.$rel; if(is_file($f)){ require $f; return true; } }
  }
  return false;
});
PHP;

$check = <<<'PHP'
<?php
declare(strict_types=1); require __DIR__.'/../api/_bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
echo "PhpSpreadsheet: ".(class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)?"OK":"MISSING")."\n";
foreach(['zip','xml','mbstring'] as $e){
  echo "ext-$e: ".(extension_loaded($e)?"OK":"MISSING")."\n";
}
PHP;

$rows=[];
foreach($dirs as $d){ $ok=mk($root.$d); $rows[]=["mkdir $d",$ok?'OK':'FAIL']; }
$rows[]=['write /libs/autoload-phpss.php', put($root.'/libs/autoload-phpss.php',$auto)?'OK':'FAIL'];
$rows[]=['write /admin/phpss_check.php', put($root.'/admin/phpss_check.php',$check)?'OK':'FAIL'];

?><!doctype html><meta charset="utf-8">
<title>PhpSpreadsheet Manual • Installer</title>
<style>body{font-family:system-ui;background:#0f172a;color:#e5e7eb;padding:16px}table{border-collapse:collapse}td{padding:6px 10px;border-bottom:1px solid #1f2937}.ok{color:#86efac}.fail{color:#f87171}</style>
<h1>PhpSpreadsheet Manual • Installer</h1>
<p>Root: <?=htmlspecialchars($root)?></p>
<table><?php foreach($rows as [$a,$s]): ?><tr><td><?=htmlspecialchars($a)?></td><td class="<?=strtolower($s)==='ok'?'ok':'fail'?>"><?=htmlspecialchars($s)?></td></tr><?php endforeach; ?></table>
<p>Next:</p>
<ol>
<li>Upload the <strong>src/</strong> folders into <code>/httpdocs/libs/…</code> as shown above.</li>
<li>Ensure <code>zip, xml, mbstring</code> PHP extensions are enabled in Plesk.</li>
<li>Open <a href="/admin/phpss_check.php">/admin/phpss_check.php</a> — it should say <em>OK</em>.</li>
<li>Delete this installer for security.</li>
</ol>

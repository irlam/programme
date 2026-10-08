<?php
declare(strict_types=1);
require __DIR__.'/../app/Lib/SuiteBinding.php';
use App\Lib\SuiteBinding;
$temp=sys_get_temp_dir().'/suite-binding-'.bin2hex(random_bytes(6));
mkdir($temp.'/app/config',0700,true);
$file=$temp.'/app/config/runtime.suite.private.php';
$binding=['key'=>str_repeat('b',64)];
$assert=static function(bool $ok):void {if(!$ok)throw new RuntimeException('Binding test failed.');};
$reject=static function()use($temp,$assert):void {try {SuiteBinding::load($temp);}catch(RuntimeException $e){return;}$assert(false);};
$server=null;
$prior=getenv('PROGRAMME_SUITE_CONFIG_FILE');
try {
    putenv('PROGRAMME_SUITE_CONFIG_FILE');$reject();
    file_put_contents($file,'<?php return '.var_export($binding,true).';');chmod($file,0600);$reject();
    $bytes="<?php\ndeclare(strict_types=1);\n".SuiteBinding::guard()."\nreturn ".var_export($binding,true).";\n";
    file_put_contents($file,$bytes);
    $assert(SuiteBinding::load($temp)===$binding);
    chmod($file,0644);$reject();chmod($file,0600);
    putenv('PROGRAMME_SUITE_CONFIG_FILE='.$temp.'/app/config/other.php');
    file_put_contents($temp.'/app/config/other.php',$bytes);chmod($temp.'/app/config/other.php',0600);$reject();
    putenv('PROGRAMME_SUITE_CONFIG_FILE='.$temp.'/missing.php');$reject();
    putenv('PROGRAMME_SUITE_CONFIG_FILE');
    $outside=$temp.'-external.php';file_put_contents($outside,'<?php echo "BUFFERED_MARKER"; return '.var_export($binding,true).';');chmod($outside,0600);
    putenv('PROGRAMME_SUITE_CONFIG_FILE='.$outside);ob_start();$assert(SuiteBinding::load($temp)===$binding);$assert(ob_get_clean()==='');
    putenv('PROGRAMME_SUITE_CONFIG_FILE');
    rename($file,$temp.'/saved.php');symlink($temp.'/saved.php',$file);$reject();unlink($file);rename($temp.'/saved.php',$file);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([getenv('PHP_BINARY')?:PHP_BINARY,'-S',$address,'-t',$temp],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    $context=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>1]]);
    $body=false;
    for($i=0;$i<20;$i++){$body=@file_get_contents('http://'.$address.'/app/config/runtime.suite.private.php',false,$context);if($body!==false)break;usleep(100000);}
    $assert($body==='' && str_contains($http_response_header[0]??'','404'));
    $assert(!str_contains($body,(string)$binding['key']));
    echo "PASS: guarded in-tree config, external compatibility, denied arbitrary paths/symlinks/permissions, buffered output and direct HTTP 404.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if($prior===false)putenv('PROGRAMME_SUITE_CONFIG_FILE');else putenv('PROGRAMME_SUITE_CONFIG_FILE='.$prior);
    foreach(glob($temp.'/app/config/*') as $p)unlink($p);
    if(isset($outside)&&file_exists($outside))unlink($outside);
    rmdir($temp.'/app/config');rmdir($temp.'/app');rmdir($temp);
}

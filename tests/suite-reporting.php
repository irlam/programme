<?php
declare(strict_types=1);
$directory=sys_get_temp_dir().'/programme-reporting-'.bin2hex(random_bytes(6)); mkdir($directory,0700);
$database=$directory.'/test.sqlite'; $config=$directory.'/config.php';
$key=str_repeat('t',40); $pdo=new PDO('sqlite:'.$database);
$pdo->exec('CREATE TABLE projects(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE tasks(id INTEGER PRIMARY KEY,project_id INTEGER,percent_complete INTEGER,finish_date TEXT)');
$pdo->exec("INSERT INTO projects VALUES(1,'Alpha'),(2,'Beta'); INSERT INTO tasks VALUES(1,1,0,'2000-01-01'),(2,1,100,'2000-01-01'),(3,2,0,'2099-01-01')");
file_put_contents($config,'<?php return '.var_export(['db'=>['dsn'=>'sqlite:'.$database,'user'=>'','pass'=>'','options'=>[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]],'suite_reporting_key'=>$key],true).';');
$root=dirname(__DIR__); $checks=0;
function call_report(string $file, array $query, string $key, string $method='GET'): array {
    global $config,$root;
    $code='putenv('.var_export('PROGRAMME_CONFIG_FILE='.$config,true).'); $_SERVER='.var_export(['REQUEST_METHOD'=>$method,'HTTP_X_CONSTRUCTION_SUITE_KEY'=>$key],true).'; $_GET='.var_export($query,true).'; register_shutdown_function(function(){echo "\nSTATUS:".(http_response_code()?:200);}); require '.var_export($root.'/api/'.$file,true).';';
    $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$error!=='')throw new RuntimeException('Report failed');
    [$body,$status]=explode("\nSTATUS:",$output);return [(int)$status,json_decode($body,true,512,JSON_THROW_ON_ERROR)];
}
function verify(bool $condition): void { global $checks; if(!$condition)throw new RuntimeException('Assertion failed');$checks++; }
try {
    [$status,$body]=call_report('suite-summary.php',['project'=>'1'],'wrong');verify($status===401&&!isset($body['metrics']));
    foreach([[],['project'=>'__all__'],['project'=>['1']],['project'=>'1 OR 1=1']] as $query){[$status]=call_report('suite-summary.php',$query,$key);verify($status===400);}
    [$status]=call_report('suite-summary.php',['project'=>'999'],$key);verify($status===404);
    [$status,$body]=call_report('suite-summary.php',['project'=>'1'],$key);verify($status===200&&$body['metrics']===['total'=>2,'outstanding'=>1,'completed'=>1,'overdue'=>1]);
    [$status,$body]=call_report('suite-summary.php',['project'=>'2'],$key);verify($status===200&&$body['metrics']['total']===1&&$body['metrics']['overdue']===0);
    [$status]=call_report('suite-summary.php',['project'=>'1'],$key,'POST');verify($status===405);
    [$status,$body]=call_report('suite-references.php',[],$key);verify($status===200&&array_column($body['items'],'value')===['1','2']);
    echo "PASS: $checks Programme reporting authentication and project boundary checks.\n";
} finally { unset($pdo);unlink($config);unlink($database);rmdir($directory); }

<?php
declare(strict_types=1);
require __DIR__.'/../app/Lib/SuiteGateway.php';
require __DIR__.'/../app/Lib/SuitePreflight.php';
use App\Lib\SuitePreflight;
$check=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);};
$binding=['instance_id'=>11,'organization_id'=>21,'project_id'=>31,'local_project_id'=>1,'suite_origin'=>'https://suite.defecttracker.uk','origin'=>'https://alpha.programme.defecttracker.uk','key'=>str_repeat('b',64)];
$temp=sys_get_temp_dir().'/programme-preflight-'.bin2hex(random_bytes(6));mkdir($temp,0700);
try {
    $database=$temp.'/fixture.sqlite';$pdo=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $check(SuitePreflight::database($pdo,$binding)['application_tables']===false,'Missing schema must fail');
    foreach (['apartments','audit_log','baselines','calendars','calendar_holidays','comments','contractors','dependencies','imports','projects','tasks','templates','template_dependencies','template_tasks','users'] as $table) {$pdo->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY)');}
    $pdo->exec('CREATE TABLE suite_instance_binding (id INTEGER PRIMARY KEY,instance_id INTEGER,organization_id INTEGER,project_id INTEGER,local_project_id INTEGER)');
    $pdo->exec('CREATE TABLE suite_user_map (suite_user_id INTEGER,local_user_id INTEGER)');
    $pdo->exec('INSERT INTO projects VALUES(1)');$pdo->exec('INSERT INTO suite_instance_binding VALUES(1,11,21,31,1)');
    $before=hash_file('sha256',$database);$valid=SuitePreflight::database($pdo,$binding);
    $check(!in_array(false,$valid,true),'Valid local fixture checks pass');$check(hash_file('sha256',$database)===$before,'Inspection changes no database bytes');
    $foreign=$binding;$foreign['organization_id']=99;$check(SuitePreflight::database($pdo,$foreign)['database_binding']===false,'Foreign company binding fails');
    $pdo->exec('INSERT INTO projects VALUES(2)');$check(SuitePreflight::database($pdo,$binding)['single_bound_project']===false,'Extra local project fails');$pdo->exec('DELETE FROM projects WHERE id=2');
    $pdo->exec('INSERT INTO suite_user_map VALUES(1,999)');$check(SuitePreflight::database($pdo,$binding)['mapping_integrity']===false,'Dangling identity fails');$pdo->exec('DELETE FROM suite_user_map');
    $foreign=$binding;$foreign['local_project_id']=2;$check(SuitePreflight::database($pdo,$foreign)['binding_configuration']===false,'Unsupported report binding fails');
    $pdo->exec('DROP TABLE comments');$check(SuitePreflight::database($pdo,$binding)['application_tables']===false,'Missing application table fails');$pdo->exec('CREATE TABLE comments(id INTEGER PRIMARY KEY)');
    $instance=$temp.'/binding.php';$config=$temp.'/database.php';
    file_put_contents($instance,"<?php echo 'SENSITIVE_FIXTURE_OUTPUT'; return ".var_export($binding,true).';');chmod($instance,0600);
    file_put_contents($config,'<?php return '.var_export(['db'=>['dsn'=>'sqlite:'.$database,'user'=>'','pass'=>'SENSITIVE_FIXTURE_PASSWORD','options'=>[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]]],true).';');chmod($config,0600);
    $env=getenv();$env['PROGRAMME_SUITE_CONFIG_FILE']=$instance;$env['PROGRAMME_CONFIG_FILE']=$config;
    $run=static function(array $env)use($check):array {
        $p=proc_open([getenv('PHP_BINARY')?:PHP_BINARY,__DIR__.'/../bin/suite-preflight.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        $check(!str_contains($out.$err,'SENSITIVE_FIXTURE')&&!str_contains($out.$err,str_repeat('b',64))&&!str_contains($out.$err,'sqlite:'),'Output redacts secrets and buffered configuration output');
        return [$code,json_decode($out,true,16,JSON_THROW_ON_ERROR)];
    };
    [$code,$result]=$run($env);$check($code===0 && $result['local_checks_passed'] && $result['tenant_ready']===false,'CLI local pass never marks readiness');
    chmod($instance,0644);[$code,$result]=$run($env);$check($code===1 && !$result['checks']['private_binding_file'],'Broad config permission fails');chmod($instance,0600);
    $env['PROGRAMME_CONFIG_FILE']=$temp.'/missing.php';$env['PROGRAMME_DB_DSN']='invalid:SENSITIVE_FIXTURE_DSN';$env['PROGRAMME_DB_USER']='fixture';$env['PROGRAMME_DB_PASSWORD']='SENSITIVE_FIXTURE_PASSWORD';
    [$code,$result]=$run($env);$check($code===1 && !$result['checks']['database_connection'],'Connection failure is redacted');
    echo "PASS: read-only preflight, schema/binding/project/mapping failures, CLI permissions, secret redaction and disabled readiness.\n";
} finally {foreach(glob($temp.'/*') as $file)unlink($file);rmdir($temp);}

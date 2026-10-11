<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/suite-prepend.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
try {
 $b=$GLOBALS['programme_summary_binding']??null;
 if(!is_array($b)||($_SERVER['REQUEST_METHOD']??'')!=='GET'||!hash_equals($b['key'],(string)($_SERVER['HTTP_X_CONSTRUCTION_SUITE_KEY']??''))){http_response_code(401);echo '{"ok":false}';exit;}
 if(isset($_GET['project'])&&(string)$_GET['project']!==(string)$b['local_project_id']){http_response_code(403);echo '{"ok":false}';exit;}
 $db=App\Config\DB::pdo();
 $q=$db->prepare('SELECT COUNT(*) total,COALESCE(SUM(percent_complete<100),0) outstanding,COALESCE(SUM(percent_complete>=100),0) completed FROM tasks WHERE project_id=?');
 $q->execute([$b['local_project_id']]);
 echo json_encode(['ok'=>true,'module'=>'programme','scope'=>['project'=>$b['local_project_id']],'metrics'=>array_map('intval',$q->fetch(PDO::FETCH_ASSOC))],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(503);echo '{"ok":false,"error":"summary_unavailable"}';}

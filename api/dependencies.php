<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;
use App\Lib\Scheduler;
use App\Lib\Programmes;

header('Content-Type: application/json');
$pdo = DB::pdo();
$method = $_SERVER['REQUEST_METHOD'];

if (in_array($method, ['POST','DELETE'], true)) { require_role(['admin','planner']); csrf_check(); }

function ok($d){ echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function bad($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function body(){ $r=file_get_contents('php://input'); return $r? (json_decode($r,true) ?: []) : []; }

if ($method==='GET') {
  $task = (int)($_GET['task_id'] ?? 0);
  if (!$task) bad('task_id required');
  $st = $pdo->prepare("SELECT id, task_id, predecessor_id, type, lag_days FROM dependencies WHERE task_id=? ORDER BY id");
  $st->execute([$task]);
  ok($st->fetchAll());
}
if ($method==='POST') {
  $b=body();foreach(['task_id','predecessor_id','type']as$field)if(!isset($b[$field]))bad('Missing '.$field);
  $q=$pdo->prepare('SELECT * FROM tasks WHERE id=?');$q->execute([(int)$b['task_id']]);$task=$q->fetch();$q->execute([(int)$b['predecessor_id']]);$previous=$q->fetch();
  if(!$task||!$previous)bad('Activity not found',404);
  $programme=Programmes::forTask($pdo,$task);
  if((int)$task['project_id']!==(int)$previous['project_id']||$programme!==Programmes::forTask($pdo,$previous))bad('Dependencies cannot link different programmes or projects');
  try{$pdo->beginTransaction();$q=$pdo->prepare('INSERT INTO dependencies(task_id,predecessor_id,type,lag_days) VALUES(?,?,?,?)');$q->execute([(int)$b['task_id'],(int)$b['predecessor_id'],$b['type']==='SS'?'SS':'FS',(int)($b['lag_days']??0)]);$id=$pdo->lastInsertId();(new Scheduler((int)$task['project_id'],$programme))->recalc();$pdo->commit();}
  catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();bad('Dependency could not be saved. No changes were applied.');}
  ok(['id'=>$id]);
}
if ($method==='DELETE') {
  $id=(int)($_GET['id']??0);if(!$id)bad('id required');
  $q=$pdo->prepare('SELECT t.* FROM dependencies d JOIN tasks t ON t.id=d.task_id WHERE d.id=?');$q->execute([$id]);$task=$q->fetch();if(!$task)bad('Dependency not found',404);
  try{$pdo->beginTransaction();$pdo->prepare('DELETE FROM dependencies WHERE id=?')->execute([$id]);(new Scheduler((int)$task['project_id'],Programmes::forTask($pdo,$task)))->recalc();$pdo->commit();}
  catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();bad('Dependency could not be removed. No changes were applied.');}
  ok(['deleted'=>true]);
}
bad('Unsupported method',405);


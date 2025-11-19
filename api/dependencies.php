<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;
use App\Lib\Scheduler;

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
  $b = body();
  foreach (['task_id','predecessor_id','type'] as $r) if (!isset($b[$r])) bad("Missing $r");
  $type = ($b['type']==='SS') ? 'SS' : 'FS';
  $lag = (int)($b['lag_days'] ?? 0);
  $pdo->prepare("INSERT INTO dependencies (task_id, predecessor_id, type, lag_days) VALUES (?,?,?,?)")
      ->execute([(int)$b['task_id'], (int)$b['predecessor_id'], $type, $lag]);
  // recalc by project
  $p = $pdo->query("SELECT project_id FROM tasks WHERE id=".(int)$b['task_id'])->fetchColumn();
  if ($p) (new Scheduler((int)$p))->recalc();
  ok(['id'=>$pdo->lastInsertId()]);
}
if ($method==='DELETE') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) bad('id required');
  $tid = $pdo->query("SELECT task_id FROM dependencies WHERE id=$id")->fetchColumn();
  $pdo->prepare("DELETE FROM dependencies WHERE id=?")->execute([$id]);
  $p = $tid ? $pdo->query("SELECT project_id FROM tasks WHERE id=".(int)$tid)->fetchColumn() : null;
  if ($p) (new Scheduler((int)$p))->recalc();
  ok(['deleted'=>true]);
}
bad('Unsupported method',405);

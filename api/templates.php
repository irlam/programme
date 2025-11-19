<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;
use App\Lib\Scheduler;

header('Content-Type: application/json');
$pdo = DB::pdo();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

function ok($d){ echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function bad($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

if ($action === 'list' && $method==='GET') {
  $st = $pdo->query("SELECT * FROM templates ORDER BY id DESC");
  ok($st->fetchAll());
}

if ($action === 'create' && $method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  if (empty($b['name'])) bad('name required');
  $pdo->prepare("INSERT INTO templates (name, description) VALUES (?,?)")->execute([$b['name'],$b['description'] ?? null]);
  ok(['id'=>$pdo->lastInsertId()]);
}

if ($action === 'from_apartment' && $method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  $apt = (int)($b['apartment_id'] ?? 0);
  $name = $b['name'] ?? ('Template from apt '.$apt);
  if (!$apt) bad('apartment_id required');

  $pdo->prepare("INSERT INTO templates (name) VALUES (?)")->execute([$name]);
  $tid = (int)$pdo->lastInsertId();

  $tasks = $pdo->prepare("SELECT * FROM tasks WHERE apartment_id=? ORDER BY start_date IS NULL, start_date, id");
  $tasks->execute([$apt]); $tasks = $tasks->fetchAll();
  if (!$tasks) ok(['id'=>$tid,'note'=>'no tasks in apartment']);

  $codeMap=[]; $i=1;
  foreach ($tasks as $t) {
    $code = sprintf('T%02d',$i++);
    $pdo->prepare("INSERT INTO template_tasks (template_id, code, name, contractor_id, operatives, duration_days, zone, is_milestone)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$tid,$code,$t['name'],$t['contractor_id'],$t['operatives'],$t['duration_days'],$t['zone'],$t['is_milestone']]);
    $codeMap[$t['id']] = $code;
  }

  $ids = implode(',', array_map('intval', array_keys($codeMap)));
  if ($ids) {
    $dep = $pdo->query("SELECT task_id, predecessor_id, type, lag_days FROM dependencies WHERE task_id IN ($ids)");
    while ($d = $dep->fetch()) {
      $pdo->prepare("INSERT INTO template_dependencies (template_id, task_code, predecessor_code, type, lag_days)
                     VALUES (?,?,?,?,?)")
          ->execute([$tid, $codeMap[$d['task_id']], $codeMap[$d['predecessor_id']] ?? null, $d['type'], (int)$d['lag_days']]);
    }
  }
  ok(['id'=>$tid,'tasks'=>count($tasks)]);
}

if ($action === 'detail' && $method==='GET') {
  $tid = (int)($_GET['id'] ?? 0); if (!$tid) bad('id required');
  $t = $pdo->prepare("SELECT * FROM templates WHERE id=?"); $t->execute([$tid]); $tpl=$t->fetch();
  if (!$tpl) bad('not found',404);
  $tt = $pdo->prepare("SELECT * FROM template_tasks WHERE template_id=? ORDER BY id"); $tt->execute([$tid]); $tasks=$tt->fetchAll();
  $td = $pdo->prepare("SELECT * FROM template_dependencies WHERE template_id=? ORDER BY id"); $td->execute([$tid]); $deps=$td->fetchAll();
  ok(['template'=>$tpl,'tasks'=>$tasks,'dependencies'=>$deps]);
}

if ($action === 'clone' && $method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  $tid = (int)($b['template_id'] ?? 0);
  $project = (int)($b['project_id'] ?? 1);
  $apartment_ids = array_filter(array_map('intval', $b['apartment_ids'] ?? []));
  if (!$tid || !$apartment_ids) bad('template_id and apartment_ids required');

  $tt = $pdo->prepare("SELECT * FROM template_tasks WHERE template_id=? ORDER BY id"); $tt->execute([$tid]); $tasks=$tt->fetchAll();
  $td = $pdo->prepare("SELECT * FROM template_dependencies WHERE template_id=?"); $td->execute([$tid]); $deps=$td->fetchAll();

  $createdTotal=0;
  foreach ($apartment_ids as $apt) {
    $newIdByCode=[];
    foreach ($tasks as $t) {
      $pdo->prepare("INSERT INTO tasks (project_id, apartment_id, name, contractor_id, operatives, duration_days, zone, is_milestone)
                     VALUES (?,?,?,?,?,?,?,?)")
          ->execute([$project,$apt,$t['name'],$t['contractor_id'],$t['operatives'],$t['duration_days'],$t['zone'],$t['is_milestone']]);
      $newIdByCode[$t['code']] = (int)$pdo->lastInsertId();
      $createdTotal++;
    }
    foreach ($deps as $d) {
      $to   = $newIdByCode[$d['task_code']] ?? null;
      $pred = $newIdByCode[$d['predecessor_code']] ?? null;
      if ($to && $pred) {
        $pdo->prepare("INSERT INTO dependencies (task_id, predecessor_id, type, lag_days) VALUES (?,?,?,?)")
            ->execute([$to,$pred,$d['type'],(int)$d['lag_days']]);
      }
    }
    (new Scheduler($project))->recalc();
  }
  ok(['created'=>$createdTotal]);
}

bad('unknown_action',400);
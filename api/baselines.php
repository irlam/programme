<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;
use App\Lib\Programmes;

header('Content-Type: application/json');
$pdo = DB::pdo();
$method = $_SERVER['REQUEST_METHOD'];

function ok($d){ echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function bad($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

if ($method==='GET') {
  $p=(int)($_GET['project']??1);
  Programmes::request($pdo,$p);
  $programme=(int)($_GET['programme']??0);
  $st=$pdo->prepare("SELECT * FROM baselines WHERE project_id=? ORDER BY id DESC");
  $st->execute([$p]);$rows=[];foreach($st->fetchAll()as$row){$owner=0;if(preg_match('/^\[programme:(\d+)\] /',$row['label'],$match)){$owner=(int)$match[1];$row['label']=substr($row['label'],strlen($match[0]));}if($owner===$programme)$rows[]=$row;}ok($rows);
}

if ($method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  $p = (int)($b['project_id'] ?? 1);
  $label = $b['label'] ?? ('Baseline '.date('Y-m-d H:i'));
  try{$programme=Programmes::selected($pdo,$p,$b['programme']??'0');}catch(RuntimeException $e){bad($e->getMessage(),404);}
  $scope=Programmes::condition($pdo,$p,$programme['id'],'tasks');
  if($programme['id'])$label='[programme:'.$programme['id'].'] '.$label;
  $label=substr($label,0,120);
  try{$pdo->beginTransaction();
  $pdo->prepare("UPDATE tasks SET baseline_start = start_date, baseline_finish = finish_date WHERE project_id=? AND $scope")->execute([$p]);
  $pdo->prepare("INSERT INTO baselines (project_id, label) VALUES (?,?)")->execute([$p,$label]);
  $pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();bad('Could not capture this baseline. No changes were applied.');}
  ok(['saved'=>true, 'label'=>$label]);
}

bad('unsupported',405);

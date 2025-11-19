<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');
$pdo = DB::pdo();
$method = $_SERVER['REQUEST_METHOD'];

function ok($d){ echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function bad($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

if ($method==='GET') {
  $p=(int)($_GET['project']??1);
  $st=$pdo->prepare("SELECT * FROM baselines WHERE project_id=? ORDER BY id DESC");
  $st->execute([$p]); ok($st->fetchAll());
}

if ($method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  $p = (int)($b['project_id'] ?? 1);
  $label = $b['label'] ?? ('Baseline '.date('Y-m-d H:i'));
  $pdo->prepare("UPDATE tasks SET baseline_start = start_date, baseline_finish = finish_date WHERE project_id=?")->execute([$p]);
  $pdo->prepare("INSERT INTO baselines (project_id, label) VALUES (?,?)")->execute([$p,$label]);
  ok(['saved'=>true, 'label'=>$label]);
}

bad('unsupported',405);
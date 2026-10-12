<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
use App\Lib\Scheduler;
use App\Lib\Programmes;
header('Content-Type: application/json');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo '{"ok":false}';exit;}
require_role(['admin','planner']);csrf_check();
$projectId = (int)($_GET['project'] ?? 1);
Programmes::request(\App\Config\DB::pdo(),$projectId);
try {
  (new Scheduler($projectId,(int)($_GET['programme']??0)))->recalc();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}


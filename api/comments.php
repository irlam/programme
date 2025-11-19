<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');
$pdo = DB::pdo();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') { require_role(['admin','planner','commenter']); csrf_check(); }

function ok($d){ echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function bad($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function body(){ $r=file_get_contents('php://input'); return $r? (json_decode($r,true) ?: []) : []; }

if ($method==='GET') {
  $task = (int)($_GET['task_id'] ?? 0);
  if (!$task) bad('task_id required');
  $st = $pdo->prepare("SELECT id, user_id, message, attachments_json, parent_id, created_at FROM comments WHERE task_id=? ORDER BY created_at ASC, id ASC");
  $st->execute([$task]);
  ok($st->fetchAll());
}
if ($method==='POST') {
  $b = body();
  if (empty($b['task_id']) || empty($b['message'])) bad('task_id and message required');
  $pdo->prepare("INSERT INTO comments (task_id, user_id, message, parent_id) VALUES (?,?,?,?)")
      ->execute([(int)$b['task_id'], $b['user_id'] ?? null, trim((string)$b['message']), $b['parent_id'] ?? null]);
  ok(['id'=>$pdo->lastInsertId()]);
}
bad('Unsupported method',405);

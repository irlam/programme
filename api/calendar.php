<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');
$pdo = DB::pdo();
$method = $_SERVER['REQUEST_METHOD'];

function ok($d){ echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function bad($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

$project = (int)($_GET['project'] ?? 1);
$calId = (int)($pdo->query("SELECT calendar_id FROM projects WHERE id=$project")->fetchColumn() ?: 0);
if (!$calId) bad('Project/calendar not found',404);

if ($method==='GET') {
  $st = $pdo->prepare("SELECT id, date, is_working, name, source FROM calendar_holidays WHERE calendar_id=? ORDER BY date");
  $st->execute([$calId]);
  ok(['calendar_id'=>$calId, 'items'=>$st->fetchAll()]);
}
if ($method==='POST') {
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  if (empty($b['date'])) bad('date required (YYYY-MM-DD)');
  $date = $b['date'];
  $isWorking = !empty($b['is_working']) ? 1 : 0;
  $name = $b['name'] ?? null;
  // upsert
  $stmt = $pdo->prepare("INSERT INTO calendar_holidays (calendar_id, date, is_working, name, source)
                         VALUES (?,?,?,?, 'manual')
                         ON DUPLICATE KEY UPDATE is_working=VALUES(is_working), name=VALUES(name)");
  $stmt->execute([$calId, $date, $isWorking, $name]);
  ok(['saved'=>true]);
}
if ($method==='DELETE') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) bad('id required');
  $pdo->prepare("DELETE FROM calendar_holidays WHERE id=? AND calendar_id=?")->execute([$id,$calId]);
  ok(['deleted'=>true]);
}
bad('Unsupported method',405);

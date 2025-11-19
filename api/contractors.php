<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');
$pdo = DB::pdo();

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
  $st = $pdo->query("SELECT id, name FROM contractors ORDER BY name");
  $items = $st->fetchAll();
  echo json_encode(['ok'=>true, 'items'=>$items]);
  exit;
}

http_response_code(404);
echo json_encode(['ok'=>false, 'error'=>'unknown_action']);
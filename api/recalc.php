<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
use App\Lib\Scheduler;
header('Content-Type: application/json');
$projectId = (int)($_GET['project'] ?? 1);
try {
  (new Scheduler($projectId))->recalc();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

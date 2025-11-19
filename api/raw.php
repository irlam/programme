<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
use App\Config\DB;

header('Content-Type: application/json');
$me = current_user();
if (!$me || $me['role']!=='admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

$sql = $_GET['sql'] ?? '';
if (!preg_match('~^\s*SELECT\s~i', $sql)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'select_only']); exit; }

$pdo = DB::pdo();
$st = $pdo->query($sql);
echo json_encode(['ok'=>true,'rows'=>$st->fetchAll()]);
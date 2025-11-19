<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');
$pdo = DB::pdo();
$action = $_GET['action'] ?? 'whoami';

if ($action === 'whoami') {
  echo json_encode(['ok'=>true,'user'=>current_user(),'csrf'=>$_SESSION['csrf']]); exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
  $body = json_decode(file_get_contents('php://input'), true) ?: [];
  $email = trim((string)($body['email'] ?? ''));
  $pass  = (string)($body['password'] ?? '');
  $st = $pdo->prepare("SELECT id,name,email,role,password_hash FROM users WHERE email=?");
  $st->execute([$email]);
  $u = $st->fetch();
  if ($u && password_verify($pass, $u['password_hash'])) {
    $_SESSION['user'] = ['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']];
    echo json_encode(['ok'=>true,'user'=>current_user(),'csrf'=>$_SESSION['csrf']]); exit;
  }
  http_response_code(401); echo json_encode(['ok'=>false,'error'=>'invalid_login']); exit;
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $_SESSION = [];
  @session_destroy();
  echo json_encode(['ok'=>true]); exit;
}

if ($action === 'create_user' && $_SERVER['REQUEST_METHOD']==='POST') {
  $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  if ($count > 0) { require_role('admin'); csrf_check(); }
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  foreach (['name','email','password','role'] as $f) if (empty($b[$f])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>"missing_$f"]); exit; }
  $hash = password_hash((string)$b['password'], PASSWORD_DEFAULT);
  $st = $pdo->prepare("INSERT INTO users (name,email,role,password_hash) VALUES (?,?,?,?)");
  $st->execute([$b['name'],$b['email'],$b['role'],$hash]);
  echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
}

http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown_action']);
<?php
declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';
use App\Config\DB;

function json_out(int $status, array $data): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}
function require_csrf(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $hdr = $_SERVER['HTTP_X_CSRF'] ?? '';
  $ok  = isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $hdr);
  if (!$ok) json_out(403, ['ok'=>false, 'error'=>'forbidden']);
}
function wd(string $a,string $b): int {
  $A=new DateTimeImmutable($a); $B=new DateTimeImmutable($b);
  if ($B<$A) [$A,$B]=[$B,$A]; $d=0; for($cur=$A;$cur<=$B;$cur=$cur->add(new DateInterval('P1D'))){ if((int)$cur->format('N')<=5)$d++; }
  return max(1,$d);
}
function hex(?string $h): ?string { if(!$h) return null; $h=ltrim($h,'#'); if(strlen($h)===3) $h=$h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; return strlen($h)===6 ? '#'.strtoupper($h):null; }

function ensure_contractor(PDO $pdo, ?string $name, ?string $hex, bool $useColour): ?int {
  $name = trim((string)$name);
  if ($name==='') return null;
  $hex  = $useColour ? hex($hex) : null;

  $q=$pdo->prepare("SELECT id, colour FROM contractors WHERE name=?"); $q->execute([$name]);
  if ($row=$q->fetch()) {
    if ($hex && ($row['colour']==='#4B5563' || $row['colour']===null)) {
      $u=$pdo->prepare("UPDATE contractors SET colour=? WHERE id=?"); $u->execute([$hex,(int)$row['id']]);
    }
    return (int)$row['id'];
  }
  if ($hex) {
    $i=$pdo->prepare("INSERT INTO contractors (name, colour) VALUES (?,?)"); $i->execute([$name,$hex]);
  } else {
    $i=$pdo->prepare("INSERT INTO contractors (name) VALUES (?)"); $i->execute([$name]);
  }
  return (int)$pdo->lastInsertId();
}

function ensure_apartment(PDO $pdo, int $projectId, string $section): int {
  $unit = trim($section) !== '' ? trim($section) : 'Imported Lookahead';
  $s=$pdo->prepare("SELECT id FROM apartments WHERE project_id=? AND unit=?"); $s->execute([$projectId,$unit]);
  if ($r=$s->fetch()) return (int)$r['id'];
  $i=$pdo->prepare("INSERT INTO apartments (project_id, block, floor, unit, type) VALUES (?,?,?,?,?)");
  $i->execute([$projectId, null, null, $unit, null]);
  return (int)$pdo->lastInsertId();
}

require_csrf();

try {
  $payload = json_decode(file_get_contents('php://input') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
  $projectId   = (int)($payload['project'] ?? 0);
  $tasksIn     = $payload['tasks'] ?? [];
  $autoFS      = !empty($payload['auto_fs']);
  $baseline    = !empty($payload['baseline']);
  $useColours  = !empty($payload['color_contractors']);

  if ($projectId<=0) json_out(400,['ok'=>false,'error'=>'project required']);
  if (!is_array($tasksIn) || !$tasksIn) json_out(400,['ok'=>false,'error'=>'no tasks']);

  $pdo = DB::pdo(); $pdo->beginTransaction();

  $insTask = $pdo->prepare("
    INSERT INTO tasks
      (project_id, apartment_id, name, contractor_id, operatives, duration_days, start_date, finish_date, zone, notes)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  $sections = [];
  $inserted = [];
  foreach ($tasksIn as $t) {
    $name   = trim((string)($t['name'] ?? ''));
    $sec    = trim((string)($t['section'] ?? ''));
    $cont   = trim((string)($t['contractor'] ?? ''));
    $start  = (string)($t['start'] ?? '');
    $finish = (string)($t['finish'] ?? '');
    $colHex = (string)($t['colour'] ?? '');

    if ($name==='' || $start==='' || $finish==='') continue;

    $aptId = ensure_apartment($pdo, $projectId, $sec);
    $cid   = ensure_contractor($pdo, $cont, $colHex, $useColours);

    $dur = isset($t['duration_days']) ? (int)$t['duration_days'] : wd($start,$finish);
    if ($dur <= 0) $dur = 1;
    $ops = isset($t['operatives']) ? max(0,(int)$t['operatives']) : 1;

    $insTask->execute([$projectId,$aptId,$name,$cid,$ops,$dur,$start,$finish,$sec?:null,null]);
    $id = (int)$pdo->lastInsertId();
    $inserted[] = $id;

    $sections[$sec ?: 'Imported Lookahead'][] = ['id'=>$id,'start'=>$start];
  }

  // Auto FS
  if ($autoFS && $sections) {
    $insD=$pdo->prepare("INSERT INTO dependencies (task_id, predecessor_id, type, lag_days) VALUES (?,?, 'FS', 0)");
    foreach ($sections as $sec=>$rows) {
      usort($rows, fn($a,$b)=>strcmp($a['start'],$b['start']));
      for ($i=1;$i<count($rows);$i++) $insD->execute([$rows[$i]['id'],$rows[$i-1]['id']]);
    }
  }

  // Baseline (chunked IN)
  if ($baseline && $inserted) {
    try {
      $pdo->prepare("INSERT INTO baselines (project_id, label, created_at) VALUES (?,?, NOW())")
          ->execute([$projectId, 'Import – '.date('Y-m-d H:i')]);
    } catch (\Throwable $e) { /* ignore */ }
    for ($i=0; $i<count($inserted); $i+=500) {
      $ids = array_slice($inserted,$i,500);
      $ph = implode(',', array_fill(0,count($ids),'?'));
      $pdo->prepare("UPDATE tasks SET baseline_start=start_date, baseline_finish=finish_date WHERE id IN ($ph)")
          ->execute($ids);
    }
  }

  $pdo->commit();
  json_out(200, ['ok'=>true,'imported'=>count($inserted)]);
}
catch (\Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_out(500, ['ok'=>false,'error'=>$e->getMessage()]);
}

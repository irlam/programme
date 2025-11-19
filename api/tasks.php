<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;
use App\Lib\Scheduler;
use App\Lib\WorkingDays;

header('Content-Type: application/json');
$pdo = DB::pdo();

$action = $_GET['action'] ?? 'get';
$method = $_SERVER['REQUEST_METHOD'];

function ok($d = []) { echo json_encode(['ok'=>true] + $d); exit; }
function bad($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

/** Helpers */
function parse_date_flexible(?string $s): ?string {
  if (!$s) return null;
  $s = trim($s);
  if ($s === '' || stripos($s, 'dd') === 0) return null; // placeholder
  // UK dd/mm/YYYY
  if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
  }
  // ISO YYYY-mm-dd
  if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s)) return $s;
  // last-resort strtotime
  $ts = strtotime($s);
  return $ts ? date('Y-m-d', $ts) : null;
}

function working_days_inclusive(WorkingDays $wd, string $startIso, string $finishIso): int {
  $start = new DateTimeImmutable($startIso);
  $end   = new DateTimeImmutable($finishIso);
  if ($finishIso < $startIso) return 0;
  $n=0; $d=$start;
  while ($d <= $end) {
    if ($wd->isWorkingDay($d)) $n++;
    $d = $d->add(new DateInterval('P1D'));
  }
  return $n;
}

/** GET: /api/tasks.php?action=get&id=123 */
if ($action === 'get' && $method === 'GET') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) bad('id required');
  $st = $pdo->prepare("SELECT t.*, c.name contractor, a.block,a.floor,a.unit,a.type AS apt_type
                       FROM tasks t
                       LEFT JOIN contractors c ON c.id=t.contractor_id
                       LEFT JOIN apartments a   ON a.id=t.apartment_id
                       WHERE t.id=?");
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) bad('not found',404);
  $row['text'] = $row['name']; // for DayPilot convenience
  ok(['task'=>$row]);
}

/** POST JSON: /api/tasks.php?action=update
 *  Body accepts:
 *   - id (required)
 *   - name OR text
 *   - contractor_id OR contractor_name (creates contractor if not found)
 *   - operatives, duration_days, zone, constraint_start
 *   - start_date + finish_date  (UK or ISO) → converts to constraint_start + duration_days (working days inclusive)
 */
if ($action === 'update' && $method === 'POST') {
  require_role(['admin','planner']); csrf_check();

  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  $id = (int)($b['id'] ?? 0);
  if (!$id) bad('id required');

  // fetch existing (to get project/calendar)
  $task = $pdo->prepare("SELECT id, project_id, duration_days FROM tasks WHERE id=?");
  $task->execute([$id]);
  $trow = $task->fetch();
  if (!$trow) bad('task not found',404);

  $name = trim((string)($b['name'] ?? ($b['text'] ?? '')));
  if ($name === '') bad('name required');

  // ---- contractor resolution ----
  $contr = null;
  if (isset($b['contractor_id']) && $b['contractor_id'] !== '' && is_numeric($b['contractor_id'])) {
    $contr = (int)$b['contractor_id'];
  } elseif (!empty($b['contractor_name'])) {
    $cname = trim((string)$b['contractor_name']);
    $find = $pdo->prepare("SELECT id FROM contractors WHERE LOWER(name)=LOWER(?) LIMIT 1");
    $find->execute([$cname]);
    $cid = $find->fetchColumn();
    if ($cid) {
      $contr = (int)$cid;
    } else {
      $defaultColour = '#60a5fa';
      $ins = $pdo->prepare("INSERT INTO contractors (name, colour) VALUES (?, ?)");
      $ins->execute([$cname, $defaultColour]);
      $contr = (int)$pdo->lastInsertId();
    }
  }

  $ops   = array_key_exists('operatives', $b)    ? max(0, (int)$b['operatives'])    : null;
  $dur   = array_key_exists('duration_days', $b) ? max(0, (int)$b['duration_days']) : null;
  $zone  = array_key_exists('zone', $b)          ? trim((string)$b['zone'])         : null;

  // date inputs
  $constraint = parse_date_flexible($b['constraint_start'] ?? '');
  $startIso   = parse_date_flexible($b['start_date'] ?? '');
  $finishIso  = parse_date_flexible($b['finish_date'] ?? '');

  // If both start & finish provided, convert to SNET + duration (working days inclusive)
  if ($startIso && $finishIso) {
    $proj = $pdo->prepare("SELECT calendar_id FROM projects WHERE id=?");
    $proj->execute([(int)$trow['project_id']]);
    $calId = (int)$proj->fetchColumn();
    $wd = new WorkingDays($calId);
    $calcDur = working_days_inclusive($wd, $startIso, $finishIso);
    $constraint = $startIso;
    $dur = max(0, $calcDur);
  } elseif ($startIso && !$constraint) {
    // allow start-only: treat as SNET with same duration
    $constraint = $startIso;
  }
  // (finish-only is ignored — not enough info to compute reliably)

  // dynamic update
  $set = ['name=?']; $args = [$name];
  if ($contr !== null) { $set[]='contractor_id=?'; $args[]=$contr; }
  if ($ops   !== null) { $set[]='operatives=?';    $args[]=$ops; }
  if ($dur   !== null) { $set[]='duration_days=?'; $args[]=$dur; }
  if ($zone  !== null) { $set[]='zone=?';          $args[]=$zone; }
  if ($constraint !== null) { $set[]='constraint_start=?'; $args[]=$constraint; }
  $args[] = $id;

  $sql = "UPDATE tasks SET ".implode(',', $set)." WHERE id=?";
  $pdo->prepare($sql)->execute($args);

  // recalc project
  $pid = (int)$trow['project_id'];
  if ($pid) { (new Scheduler($pid))->recalc(); }

  ok(['id'=>$id]);
}

bad('unknown_action', 404);

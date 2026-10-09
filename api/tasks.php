<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/app/suite-prepend.php';

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
  if ($s === null || trim($s) === '') return null;
  $s = trim($s);
  if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $s, $m)) {
    $s = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
  }
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
  if (!$d || $d->format('Y-m-d') !== $s) bad('Invalid date. Use YYYY-MM-DD or DD/MM/YYYY.');
  return $s;
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
 *   - operatives, duration_days, zone, constraint_start, percent_complete
 *   - start_date + finish_date  (UK or ISO) → converts to constraint_start + duration_days (the scheduler's working-day interval)
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

  if (strlen($name) > 760) bad('Activity name is too long');
  foreach (['operatives','duration_days','percent_complete'] as $field) {
    if (array_key_exists($field, $b) && (filter_var($b[$field], FILTER_VALIDATE_INT) === false || (int)$b[$field] < 0)) bad("$field must be a non-negative whole number");
  }
  if (isset($b['percent_complete']) && (int)$b['percent_complete'] > 100) bad('Progress must be between 0 and 100');
  if (isset($b['duration_days']) && (int)$b['duration_days'] > 3650) bad('Duration cannot exceed 3650 working days');
  $constraint = parse_date_flexible($b['constraint_start'] ?? null);
  $startIso = parse_date_flexible($b['start_date'] ?? null);
  $finishIso = parse_date_flexible($b['finish_date'] ?? null);
  if ($finishIso && !$startIso) bad('Start date is required with finish date');
  if ($startIso && $finishIso && $finishIso < $startIso) bad('Finish cannot be before start');
  if ($startIso && $finishIso && (new DateTimeImmutable($startIso))->diff(new DateTimeImmutable($finishIso))->days > 7300) bad('Date range is too large');

  try {
    $pdo->beginTransaction();
    // Contractor changes and schedule recalculation succeed or roll back together.
    $set = ['name=?']; $args = [$name];
    if (array_key_exists('contractor_name', $b)) {
      $cname = trim((string)$b['contractor_name']);
      $contr = null;
      if ($cname !== '') {
        $find = $pdo->prepare('SELECT id FROM contractors WHERE LOWER(name)=LOWER(?) LIMIT 1');
        $find->execute([$cname]); $cid = $find->fetchColumn();
        if ($cid) $contr = (int)$cid;
        else {
          $pdo->prepare('INSERT INTO contractors (name, colour) VALUES (?, ?)')->execute([$cname, '#559584']);
          $contr = (int)$pdo->lastInsertId();
        }
      }
      $set[] = 'contractor_id=?'; $args[] = $contr;
    } elseif (array_key_exists('contractor_id', $b)) {
      $contr = $b['contractor_id'] === null || $b['contractor_id'] === '' ? null : (int)$b['contractor_id'];
      if ($contr !== null) {
        $check = $pdo->prepare('SELECT id FROM contractors WHERE id=?'); $check->execute([$contr]);
        if (!$check->fetchColumn()) throw new RuntimeException('Contractor not found');
      }
      $set[] = 'contractor_id=?'; $args[] = $contr;
    }
    foreach (['operatives','duration_days','percent_complete'] as $field) {
      if (array_key_exists($field, $b)) { $set[] = "$field=?"; $args[] = (int)$b[$field]; }
    }
    if (array_key_exists('zone', $b)) { $set[] = 'zone=?'; $args[] = trim((string)$b['zone']) ?: null; }
    if ($startIso && $finishIso) {
      $proj = $pdo->prepare('SELECT calendar_id FROM projects WHERE id=?'); $proj->execute([(int)$trow['project_id']]);
      $wd = new WorkingDays((int)$proj->fetchColumn());
      // Scheduler::recalc uses addWorkingDays(start, duration) for its finish.
      $duration = max(0, $wd->diffWorkingDays(new DateTimeImmutable($startIso), new DateTimeImmutable($finishIso)));
      // Date resizing wins over any submitted duration, without duplicate SETs.
      foreach ($set as $key => $clause) if ($clause === 'duration_days=?') { unset($set[$key], $args[$key]); }
      $set = array_values($set); $args = array_values($args);
      $set[] = 'duration_days=?'; $args[] = $duration;
    }
    if ($startIso || array_key_exists('constraint_start', $b)) {
      $set[] = 'constraint_start=?'; $args[] = $startIso ?: $constraint;
    }
    $args[] = $id;
    $pdo->prepare('UPDATE tasks SET '.implode(',', $set).' WHERE id=?')->execute($args);
    (new Scheduler((int)$trow['project_id']))->recalc();
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Programme task update: '.$e->getMessage());
    bad($e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Could not save this activity. No changes were applied.', 400);
  }

  ok(['id'=>$id]);
}

bad('unknown_action', 404);

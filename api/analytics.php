<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;
use App\Lib\WorkingDays;

header('Content-Type: application/json');

$pdo = DB::pdo();
$project = (int)($_GET['project'] ?? 1);
$type = $_GET['type'] ?? 'workforce';

$calId = (int)$pdo->query("SELECT calendar_id FROM projects WHERE id=$project")->fetchColumn();
$wd = new WorkingDays($calId);

$st = $pdo->prepare("SELECT t.*, c.name contractor FROM tasks t LEFT JOIN contractors c ON c.id=t.contractor_id WHERE t.project_id=?");
$st->execute([$project]);
$all = $st->fetchAll();

function workdays_between(string $a, string $b, WorkingDays $wd): int {
  $d1 = new DateTimeImmutable($a);
  $d2 = new DateTimeImmutable($b);
  if ($d1 == $d2) return 0;
  $step = ($d1 < $d2) ? 1 : -1;
  $cnt = 0; $d = $d1;
  while (($step>0 && $d < $d2) || ($step<0 && $d > $d2)) {
    if ($wd->isWorkingDay($d)) $cnt += $step;
    $d = $d->add(new DateInterval('P1D'));
  }
  return $cnt;
}

if ($type === 'workforce') {
  $byDay = []; $byDayContractor=[];
  foreach ($all as $t) {
    if (!$t['start_date'] || !$t['finish_date']) continue;
    $d1 = new DateTimeImmutable($t['start_date']);
    $d2 = new DateTimeImmutable($t['finish_date']);
    for ($d=$d1; $d <= $d2; $d=$d->add(new DateInterval('P1D'))) {
      if (!$wd->isWorkingDay($d)) continue;
      $k=$d->format('Y-m-d');
      $byDay[$k] = ($byDay[$k] ?? 0) + (int)$t['operatives'];
      $c = $t['contractor'] ?: 'Unassigned';
      $byDayContractor[$k][$c] = ($byDayContractor[$k][$c] ?? 0) + (int)$t['operatives'];
    }
  }
  ksort($byDay);
  echo json_encode(['ok'=>true,'type'=>'workforce','labels'=>array_keys($byDay),'total'=>array_values($byDay),'stack'=>$byDayContractor]); exit;
}

if ($type === 'tight') {
  $weekly = [];
  foreach ($all as $t) {
    if (empty($t['alerts_json'])) continue;
    $alerts = json_decode($t['alerts_json'], true) ?: [];
    if (empty($alerts['tight'])) continue;
    if (!$t['start_date']) continue;
    $week = (new DateTimeImmutable($t['start_date']))->format('o-\\WW');
    $c = $t['contractor'] ?: 'Unassigned';
    $weekly[$week][$c] = ($weekly[$week][$c] ?? 0) + count($alerts['tight']);
  }
  ksort($weekly);
  echo json_encode(['ok'=>true,'type'=>'tight','weeks'=>array_keys($weekly),'data'=>$weekly]); exit;
}

if ($type === 'throughput') {
  $startW=[]; $finishW=[];
  foreach ($all as $t) {
    if ($t['start_date'])  { $w=(new DateTimeImmutable($t['start_date']))->format('o-\\WW'); $startW[$w]=($startW[$w]??0)+1; }
    if ($t['finish_date']) { $w=(new DateTimeImmutable($t['finish_date']))->format('o-\\WW'); $finishW[$w]=($finishW[$w]??0)+1; }
  }
  $weeks = array_values(array_unique(array_merge(array_keys($startW), array_keys($finishW)))); sort($weeks);
  $s=[]; $f=[]; foreach ($weeks as $w){ $s[]=$startW[$w]??0; $f[]=$finishW[$w]??0; }
  echo json_encode(['ok'=>true,'type'=>'throughput','weeks'=>$weeks,'started'=>$s,'finished'=>$f]); exit;
}

if ($type === 'variance') {
  $rows=[];
  foreach ($all as $t) {
    if (!$t['baseline_start'] && !$t['baseline_finish']) continue;
    $vs = null; $vf = null;
    if ($t['baseline_start'] && $t['start_date'])  $vs = workdays_between($t['baseline_start'],$t['start_date'],$wd);
    if ($t['baseline_finish'] && $t['finish_date']) $vf = workdays_between($t['baseline_finish'],$t['finish_date'],$wd);
    $rows[] = [
      'id'=>$t['id'],'task'=>$t['name'],'contractor'=>$t['contractor'],
      'baseline_start'=>$t['baseline_start'],'start'=>$t['start_date'],'start_slip_days'=>$vs,
      'baseline_finish'=>$t['baseline_finish'],'finish'=>$t['finish_date'],'finish_slip_days'=>$vf
    ];
  }
  echo json_encode(['ok'=>true,'type'=>'variance','rows'=>$rows]); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown type']);
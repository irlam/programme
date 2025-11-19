<?php
declare(strict_types=1);
namespace App\Lib;
use App\Config\DB;
use DateTimeImmutable;
use RuntimeException;
final class Scheduler {
  public function __construct(private int $projectId) {}
  public function recalc(): void {
    $pdo = DB::pdo();
    $proj = $pdo->prepare("SELECT start_date, calendar_id FROM projects WHERE id=?");
    $proj->execute([$this->projectId]);
    $p = $proj->fetch();
    if (!$p) throw new RuntimeException("Project not found");
    $projStart = new DateTimeImmutable($p['start_date']);
    $wd = new WorkingDays((int)$p['calendar_id']);
    $tasksStmt = $pdo->prepare("SELECT * FROM tasks WHERE project_id=? ORDER BY id");
    $tasksStmt->execute([$this->projectId]);
    $tasks = $tasksStmt->fetchAll();
    $byId = [];
    foreach ($tasks as $t) $byId[(int)$t['id']] = $t;
    $deps = $pdo->prepare("SELECT d.*, t.project_id as t_project FROM dependencies d JOIN tasks t ON t.id=d.task_id WHERE t.project_id=?");
    $deps->execute([$this->projectId]);
    $preds = []; $succs = [];
    while ($row = $deps->fetch()) {
      $tid = (int)$row['task_id']; $pid = (int)$row['predecessor_id'];
      $preds[$tid][] = $row; $succs[$pid][] = $tid;
    }
    $inDeg = []; foreach ($byId as $id => $_) $inDeg[$id] = 0;
    foreach ($preds as $tid => $arr) foreach ($arr as $_) $inDeg[$tid]++;
    $queue = []; foreach ($inDeg as $id => $deg) if ($deg === 0) $queue[] = $id;
    $order = [];
    while ($queue) {
      $v = array_shift($queue); $order[] = $v;
      foreach (($succs[$v] ?? []) as $w) { $inDeg[$w]--; if ($inDeg[$w] === 0) $queue[] = $w; }
    }
    if (count($order) !== count($byId)) throw new RuntimeException("Dependency cycle detected. Please review links.");
    $computed = [];
    foreach ($order as $id) {
      $t = $byId[$id];
      $es = $projStart;
      if (!empty($preds[$id])) {
        $candidates = [];
        foreach ($preds[$id] as $drow) {
          $pTaskId = (int)$drow['predecessor_id'];
          $ptype = $drow['type']; $lag = (int)$drow['lag_days'];
          $p_es = $computed[$pTaskId]['es'] ?? $projStart;
          $p_ef = $computed[$pTaskId]['ef'] ?? $projStart;
          $candidates[] = ($ptype === 'FS') ? $wd->addWorkingDays($p_ef, $lag) : $wd->addWorkingDays($p_es, $lag);
        }
        sort($candidates);
        $es = end($candidates);
      }
      if (!empty($t['constraint_start'])) {
        $constraint = new \DateTimeImmutable($t['constraint_start']);
        if ($constraint > $es) $es = $constraint;
        $es = $wd->nextWorkingDay($es);
      } else {
        $es = $wd->nextWorkingDay($es);
      }
      $dur = max(0, (int)$t['duration_days']);
      $ef  = $dur === 0 ? $es : $wd->addWorkingDays($es, $dur);
      $computed[$id] = ['es'=>$es, 'ef'=>$ef];
    }
    $upd = $pdo->prepare("UPDATE tasks SET start_date=?, finish_date=?, alerts_json=? WHERE id=?");
    foreach ($computed as $id => $dates) {
      $alerts = [];
      foreach ($preds[$id] ?? [] as $drow) {
        if ($drow['type'] !== 'FS') continue;
        $pid = (int)$drow['predecessor_id'];
        $gap = $wd->diffWorkingDays($computed[$pid]['ef'], $dates['es']);
        if ($gap < 1) $alerts['tight'][] = $pid;
      }
      $t = $byId[$id]; $overlapWith = [];
      foreach ($byId as $oid => $other) {
        if ($oid === $id) continue;
        if ((int)$other['apartment_id'] !== (int)$t['apartment_id']) continue;
        if (($other['zone'] ?? null) !== ($t['zone'] ?? null)) continue;
        $a1 = $dates['es']; $a2 = $dates['ef'];
        $b1 = $computed[$oid]['es'] ?? null; $b2 = $computed[$oid]['ef'] ?? null;
        if (!$b1 || !$b2) continue;
        $intersects = !($a2 <= $b1 || $b2 <= $a1);
        if ($intersects) {
          $permits = false;
          foreach ($preds[$id] ?? [] as $drow) if ((int)$drow['predecessor_id'] === $oid && $drow['type'] === 'SS') $permits = True;
          foreach ($preds[$oid] ?? [] as $drow) if ((int)$drow['predecessor_id'] === $id && $drow['type'] === 'SS') $permits = True;
          if (!$permits) $overlapWith[] = $oid;
        }
      }
      if ($overlapWith) $alerts['overlap_with'] = array_values(array_unique($overlapWith));
      $upd->execute([$dates['es']->format('Y-m-d'), $dates['ef']->format('Y-m-d'), $alerts ? json_encode($alerts) : null, $id]);
    }
  }
}

<?php
declare(strict_types=1);
namespace App\Lib;
use App\Config\DB;
use DateInterval;
use DateTimeImmutable;
final class WorkingDays {
  public function __construct(private int $calendarId) {}
  private function workdays(): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT workdays_json FROM calendars WHERE id=?");
    $stmt->execute([$this->calendarId]);
    $row = $stmt->fetch();
    $w = $row ? json_decode((string)$row['workdays_json'], true) : null;
    return $w ?: ['mon'=>1,'tue'=>1,'wed'=>1,'thu'=>1,'fri'=>1,'sat'=>0,'sun'=>0];
  }
  private function isException(string $ymd): ?bool {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT is_working FROM calendar_holidays WHERE calendar_id=? AND date=?");
    $stmt->execute([$this->calendarId, $ymd]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : (bool)$val;
  }
  public function isWorkingDay(DateTimeImmutable $d): bool {
    $dow = strtolower($d->format('D'));
    $map = ['mon'=>'mon','tue'=>'tue','wed'=>'wed','thu'=>'thu','fri'=>'fri','sat'=>'sat','sun'=>'sun'];
    $wd = $this->workdays()[$map[$dow]] ?? 0;
    $ymd = $d->format('Y-m-d');
    $ex = $this->isException($ymd);
    if ($ex !== null) return $ex;
    return (bool)$wd;
  }
  public function addWorkingDays(DateTimeImmutable $start, int $days): DateTimeImmutable {
    if ($days == 0) return $start;
    $remaining = abs($days);
    $d = $start;
    $forward = $days > 0;
    while ($remaining > 0) {
      $d = $forward ? $d->add(new DateInterval('P1D')) : $d->sub(new DateInterval('P1D'));
      if ($this->isWorkingDay($d)) $remaining--;
    }
    return $d;
  }
  public function nextWorkingDay(DateTimeImmutable $d): DateTimeImmutable {
    $x = $d;
    while (!$this->isWorkingDay($x)) $x = $x->add(new DateInterval('P1D'));
    return $x;
  }
  public function diffWorkingDays(DateTimeImmutable $a, DateTimeImmutable $b): int {
    if ($a == $b) return 0;
    $count = 0; $forward = $a < $b; $d = $a;
    while (($forward && $d < $b) || (!$forward && $d > $b)) {
      $d = $forward ? $d->add(new DateInterval('P1D')) : $d->sub(new DateInterval('P1D'));
      if ($this->isWorkingDay($d)) $count += $forward ? 1 : -1;
    }
    return $count;
  }
}

<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

use App\Config\DB;
use App\Lib\WorkingDays;

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug) { @ini_set('display_errors','1'); error_reporting(E_ALL); }
register_shutdown_function(function() use ($debug) {
  if (!$debug) return;
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "FATAL: {$e['message']} in {$e['file']}:{$e['line']}";
  }
});

$pdo     = DB::pdo();
$project = (int)($_GET['project'] ?? 1);
$days    = max(7, (int)($_GET['days'] ?? 14));
$group   = ($_GET['group'] ?? 'apartment');
$from    = $_GET['from'] ?? date('Y-m-d');
$format  = ($_GET['format'] ?? 'pdf');
$nofpdf  = isset($_GET['nofpdf']) && $_GET['nofpdf'] == '1';
$progressLine = ($_GET['progress_line'] ?? '') === '1';
$statusDate = (string)($_GET['status_date'] ?? '');
if ($progressLine && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $statusDate)) {
  http_response_code(400); exit('Choose a valid review date.');
}
if ($progressLine) {
  $checkDate = DateTimeImmutable::createFromFormat('!Y-m-d', $statusDate);
  if (!$checkDate || $checkDate->format('Y-m-d') !== $statusDate) { http_response_code(400); exit('Choose a valid review date.'); }
}

$proj = $pdo->prepare("SELECT start_date, calendar_id, name FROM projects WHERE id=?");
$proj->execute([$project]);
$p = $proj->fetch();
if (!$p) { http_response_code(404); exit('Project not found'); }

$wd = new WorkingDays((int)$p['calendar_id']);
$start = new DateTimeImmutable($from);

$dates = [];
$d = $start;
// New workspace links use the same calendar-day window shown on screen.
// Existing API links retain their working-day window semantics.
$calendarWindow = ($_GET['calendar_window'] ?? '') === '1';
$calendarEnd = $start->add(new DateInterval('P' . ($days - 1) . 'D'));
while (count($dates) < $days) {
  if ($calendarWindow && $d > $calendarEnd) break;
  if ($wd->isWorkingDay($d)) $dates[] = $d->format('Y-m-d');
  $d = $d->add(new DateInterval('P1D'));
}
if (!$dates) { http_response_code(400); exit('No working days in this window. Choose a different date window.'); }
$winStart = reset($dates);
$winEnd   = end($dates);

$sql = "SELECT t.*, c.name contractor, c.colour,
               a.block, a.floor, a.unit, a.type AS apt_type
        FROM tasks t
        LEFT JOIN contractors c ON c.id=t.contractor_id
        JOIN apartments a ON a.id=t.apartment_id
        WHERE t.project_id=? AND t.start_date <= ? AND t.finish_date >= ?
        ORDER BY a.block, a.floor, a.unit, t.start_date, t.id";
$st = $pdo->prepare($sql);
$st->execute([$project, $winEnd, $winStart]);
$rows = $st->fetchAll();

$uk = function(?string $ymd): string {
  if (!$ymd) return '';
  try { return (new DateTimeImmutable($ymd))->format('d/m/Y'); }
  catch (Throwable) { return $ymd; }
};
$txt = function(string $s): string {
  $s = str_replace(["\u{2013}", "\u{2022}", "\u{2192}"], ['-', '•', '->'], $s);
  $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
  return $out !== false ? $out : $s;
};

$bucketed = [];
foreach ($rows as $r) {
  $apt = trim(($r['block'] ?? '') . ' ' . ($r['floor'] ?? '') . ' ' . ($r['unit'] ?? '') . ' ' . ($r['apt_type'] ?? ''));
  $apt = ($apt !== '') ? $apt : 'Apartment';
  $key = ($group === 'contractor')
    ? ((($r['contractor'] ?? '') !== '') ? $r['contractor'] : 'Unassigned')
    : $apt;
  $bucketed[$key][] = $r;
}

$render_html = function(string $title, array $dates, array $bucketed, string $group) use ($p, $winStart, $winEnd, $uk) {
  ob_start(); ?>
  <!doctype html><html><head>
  <meta charset="utf-8"><title>Short-term Programme</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#0f172a;color:#e5e7eb}
    .wrap{max-width:1100px;margin:0 auto;padding:20px}
    .board{border:1px solid #1f2937;border-radius:10px;overflow:hidden;background:#0b1220}
    table{width:100%;border-collapse:collapse}
    th,td{font-size:12px;border-bottom:1px solid #1f2937;padding:8px 6px;vertical-align:top}
    th{position:sticky;top:0;background:#111827}
    .day{display:inline-block;width:14px;height:10px;margin:0 1px;background:#111827;border:1px solid #1f2937;border-radius:2px}
    .on{background:#60a5fa;border-color:#2563eb}
    @media print { body{background:#fff;color:#000} .board{border-color:#ccc} th{background:#eee} .day{border-color:#ccc} .on{background:#000} }
  </style></head><body>
  <div class="wrap">
    <h1><?=htmlspecialchars($title)?></h1>
    <p><?=htmlspecialchars($p['name'])?> • Window: <?=htmlspecialchars($uk($winStart))?> → <?=htmlspecialchars($uk($winEnd))?></p>
    <div class="board"><table><thead>
      <tr>
        <th style="width:260px"><?= $group==='contractor' ? 'Contractor' : 'Apartment' ?></th>
        <th>Task</th><th style="width:80px">Ops</th>
        <th style="width:110px">Start</th><th style="width:110px">Finish</th>
        <th style="width:<?=count($dates)*16?>px"></th>
      </tr>
    </thead><tbody>
    <?php foreach ($bucketed as $g => $tasks): ?>
      <tr><td colspan="6" style="background:#0f172a;color:#93c5fd;font-weight:600"><?=htmlspecialchars($g)?></td></tr>
      <?php foreach ($tasks as $t): ?>
        <tr>
          <td><?= $group==='contractor' ? htmlspecialchars($t['contractor']?:'Unassigned') : htmlspecialchars(trim(($t['block']?:'')." ".($t['floor']?:'')." ".($t['unit']?:'')." ".($t['apt_type']?:''))) ?></td>
          <td><?=htmlspecialchars($t['name'])?></td>
          <td><?= (int)$t['operatives'] ?></td>
          <td><?= htmlspecialchars($uk($t['start_date'])) ?></td>
          <td><?= htmlspecialchars($uk($t['finish_date'])) ?></td>
          <td><?php foreach ($dates as $d): $on = ($t['start_date'] <= $d && $t['finish_date'] >= $d); ?>
            <span class="day <?=$on?'on':''?>" title="<?=$d?>"></span>
          <?php endforeach; ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody></table></div>
  </div></body></html>
  <?php return ob_get_clean();
};

if ($format === 'html') {
  header('Content-Type: text/html; charset=utf-8');
  echo $render_html('Short-term Programme ('.$group.')', $dates, $bucketed, $group);
  exit;
}

$fpdfPath = null;
if (!$nofpdf) {
  $root = dirname(__DIR__, 2);
  foreach ([
    $root.'/app/Lib/fpdf/fpdf.php',
    $root.'/app/Lib/FPDF.php',
    $root.'/app/lib/fpdf/fpdf.php',
    $root.'/app/lib/FPDF.php',
  ] as $cand) if (is_file($cand)) { $fpdfPath = $cand; break; }
}

if ($fpdfPath && !$nofpdf) {
  require_once $fpdfPath;

  require_once dirname(__DIR__, 2) . '/app/Lib/ShortTermReport.php';
  $pdf = new \App\Lib\ShortTermReport((string)$p['name'], $dates, (string)$group, $progressLine ? $statusDate : null);
  $pdf->render($bucketed);

  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="shortterm.pdf"');
  $pdf->Output('I'); exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $render_html('Short-term Programme ('.$group.')', $dates, $bucketed, $group);

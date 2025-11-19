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

$proj = $pdo->prepare("SELECT start_date, calendar_id, name FROM projects WHERE id=?");
$proj->execute([$project]);
$p = $proj->fetch();
if (!$p) { http_response_code(404); exit('Project not found'); }

$wd = new WorkingDays((int)$p['calendar_id']);
$start = new DateTimeImmutable($from);

$dates = [];
$d = $start;
while (count($dates) < $days) {
  if ($wd->isWorkingDay($d)) $dates[] = $d->format('Y-m-d');
  $d = $d->add(new DateInterval('P1D'));
}
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

  class ShortTermPDF extends FPDF {
    function headerRow(array $labels, array $widths) {
      $this->SetFont('Arial','B',10);
      foreach ($labels as $i=>$t) { $this->Cell($widths[$i], 8, $t, 1, 0, 'L', true); }
      $this->Ln();
    }
  }

  $palette = [
    'header_fill'  => [17,24,39],
    'header_text'  => [255,255,255],
    'header_line'  => [31,41,55],
    'group_fill'   => [15,23,42],
    'group_text'   => [147,197,253],
    'group_line'   => [31,41,55],
    'timeline_bg'  => [11,18,32],
    'timeline_grid'=> [45,55,72],
    'cell_line'    => [210,215,220],
    'text'         => [0,0,0],
  ];

  $pdf = new ShortTermPDF('L','mm','A4');
  $pdf->SetMargins(12,12,12);
  $pdf->SetAutoPageBreak(true, 12);
  $pdf->AddPage();

  $txt = function(string $s): string {
    $s = str_replace(["\u{2013}", "\u{2022}", "\u{2192}"], ['-', '•', '->'], $s);
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $out !== false ? $out : $s;
  };

  $uk = function(?string $ymd): string {
    if (!$ymd) return '';
    try { return (new DateTimeImmutable($ymd))->format('d/m/Y'); }
    catch (Throwable) { return $ymd; }
  };

  $pdf->SetFont('Arial','B',14);
  $pdf->SetTextColor(...$palette['text']);
  $pdf->Cell(0,8,$txt('Short-term Programme ('.ucfirst($group).')'),0,1,'L');
  $pdf->SetFont('Arial','',10);
  $pdf->Cell(0,6,$txt($p['name'].'  –  Window: '.$uk($winStart).' → '.$uk($winEnd)),0,1,'L');
  $pdf->Ln(2);

  $colGroup=54; $colTask=80; $colOps=12; $colStart=22; $colFinish=22;
  $timelineW = 273 - ($colGroup+$colTask+$colOps+$colStart+$colFinish);
  $dayCellW  = max(2.5, min(6.0, $timelineW / max(1,count($dates))));
  $rowH=6.5;

  $pdf->SetFillColor(...$palette['header_fill']);
  $pdf->SetTextColor(...$palette['header_text']);
  $pdf->SetDrawColor(...$palette['header_line']);
  $pdf->headerRow(
    [$txt($group==='contractor'?'Contractor':'Apartment'),$txt('Task'),$txt('Ops'),$txt('Start'),$txt('Finish'),$txt('Timeline')],
    [$colGroup,$colTask,$colOps,$colStart,$colFinish,$timelineW]
  );

  $dateIndex = array_flip($dates);
  $hex2rgb = function(?string $hex): array {
    $hex = $hex ?: '#60a5fa'; $hex = ltrim($hex,'#');
    if (strlen($hex)===3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
  };

  foreach ($bucketed as $gkey=>$tasks) {
    $pdf->SetFillColor(...$palette['group_fill']);
    $pdf->SetTextColor(...$palette['group_text']);
    $pdf->SetDrawColor(...$palette['group_line']);
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell($colGroup+$colTask+$colOps+$colStart+$colFinish+$timelineW,7,$txt(' '.$gkey),1,1,'L',true);

    $pdf->SetFont('Arial','',9);
    $pdf->SetTextColor(...$palette['text']);
    $pdf->SetDrawColor(...$palette['cell_line']);

    foreach ($tasks as $t) {
      if ($pdf->GetY() > 190) {
        $pdf->AddPage();
        $pdf->SetFillColor(...$palette['header_fill']);
        $pdf->SetTextColor(...$palette['header_text']);
        $pdf->SetDrawColor(...$palette['header_line']);
        $pdf->headerRow(
          [$txt($group==='contractor'?'Contractor':'Apartment'),$txt('Task'),$txt('Ops'),$txt('Start'),$txt('Finish'),$txt('Timeline')],
          [$colGroup,$colTask,$colOps,$colStart,$colFinish,$timelineW]
        );
        $pdf->SetFont('Arial','',9);
        $pdf->SetTextColor(...$palette['text']);
        $pdf->SetDrawColor(...$palette['cell_line']);
      }

      $apt = trim(($t['block']?:'').' '.($t['floor']?:'').' '.($t['unit']?:'').' '.($t['apt_type']?:''));
      $who = ($group==='contractor') ? ($t['contractor'] ?: 'Unassigned') : ($apt ?: 'Apartment');

      $pdf->Cell($colGroup,$rowH,$txt($who),1,0,'L');
      $pdf->Cell($colTask,$rowH,$txt($t['name']),1,0,'L');
      $pdf->Cell($colOps,$rowH,(string)(int)$t['operatives'],1,0,'C');
      $pdf->Cell($colStart,$rowH,$uk($t['start_date']),1,0,'C');
      $pdf->Cell($colFinish,$rowH,$uk($t['finish_date']),1,0,'C');

      $x=$pdf->GetX(); $y=$pdf->GetY();
      $pdf->SetFillColor(...$palette['timeline_bg']);
      $pdf->SetDrawColor(...$palette['cell_line']);
      $pdf->Cell($timelineW,$rowH,'',1,0,'L',true);

      $pdf->SetDrawColor(...$palette['timeline_grid']);
      for ($i=0;$i<count($dates);$i++) $pdf->Line($x+$i*$dayCellW,$y,$x+$i*$dayCellW,$y+$rowH);

      $s = max($t['start_date'],$winStart); $f = min($t['finish_date'],$winEnd);
      if (isset($dateIndex[$s]) && isset($dateIndex[$f])) {
        $i0=$dateIndex[$s]; $i1=$dateIndex[$f];
        $barX=$x+$i0*$dayCellW+0.3; $barW=max(1.8,($i1-$i0+1)*$dayCellW-0.6);
        [$r,$g,$b]=$hex2rgb($t['colour']??'#60a5fa');
        $pdf->SetFillColor($r,$g,$b);
        $pdf->SetDrawColor(0,0,0);
        $pdf->Rect($barX,$y+1.2,$barW,$rowH-2.4,'F');
      }

      $pdf->Ln();
    }
  }

  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="shortterm.pdf"');
  $pdf->Output('I'); exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $render_html('Short-term Programme ('.$group.')', $dates, $bucketed, $group);
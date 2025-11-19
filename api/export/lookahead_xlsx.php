<?php
declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

use App\Config\DB;

function phpss_load(): bool {
  $root = dirname(__DIR__, 2);
  foreach ([$root . '/vendor/autoload.php', $root . '/libs/autoload-phpss.php'] as $p) {
    if (is_file($p)) { require_once $p; return true; }
  }
  return false;
}

$project = (int)($_GET['project'] ?? 1);
$days    = max(5, (int)($_GET['days'] ?? 42)); // default 6 weeks
$from    = $_GET['from'] ?? null;
$group   = $_GET['group'] ?? 'apartment'; // apartment | contractor

$pdo = DB::pdo();

// window
if (!$from) {
  $from = (string)$pdo->query("SELECT COALESCE(MIN(start_date), CURDATE()) FROM tasks WHERE project_id=".(int)$project)->fetchColumn();
  if (!$from) $from = date('Y-m-d');
}
$start = new DateTimeImmutable($from);
$dates = [];
$d = $start;
for ($i=0;$i<$days;$i++) {
  $dates[] = $d->format('Y-m-d');
  $d = $d->add(new DateInterval('P1D'));
}
$winStart = $dates[0];
$winEnd   = $dates[$days-1];

// tasks overlapping window
$sql = "SELECT t.*, c.name contractor, c.colour,
               a.block, a.floor, a.unit, a.type AS apt_type
        FROM tasks t
        LEFT JOIN contractors c ON c.id=t.contractor_id
        LEFT JOIN apartments a ON a.id=t.apartment_id
        WHERE t.project_id=? AND t.start_date <= ? AND t.finish_date >= ?
        ORDER BY a.block, a.floor, a.unit, t.start_date, t.id";
$st = $pdo->prepare($sql);
$st->execute([$project, $winEnd, $winStart]);
$rows = $st->fetchAll();

// group
$bucket = [];
foreach ($rows as $r) {
  $key = ($group === 'contractor')
    ? ($r['contractor'] ?: 'Unassigned')
    : trim(($r['block']?:'').' '.($r['floor']?:'').' '.($r['unit']?:'').' '.($r['apt_type']?:'')) ?: 'Apartment';
  $bucket[$key][] = $r;
}

// Try PhpSpreadsheet; else CSV fallback
$hasPhpSS = phpss_load();
if (!$hasPhpSS) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="lookahead_grid_project'.$project.'.csv"');
  $out = fopen('php://output','w');
  // CSV header
  $hdr = [$group==='contractor'?'Contractor':'Apartment','Task','Contractor','Ops','Start','Finish'];
  foreach ($dates as $d) $hdr[] = $d;
  fputcsv($out, $hdr);
  foreach ($bucket as $g => $tasks) {
    foreach ($tasks as $t) {
      $row = [$g, $t['name'], $t['contractor'], (int)$t['operatives'], $t['start_date'], $t['finish_date']];
      foreach ($dates as $day) $row[] = ($t['start_date'] <= $day && $t['finish_date'] >= $day) ? '1' : '';
      fputcsv($out, $row);
    }
  }
  fclose($out);
  exit;
}

// Build XLSX grid
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$ss = new Spreadsheet();
$ws = $ss->getActiveSheet();
$ws->setTitle('6-week Lookahead');

$row = 1; $col = 1;

// title & meta
$ws->setCellValueByColumnAndRow($col, $row, '6-Week Lookahead');
$ws->getStyleByColumnAndRow($col, $row)->getFont()->setBold(true)->setSize(14);
$row++;
$ws->setCellValueByColumnAndRow($col, $row, "Project #{$project}  •  Window: ".date('d/m/Y', strtotime($winStart))." → ".date('d/m/Y', strtotime($winEnd)));
$row += 2;

// header row
$labelsLeft = [ $group==='contractor'?'Contractor':'Apartment', 'Task', 'Contractor', 'Ops', 'Start', 'Finish' ];
$c = 1;
foreach ($labelsLeft as $lab) {
  $ws->setCellValueByColumnAndRow($c++, $row, $lab);
}
foreach ($dates as $d) {
  $ws->setCellValueByColumnAndRow($c++, $row, \DateTime::createFromFormat('Y-m-d',$d));
}
$ws->getStyleByColumnAndRow(1, $row, count($labelsLeft)+count($dates), $row)->getFont()->setBold(true);
$ws->getStyleByColumnAndRow(count($labelsLeft)+1, $row, count($labelsLeft)+count($dates), $row)
   ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYY);
$row++;

// grid rows
$dayStartCol = count($labelsLeft) + 1;
$blue = '4F81BD'; // Excel-style blue

foreach ($bucket as $g => $tasks) {
  // group header
  $ws->setCellValueByColumnAndRow(1, $row, $g);
  $ws->mergeCellsByColumnAndRow(1, $row, $dayStartCol+count($dates)-1, $row);
  $ws->getStyleByColumnAndRow(1, $row)->getFont()->setBold(true);
  $row++;

  foreach ($tasks as $t) {
    $c = 1;
    $ws->setCellValueByColumnAndRow($c++, $row, $g);
    $ws->setCellValueByColumnAndRow($c++, $row, $t['name']);
    $ws->setCellValueByColumnAndRow($c++, $row, $t['contractor']);
    $ws->setCellValueByColumnAndRow($c++, $row, (int)$t['operatives']);
    $ws->setCellValueByColumnAndRow($c++, $row, $t['start_date'] ? \DateTime::createFromFormat('Y-m-d',$t['start_date']) : null);
    $ws->setCellValueByColumnAndRow($c++, $row, $t['finish_date'] ? \DateTime::createFromFormat('Y-m-d',$t['finish_date']) : null);

    // fill timeline
    $cDay = $dayStartCol;
    foreach ($dates as $d) {
      $on = ($t['start_date'] <= $d && $t['finish_date'] >= $d);
      if ($on) {
        $ws->getStyleByColumnAndRow($cDay, $row)->getFill()
           ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($blue);
      }
      $cDay++;
    }
    $row++;
  }
  $row++;
}

// tidy
$ws->freezePaneByColumnAndRow($dayStartCol, 5);
foreach (range(1, $dayStartCol-1) as $cc) { $ws->getColumnDimensionByColumn($cc)->setAutoSize(true); }
$ws->getStyleByColumnAndRow($dayStartCol, 4, $dayStartCol+count($dates)-1, 4)
   ->getAlignment()->setTextRotation(45)->setHorizontal(Alignment::HORIZONTAL_CENTER);
$ws->getStyleByColumnAndRow($dayStartCol, 4, $dayStartCol+count($dates)-1, 4)
   ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMM);

// output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="lookahead_grid_project'.$project.'.xlsx"');
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;

<?php
declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

use App\Config\DB;

function phpss_load(): bool {
  // Try Composer first, then manual
  $root = dirname(__DIR__, 2);
  $paths = [
    $root . '/vendor/autoload.php',
    $root . '/libs/autoload-phpss.php',
  ];
  foreach ($paths as $p) {
    if (is_file($p)) { require_once $p; return true; }
  }
  return false;
}

$project = (int)($_GET['project'] ?? 1);

$pdo = DB::pdo();
$sql = "SELECT 
          t.id, t.name, t.operatives, t.duration_days, t.start_date, t.finish_date, t.zone, t.notes,
          c.name AS contractor,
          a.block, a.floor, a.unit, a.type AS apt_type
        FROM tasks t
        LEFT JOIN contractors c ON c.id = t.contractor_id
        LEFT JOIN apartments a  ON a.id = t.apartment_id
        WHERE t.project_id = ?
        ORDER BY a.block, a.floor, a.unit, t.start_date, t.id";
$st = $pdo->prepare($sql);
$st->execute([$project]);
$rows = $st->fetchAll();

$hasPhpSS = phpss_load();

if (!$hasPhpSS) {
  // Fallback: CSV
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="programme_tasklist_project'.$project.'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Block','Floor','Unit','Type','Task','Contractor','Ops','Duration (wd)','Start','Finish','Zone','Notes']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['block'],$r['floor'],$r['unit'],$r['apt_type'],
      $r['name'],$r['contractor'],(int)$r['operatives'],(int)$r['duration_days'],
      $r['start_date'],$r['finish_date'],$r['zone'],$r['notes']
    ]);
  }
  fclose($out);
  exit;
}

// PhpSpreadsheet output
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$ss = new Spreadsheet();
$ws = $ss->getActiveSheet();
$ws->setTitle('Task List');

// header
$hdr = ['Block','Floor','Unit','Type','Task','Contractor','Ops','Duration (wd)','Start','Finish','Zone','Notes'];
$col = 1;
foreach ($hdr as $h) { $ws->setCellValueByColumnAndRow($col++, 1, $h); }
$ws->getStyle('A1:L1')->getFont()->setBold(true);

// rows
$ridx = 2;
foreach ($rows as $r) {
  $c = 1;
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['block']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['floor']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['unit']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['apt_type']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['name']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['contractor']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, (int)$r['operatives']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, (int)$r['duration_days']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['start_date'] ? \DateTime::createFromFormat('Y-m-d',$r['start_date']) : null);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['finish_date'] ? \DateTime::createFromFormat('Y-m-d',$r['finish_date']) : null);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['zone']);
  $ws->setCellValueByColumnAndRow($c++, $ridx, $r['notes']);
  $ridx++;
}

// formats, filters, sizing
$ws->getStyle("I2:J{$ridx}")
   ->getNumberFormat()
   ->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
$ws->setAutoFilter("A1:L1");
$ws->freezePane('A2');
foreach (range('A','L') as $colLetter) { $ws->getColumnDimension($colLetter)->setAutoSize(true); }

// output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="programme_tasklist_project'.$project.'.xlsx"');
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;

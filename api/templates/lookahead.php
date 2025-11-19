<?php
declare(strict_types=1);

/**
 * 6-Week Lookahead (calendar grid) — ultra-safe generator
 * - No Excel formulas, no wide range styling, no merges
 * - Writes to /httpdocs/tmp then streams or saves
 * - ?save=1       → save into /templates/lookahead_template.xlsx
 * - ?base=YYYY-MM-DD → first date (default: next Monday)
 * - ?debug=1      → show errors
 */

date_default_timezone_set('Europe/London');

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug) { @ini_set('display_errors', '1'); error_reporting(E_ALL); }
register_shutdown_function(function () use ($debug) {
  if (!$debug) return;
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "FATAL: {$e['message']} in {$e['file']}:{$e['line']}";
  }
});

$root   = dirname(__DIR__, 2); // /httpdocs
$tmpDir = is_dir($root.'/tmp') && is_writable($root.'/tmp') ? $root.'/tmp' : sys_get_temp_dir();

$autoload = $root . '/libs/autoload-phpss.php';
if (!is_file($autoload)) { http_response_code(500); exit('ERROR: autoload-phpss.php not found'); }
require $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

function nextMonday(\DateTimeImmutable $from): \DateTimeImmutable {
  $dow = (int)$from->format('N'); // 1..7
  $add = ($dow === 1) ? 0 : (8 - $dow);
  return $from->add(new DateInterval("P{$add}D"));
}

try {
  if (!class_exists(Spreadsheet::class)) throw new RuntimeException('PhpSpreadsheet not available');

  $numDays = 42;            // 6 weeks
  $firstColIdx = 5;         // column E
  $weekdayRow = 4;          // textual weekday
  $dateRow    = 5;          // dd/mm
  $rowStart   = 6;          // first task row

  $base = isset($_GET['base']) ? new DateTimeImmutable($_GET['base']) : nextMonday(new DateTimeImmutable('today'));

  $ss = new Spreadsheet();
  $ws = $ss->getActiveSheet();
  $ws->setTitle('Lookahead');

  // Title (simple, no merge)
  $ws->setCellValue('A1', '6-Week Lookahead (Template)');
  $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);

  // Meta row
  $ws->setCellValue('B3', 'Date');
  $ws->getStyle('B3')->getFont()->setBold(true);
  $ws->setCellValue('C3', $base->format('Y-m-d'));
  $ws->setCellValue('D3', 'Change C3 (text) to shift printed header only');

  // Left headers
  $ws->setCellValue('A5', 'Section');
  $ws->setCellValue('B5', 'Activity');
  $ws->setCellValue('C5', 'Contractor');
  $ws->setCellValue('D5', 'Notes');
  foreach (['A5','B5','C5','D5'] as $addr) {
    $ws->getStyle($addr)->getFont()->setBold(true);
    $ws->getStyle($addr)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle($addr)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
  }

  // Column widths
  $ws->getColumnDimension('A')->setWidth(12);
  $ws->getColumnDimension('B')->setWidth(40);
  $ws->getColumnDimension('C')->setWidth(20);
  $ws->getColumnDimension('D')->setWidth(22);
  for ($i=0; $i<$numDays; $i++) {
    $ws->getColumnDimensionByColumn($firstColIdx + $i)->setWidth(3.5);
  }

  // Weekday + date row (pure text; minimal per-cell styling)
  for ($i=0; $i<$numDays; $i++) {
    $colIdx = $firstColIdx + $i;
    $d = $base->add(new DateInterval("P{$i}D"));
    $weekday = $d->format('D');     // Mon
    $ddmm    = $d->format('d/m');   // 12/08

    $ws->setCellValueByColumnAndRow($colIdx, $weekdayRow, $weekday);
    $ws->setCellValueByColumnAndRow($colIdx, $dateRow,    $ddmm);

    // style each cell separately (avoid range ops)
    $ws->getStyleByColumnAndRow($colIdx, $weekdayRow)->getFont()->setBold(true);
    $ws->getStyleByColumnAndRow($colIdx, $weekdayRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyleByColumnAndRow($colIdx, $weekdayRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $ws->getStyleByColumnAndRow($colIdx, $dateRow)->getFont()->setBold(true);
    $ws->getStyleByColumnAndRow($colIdx, $dateRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyleByColumnAndRow($colIdx, $dateRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // weekend shade only these two header cells (avoid long column fills)
    $isWknd = ((int)$d->format('N')) >= 6;
    if ($isWknd) {
      $ws->getStyleByColumnAndRow($colIdx, $weekdayRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDDDDD');
      $ws->getStyleByColumnAndRow($colIdx, $dateRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDDDDD');
    }
  }

  // Example rows
  $ws->fromArray([
    ['Core','BWH to structural walls','Panacea',''],
    ['Wetrooms','SVP/RWP install','GPL',''],
    ['Core','Structural walls 1st fix','Edencroft',''],
  ], null, 'A6');

  // Light grid for visible area (apply per row to avoid long ranges)
  for ($r = 5; $r <= 12; $r++) {
    for ($c = 1; $c <= ($firstColIdx + $numDays - 1); $c++) {
      $ws->getStyleByColumnAndRow($c, $r)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR);
    }
  }

  // Save to tmp then serve / copy
  $fname = 'lookahead_template.xlsx';
  $tmp = rtrim($tmpDir, '/').'/'.$fname;
  (new Xlsx($ss))->save($tmp);

  if (isset($_GET['save']) && $_GET['save'] == '1') {
    $destDir = $root.'/templates';
    if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
    $dest = $destDir.'/'.$fname;
    if (!@copy($tmp, $dest)) { http_response_code(500); exit('ERROR: cannot save to /templates (permissions?)'); }
    @unlink($tmp);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Saved to /templates/{$fname}";
    exit;
  }

  if (!is_file($tmp)) { http_response_code(500); exit('ERROR: temp file not created'); }
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
} catch (Throwable $e) {
  http_response_code(500);
  if ($debug) { header('Content-Type: text/plain; charset=utf-8'); echo 'ERROR: '.$e->getMessage(); }
  else { echo 'ERROR'; }
}

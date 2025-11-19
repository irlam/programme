<?php
declare(strict_types=1);

/**
 * TaskList template generator.
 * - Uses PhpSpreadsheet
 * - Writes to /httpdocs/tmp first (fallback: system temp), then streams
 * - ?save=1  → copy to /templates/tasklist_template.xlsx (plain text result)
 * - ?debug=1 → show detailed PHP errors on screen
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

$root = dirname(__DIR__, 2); // /httpdocs
// Prefer your local /tmp under document root
$tmpDir = is_dir($root.'/tmp') && is_writable($root.'/tmp') ? $root.'/tmp' : sys_get_temp_dir();

// Load PhpSpreadsheet from manual autoloader
$autoload = $root . '/libs/autoload-phpss.php';
if (!is_file($autoload)) { http_response_code(500); exit('ERROR: autoload-phpss.php not found'); }
require $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

try {
  if (!class_exists(Spreadsheet::class)) throw new RuntimeException('PhpSpreadsheet not available');

  $ss = new Spreadsheet();
  $ws = $ss->getActiveSheet();
  $ws->setTitle('TaskList');

  // Headers
  $headers = [
    'Section','Activity','Contractor','Ops','Duration (days)',
    'Start (dd/mm/yyyy)','Finish (dd/mm/yyyy)',
    'Zone','DepType (FS/SS)','Predecessor Task','Lag (days)'
  ];
  $ws->fromArray([$headers], null, 'A1');
  $ws->getStyle('A1:K1')->getFont()->setBold(true);
  $ws->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
  $ws->getStyle('A1:K1')->getFont()->getColor()->setRGB('E5E7EB');
  $ws->getStyle('A1:K1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

  // Helper row
  $ws->fromArray([[
    'e.g. A 1 01','Short clear task name','Use names from Admin → Contractors','int',
    'working days (Mon–Fri)','optional','optional',
    'Bathroom/Core/Kitchen…','FS or SS','Task code or ID','± days'
  ]], null, 'A2');
  $ws->getStyle('A2:K2')->getFont()->getColor()->setRGB('93C5FD');

  // Example rows
  $rows = [
    ['A 1 01','BWH to structural walls','Panacea',2,3,'','','Core','','',''],
    ['A 1 01','SVP/RWP install','GPL',2,5,'','','Wetrooms','FS','BWH to structural walls',0],
    ['A 1 01','Fire stop to SVP/RWP','TECL',2,5,'','','Wetrooms','SS','SVP/RWP install',0],
  ];
  $ws->fromArray($rows, null, 'A4');

  // Widths + subtle grid
  $widths = [16,40,22,6,16,18,18,16,16,24,12];
  foreach ($widths as $i=>$w) $ws->getColumnDimensionByColumn($i+1)->setWidth($w);
  $lastRow = 4 + count($rows) - 1;
  $ws->getStyle("A1:K{$lastRow}")->getBorders()->getAllBorders()
     ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('1F2937');

  // Write to temp file (avoids ZipStream entirely)
  $fname = 'tasklist_template.xlsx';
  $tmp = rtrim($tmpDir, '/').'/'.$fname;
  (new Xlsx($ss))->save($tmp);

  // Save to /templates if requested
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

  // Stream download
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

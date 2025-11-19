<?php
declare(strict_types=1);

/*
  Template Endpoints Installer
  - Writes:
      /api/templates/lookahead.php
      /api/templates/tasklist.php
  - Creates /templates/ (writable) for optional saved copies (?save=1)
  - Uses your manual autoloader via /api/_bootstrap.php
*/

$ROOT = dirname(__DIR__);            // /httpdocs
$API  = $ROOT . '/api/templates';
$TPL  = $ROOT . '/templates';

function mkdir_p(string $p): bool { return is_dir($p) || @mkdir($p, 0775, true); }
function put(string $p, string $c): array { mkdir_p(dirname($p)); $ok=@file_put_contents($p,$c); return [$ok!==false, $ok?('wrote '.strlen($c).' bytes'):'FAIL']; }

$lookahead = <<<'PHP'
<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');
// Load autoloaders (manual or composer) via bootstrap if available
$boot = __DIR__ . '/../../api/_bootstrap.php';
if (is_file($boot)) { require $boot; }
else {
  $manual = __DIR__ . '/../../libs/autoload-phpss.php';
  if (is_file($manual)) require $manual;
}
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Conditional;

try {
  if (!class_exists(Spreadsheet::class)) {
    throw new RuntimeException('PhpSpreadsheet not available.');
  }

  $numDays = 42;                        // 6 weeks
  $firstCol = 5;                        // E
  $dayHeaderRow = 4;
  $dateRow = 5;
  $taskStartRow = 6;

  // Base date: next Monday (override via ?base=YYYY-MM-DD)
  $base = $_GET['base'] ?? null;
  if ($base) {
    $baseDate = new DateTimeImmutable($base);
  } else {
    $today = new DateTimeImmutable('today');
    $baseDate = ((int)$today->format('N') === 1) ? $today : $today->modify('next monday');
  }

  $ss = new Spreadsheet();
  $ws = $ss->getActiveSheet();
  $ws->setTitle('Lookahead');

  // Title + meta
  $endColLetter = Coordinate::stringFromColumnIndex($firstCol + $numDays - 1);
  $ws->mergeCells("A1:{$endColLetter}1");
  $ws->setCellValue('A1', '6-Week Lookahead (Template)');
  $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');

  $ws->setCellValue('B3', 'Date');
  $ws->getStyle('B3')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
  $ws->setCellValue('C3', $baseDate->format('Y-m-d'));
  $ws->getStyle('C3')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
  $ws->setCellValue('D3', 'Change C3 to shift the horizon');
  $ws->getStyle('D3')->getFont()->getColor()->setARGB('FF9CA3AF');

  // Fixed headers A–D
  $ws->setCellValue('A5', 'Section');
  $ws->setCellValue('B5', 'Activity');
  $ws->setCellValue('C5', 'Contractor');
  $ws->setCellValue('D5', 'Notes');
  foreach (['A5','B5','C5','D5'] as $addr) {
    $ws->getStyle($addr)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle($addr)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F2937');
    $ws->getStyle($addr)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF1F2937');
    $ws->getStyle($addr)->getAlignment()->setHorizontal('center')->setVertical('center');
  }

  // Column widths
  $ws->getColumnDimension('A')->setWidth(12);
  $ws->getColumnDimension('B')->setWidth(36);
  $ws->getColumnDimension('C')->setWidth(18);
  $ws->getColumnDimension('D')->setWidth(22);
  for ($i=0; $i<$numDays; $i++) {
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($firstCol + $i))->setWidth(3);
  }

  // Day headers + date formulas
  for ($i=0; $i<$numDays; $i++) {
    $colIdx = $firstCol + $i;
    $colLetter = Coordinate::stringFromColumnIndex($colIdx);
    // Weekday header
    $ws->setCellValueByColumnAndRow($colIdx, $dayHeaderRow, "=TEXT({$colLetter}{$dateRow},\"ddd\")");
    $ws->getStyle("{$colLetter}{$dayHeaderRow}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle("{$colLetter}{$dayHeaderRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F2937');
    $ws->getStyle("{$colLetter}{$dayHeaderRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF1F2937');
    $ws->getStyle("{$colLetter}{$dayHeaderRow}")->getAlignment()->setHorizontal('center')->setVertical('center');
    // Date row
    $ws->setCellValueByColumnAndRow($colIdx, $dateRow, $i === 0 ? '=$C$3' : "=\$C\$3+{$i}");
    $ws->getStyle("{$colLetter}{$dateRow}")->getNumberFormat()->setFormatCode('dd/mm');
    $ws->getStyle("{$colLetter}{$dateRow}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle("{$colLetter}{$dateRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF1F2937');
    $ws->getStyle("{$colLetter}{$dateRow}")->getAlignment()->setHorizontal('center')->setVertical('center');
  }

  // Conditional formatting to shade weekends across E:... rows
  $startLetter = Coordinate::stringFromColumnIndex($firstCol);
  $range = "{$startLetter}{$dayHeaderRow}:{$endColLetter}".($taskStartRow+200);
  $cond = new Conditional();
  $cond->setConditionType(Conditional::CONDITION_EXPRESSION);
  $cond->addCondition("=WEEKDAY({$startLetter}\${$dateRow},2)>=6"); // relative; shifts per column
  $cond->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D2139');
  $ws->getStyle($range)->setConditionalStyles([$cond]);

  // Freeze panes
  $ws->freezePane("E6");

  // Sample rows with coloured spans
  $samples = [
    ['Core','BWH to structural walls','Panacea', [[0,4]], 'FF60A5FA'],
    ['Wetrooms','SVP/RWP install','GPL', [[3,8]], 'FFF59E0B'],
    ['Core','Structural walls 1st fix','Edencroft', [[4,9]], 'FF34D399'],
    ['Ceilings','MF ceilings','Panacea', [[12,17]], 'FF60A5FA'],
    ['Fire','Sprinkler 1st fix','Armstrong', [[18,20]], 'FFF59E0B'],
  ];
  $r = $taskStartRow;
  foreach ($samples as [$sec,$act,$con,$spans,$hex]) {
    $ws->setCellValue("A{$r}", $sec);
    $ws->setCellValue("B{$r}", $act);
    $ws->setCellValue("C{$r}", $con);
    foreach ($spans as [$s,$e]) {
      for ($i=$s; $i<=$e; $i++) {
        $col = Coordinate::stringFromColumnIndex($firstCol + $i);
        $ws->getStyle("{$col}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($hex);
        $ws->getStyle("{$col}{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF1F2937');
      }
    }
    $r++;
  }

  // Legend
  $legRow = $taskStartRow + 6;
  $ws->mergeCells("A{$legRow}:D{$legRow}");
  $ws->setCellValue("A{$legRow}", "Fill cells to mark planned days. Weekend columns shade automatically. Leave gaps to create multiple spans.");
  $ws->getStyle("A{$legRow}")->getFont()->getColor()->setARGB('FF9CA3AF');

  // ReadMe
  $rm = $ss->createSheet();
  $rm->setTitle('ReadMe');
  $rm->setCellValue('A1','Lookahead Template – How to Use');
  $rm->getStyle('A1')->getFont()->setBold(true)->setSize(14);
  $rm->fromArray([
    [''],
    ['1) Edit base date in Lookahead!C3 (dd.mm.yyyy) to set the 6-week window.'],
    ['2) Enter rows: Section (A), Activity (B), Contractor (C), optional Notes (D).'],
    ['3) Mark planned work days by FILLING the day cells (E→). Any solid colour works.'],
    ['4) Weekends shade automatically; the importer can ignore weekends.'],
    ['5) Non-contiguous coloured blocks on a row become separate tasks.'],
    ['6) Save and upload via Admin → Import Programme → Preview → Import.'],
    [''],
    ['Notes'],
    ['• Keep sheet name "Lookahead" for auto-detection.'],
    ['• Add more day columns by copying the last date formula to the right.'],
    ['• Alternatively, use the TaskList template (simple table).'],
  ], null, 'A3');

  // Save or stream
  $save = isset($_GET['save']) && $_GET['save'] == '1';
  if ($save) {
    $dir = __DIR__ . '/../../templates';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/lookahead_template.xlsx';
    $writer = new Xlsx($ss);
    $writer->save($path);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Saved: /templates/lookahead_template.xlsx";
    exit;
  }

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="lookahead_template.xlsx"');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  $writer = new Xlsx($ss);
  $writer->save('php://output');
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'ERROR: ' . $e->getMessage();
}
PHP;

$tasklist = <<<'PHP'
<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');
$boot = __DIR__ . '/../../api/_bootstrap.php';
if (is_file($boot)) { require $boot; }
else {
  $manual = __DIR__ . '/../../libs/autoload-phpss.php';
  if (is_file($manual)) require $manual;
}
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

try {
  if (!class_exists(Spreadsheet::class)) {
    throw new RuntimeException('PhpSpreadsheet not available.');
  }

  $ss = new Spreadsheet();
  $ws = $ss->getActiveSheet();
  $ws->setTitle('TaskList');

  // Headers
  $headers = ['Task','Contractor','Start','Finish','Duration (days)','Section','Zone','Ops'];
  $ws->fromArray([$headers], null, 'A1');
  $ws->getStyle('A1:H1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
  $ws->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F2937');
  $ws->getStyle('A1:H1')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF1F2937');

  // Sample rows
  $rows = [
    ['BWH to structural walls','Panacea','','','3','Core','','4'],
    ['SVP/RWP install','GPL','','','5','Wetrooms','','2'],
    ['Sprinkler 1st fix','Armstrong','','','3','Ceilings','','2'],
  ];
  $ws->fromArray($rows, null, 'A2');

  // Widths
  $ws->getColumnDimension('A')->setWidth(36);
  $ws->getColumnDimension('B')->setWidth(20);
  $ws->getColumnDimension('C')->setWidth(12);
  $ws->getColumnDimension('D')->setWidth(12);
  $ws->getColumnDimension('E')->setWidth(16);
  $ws->getColumnDimension('F')->setWidth(16);
  $ws->getColumnDimension('G')->setWidth(16);
  $ws->getColumnDimension('H')->setWidth(8);

  // ReadMe sheet
  $rm = $ss->createSheet();
  $rm->setTitle('ReadMe');
  $rm->setCellValue('A1','Task List Template – How to Use');
  $rm->getStyle('A1')->getFont()->setBold(true)->setSize(14);
  $rm->fromArray([
    [''],
    ['1) Fill rows with tasks; either provide Start/Finish or Duration (days).'],
    ['2) If Start+Finish present, duration is computed by importer using working days.'],
    ['3) Leave Zone/Section blank if not used; Ops = headcount.'],
    ['4) Save and upload via Admin → Import Programme → Preview → Import.'],
  ], null, 'A3');

  // Save or stream
  $save = isset($_GET['save']) && $_GET['save'] == '1';
  if ($save) {
    $dir = __DIR__ . '/../../templates';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/tasklist_template.xlsx';
    $writer = new Xlsx($ss);
    $writer->save($path);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Saved: /templates/tasklist_template.xlsx";
    exit;
  }

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="tasklist_template.xlsx"');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  $writer = new Xlsx($ss);
  $writer->save('php://output');
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'ERROR: ' . $e->getMessage();
}
PHP;

$rows = [];
$rows[] = ["/api/templates/", mkdir_p($API) ? "OK" : "FAIL"];
$rows[] = ["/templates/", mkdir_p($TPL) ? "OK" : "FAIL"];
[$ok1,$m1] = put($API . '/lookahead.php', $lookahead);   $rows[] = ["/api/templates/lookahead.php", $ok1 ? "OK ($m1)" : "FAIL ($m1)"];
[$ok2,$m2] = put($API . '/tasklist.php',  $tasklist);    $rows[] = ["/api/templates/tasklist.php",  $ok2 ? "OK ($m2)" : "FAIL ($m2)"];

?><!doctype html>
<meta charset="utf-8">
<title>Template Endpoints Installer</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:16px}
 table{border-collapse:collapse} td{padding:6px 10px;border-bottom:1px solid #1f2937}
 .ok{color:#86efac}.fail{color:#fca5a5}
 .pill{background:#1f2937;border:1px solid #1f2937;padding:6px 10px;border-radius:999px;color:#e5e7eb;text-decoration:none}
</style>
<h2>Template Endpoints Installer</h2>
<table>
  <?php foreach ($rows as [$a,$s]): ?>
    <tr><td><?=htmlspecialchars($a)?></td><td class="<?=stripos($s,'ok')!==false?'ok':'fail'?>"><?=htmlspecialchars($s)?></td></tr>
  <?php endforeach; ?>
</table>
<p>
  Try now:
  <a class="pill" href="/api/templates/tasklist.php">TaskList (download)</a>
  <a class="pill" href="/api/templates/tasklist.php?save=1">TaskList (save to /templates)</a>
  <a class="pill" href="/api/templates/lookahead.php">Lookahead (download)</a>
  <a class="pill" href="/api/templates/lookahead.php?save=1">Lookahead (save to /templates)</a>
</p>
<p style="opacity:.8">If you see an ERROR, open <code>/admin/phpss_check.php</code>. Delete this installer when finished.</p>

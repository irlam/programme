<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

$pdo = DB::pdo();
$project = (int)($_GET['project'] ?? 1);

// Load project start for offsets if needed
$proj = $pdo->prepare("SELECT start_date FROM projects WHERE id=?"); $proj->execute([$project]);
$projectStart = $proj->fetchColumn() ?: date('Y-m-d');

// Tasks + contractors + apartments
$sql = "SELECT t.*, c.name AS contractor, c.colour, a.block, a.floor, a.unit, a.type AS apartment_type
        FROM tasks t
        LEFT JOIN contractors c ON c.id = t.contractor_id
        JOIN apartments a ON a.id=t.apartment_id
        WHERE t.project_id = ?
        ORDER BY t.start_date IS NULL, t.start_date, t.id";
$st = $pdo->prepare($sql); $st->execute([$project]);
$rows = $st->fetchAll();

$wantXlsx = class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet');

if ($wantXlsx) {
  // Build .xlsx with a compact gantt band
  $spread = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spread->getActiveSheet(); $sheet->setTitle('Programme');

  $headers = ['Block','Floor','Unit','Apt Type','Task','Contractor','Ops','Dur (d)','Start','Finish','Flags'];
  $col = 1;
  foreach ($headers as $h) { $sheet->setCellValueByColumnAndRow($col++, 1, $h); }
  // timeline from project start for 120 working-ish days (simple 1 col per day)
  $timelineCols = 120;
  for ($i=0;$i<$timelineCols;$i++) {
    $sheet->setCellValueByColumnAndRow($col+$i, 1, $i+1);
    $sheet->getColumnDimensionByColumn($col+$i)->setWidth(2.5);
  }

  $r = 2;
  foreach ($rows as $row) {
    $flags = [];
    if (!empty($row['alerts_json'])) {
      $a = json_decode($row['alerts_json'], true) ?: [];
      if (!empty($a['tight'])) $flags[] = 'Tight';
      if (!empty($a['overlap_with'])) $flags[] = 'Overlap';
    }
    $data = [
      $row['block'],$row['floor'],$row['unit'],$row['apartment_type'],$row['name'],
      $row['contractor'],$row['operatives'],$row['duration_days'],$row['start_date'],$row['finish_date'],implode(', ',$flags)
    ];
    $c=1; foreach ($data as $v) $sheet->setCellValueByColumnAndRow($c++, $r, $v);

    // compact band: offset = max(0, days from project start to task start), len = duration_days
    $start = $row['start_date'] ?: $projectStart;
    $dur = max(1, (int)$row['duration_days']);
    $offset = (new DateTime($projectStart))->diff(new DateTime($start))->days;
    $offset = max(0, min($timelineCols-1, $offset));
    for ($i=0; $i<$dur && ($offset+$i)<$timelineCols; $i++) {
      $sheet->getStyleByColumnAndRow($c+$offset+$i, $r)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('5CA0FA'); // blue bar
    }
    $r++;
  }

  // headers + output
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="programme.xlsx"');
  $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spread);
  $writer->save('php://output');
  exit;
}

// CSV fallback
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="programme.csv"');
$out = fopen('php://output','w');
fputcsv($out, ['Block','Floor','Unit','Apt Type','Task','Contractor','Ops','Dur (d)','Start','Finish','Flags']);
foreach ($rows as $row) {
  $flags = [];
  if (!empty($row['alerts_json'])) {
    $a = json_decode($row['alerts_json'], true) ?: [];
    if (!empty($a['tight'])) $flags[] = 'Tight';
    if (!empty($a['overlap_with'])) $flags[] = 'Overlap';
  }
  fputcsv($out, [
    $row['block'],$row['floor'],$row['unit'],$row['apartment_type'],$row['name'],
    $row['contractor'],$row['operatives'],$row['duration_days'],$row['start_date'],$row['finish_date'],implode(', ',$flags)
  ]);
}
fclose($out);

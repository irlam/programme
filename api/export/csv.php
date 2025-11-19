<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';
use App\Config\DB;

$pdo = DB::pdo();
$project = (int)($_GET['project'] ?? 1);
$block   = $_GET['block'] ?? null;
$floor   = $_GET['floor'] ?? null;
$unit    = $_GET['unit'] ?? null;
$contractor = $_GET['contractor'] ?? null;
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;

$q = [];
$w = ["t.project_id = ?"]; $q[] = $project;
if ($block !== null && $block !== '') { $w[]="a.block = ?"; $q[]=$block; }
if ($floor !== null && $floor !== '') { $w[]="a.floor = ?"; $q[]=$floor; }
if ($unit  !== null && $unit  !== '') { $w[]="a.unit = ?";  $q[]=$unit; }
if ($contractor !== null && $contractor !== '') { $w[]="c.name = ?"; $q[]=$contractor; }
if ($from) { $w[]="t.finish_date >= ?"; $q[]=$from; }
if ($to)   { $w[]="t.start_date <= ?";  $q[]=$to; }

$sql = "SELECT a.block,a.floor,a.unit,a.type apt_type,
               t.name task, c.name contractor, t.operatives, t.duration_days,
               t.start_date, t.finish_date, t.zone
        FROM tasks t
        JOIN apartments a ON a.id=t.apartment_id
        LEFT JOIN contractors c ON c.id=t.contractor_id
        WHERE ".implode(' AND ', $w)."
        ORDER BY a.block, a.floor, a.unit, t.start_date, t.id";
$st = $pdo->prepare($sql);
$st->execute($q);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="programme_export.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Block','Floor','Unit','Apartment Type','Task','Contractor','Ops','Duration (days)','Start','Finish','Zone']);
while ($r = $st->fetch()) {
  fputcsv($out, [$r['block'],$r['floor'],$r['unit'],$r['apt_type'],$r['task'],$r['contractor'],$r['operatives'],$r['duration_days'],$r['start_date'],$r['finish_date'],$r['zone']]);
}
fclose($out);

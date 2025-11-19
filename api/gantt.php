<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
use App\Config\DB;
header('Content-Type: application/json');
$projectId = (int)($_GET['project'] ?? 1);
$from = $_GET['from'] ?? null; $to = $_GET['to'] ?? null;
$pdo = DB::pdo();
$apts = $pdo->prepare("SELECT id, block, floor, unit, type FROM apartments WHERE project_id=? ORDER BY block, floor, unit");
$apts->execute([$projectId]);
$rows = [];
while ($a = $apts->fetch()) {
  $label = trim(implode(' ', array_filter([$a['block'], $a['floor'], $a['unit'], $a['type']])));
  $rows[] = ['id' => 'apt-'.$a['id'], 'name' => $label ?: ('Apartment #'.$a['id'])];
}
$sql = "SELECT t.id, t.name, t.start_date, t.finish_date, t.apartment_id, c.colour, ct.name AS contractor
        FROM tasks t
        LEFT JOIN contractors c ON c.id = t.contractor_id
        LEFT JOIN contractors ct ON ct.id = t.contractor_id
        WHERE t.project_id = ?";
$params = [$projectId];
if ($from) { $sql .= " AND t.finish_date >= ?"; $params[] = $from; }
if ($to)   { $sql .= " AND t.start_date <= ?";  $params[] = $to; }
$sql .= " ORDER BY t.start_date IS NULL, t.start_date, t.id";
$st = $pdo->prepare($sql); $st->execute($params);
$events = [];
while ($t = $st->fetch()) {
  if (!$t['start_date'] || !$t['finish_date']) continue;
  $events[] = [
    'id' => (string)$t['id'],
    'text' => $t['name'],
    'start' => $t['start_date'].'T08:00:00',
    'end' => $t['finish_date'].'T17:00:00',
    'resource' => 'apt-'.$t['apartment_id'],
    'barColor' => $t['colour'] ?: '#6B7280',
    'tags' => ['contractor' => $t['contractor']]
  ];
}
echo json_encode(['resources' => $rows, 'events' => $events]);

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/app/suite-prepend.php';

require __DIR__ . '/_bootstrap.php';
use App\Config\DB;
header('Content-Type: application/json');
header('Cache-Control: no-store');
$pdo = DB::pdo();
$projects = $pdo->query('SELECT id, name, start_date, timezone FROM projects ORDER BY name')->fetchAll();
$projectId = max(1, (int)($_GET['project'] ?? ($projects[0]['id'] ?? 1)));
$project = null;
foreach ($projects as $item) if ((int)$item['id'] === $projectId) $project = $item;
if (!$project) { http_response_code(404); echo json_encode(['ok'=>false, 'error'=>'Project not found']); exit; }
$stmt = $pdo->prepare('SELECT t.*, c.name AS contractor, c.colour, a.block, a.floor, a.unit, a.type AS apt_type FROM tasks t LEFT JOIN contractors c ON c.id=t.contractor_id LEFT JOIN apartments a ON a.id=t.apartment_id WHERE t.project_id=? ORDER BY a.block, a.floor, a.unit, t.start_date, t.id');
$stmt->execute([$projectId]);
$tasks = $stmt->fetchAll();
$contractors = $pdo->query('SELECT id, name, colour FROM contractors ORDER BY name')->fetchAll();
$zone = new DateTimeZone($project['timezone'] ?: 'Europe/London');
$today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
foreach ($tasks as &$task) {
    foreach (['id','project_id','apartment_id','operatives','duration_days','percent_complete','is_milestone'] as $field) $task[$field] = (int)$task[$field];
    $task['alerts'] = json_decode($task['alerts_json'] ?? '', true) ?: [];
    unset($task['alerts_json']);
    $task['status'] = $task['percent_complete'] >= 100 ? 'complete' : (!$task['start_date'] || !$task['finish_date'] ? 'unscheduled' : ($task['finish_date'] < $today ? 'delayed' : ($task['start_date'] <= $today ? 'in_progress' : 'planned')));
}
unset($task);
echo json_encode(['ok'=>true, 'projects'=>$projects, 'project'=>$project, 'today'=>$today, 'tasks'=>$tasks, 'contractors'=>$contractors]);

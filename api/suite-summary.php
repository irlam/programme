<?php
declare(strict_types=1);
require __DIR__.'/_suite-reporting.php';
$reference = $_GET['project'] ?? null;
if (!is_string($reference) || !preg_match('/^[1-9][0-9]{0,9}$/D',$reference)) programme_suite_fail(400,'project_required');
try {
    $project = $reportDb->prepare('SELECT id FROM projects WHERE id=?');
    $project->execute([$reference]);
    if (!$project->fetchColumn()) programme_suite_fail(404,'project_not_found');
    $query = $reportDb->prepare('SELECT percent_complete,finish_date FROM tasks WHERE project_id=?');
    $query->execute([$reference]);
    $metrics = ['total'=>0,'outstanding'=>0,'completed'=>0,'overdue'=>0];
    $today = date('Y-m-d');
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $metrics['total']++;
        if ((int)$task['percent_complete'] >= 100) { $metrics['completed']++; }
        else {
            $metrics['outstanding']++;
            if ($task['finish_date'] && $task['finish_date'] < $today) $metrics['overdue']++;
        }
    }
    echo json_encode(['ok'=>true,'module'=>'programme','scope'=>['project'=>(int)$reference],'metrics'=>$metrics]);
} catch (Throwable $error) { programme_suite_fail(503,'summary_unavailable'); }

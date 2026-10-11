<?php
declare(strict_types=1);
// CLI-only, explicitly labelled synthetic programme; no notifications or user changes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || ($argv[1] ?? '') !== '--rochdale-programme') {
    fwrite(STDERR, "Use --rochdale-programme.\n"); exit(2);
}
ini_set('display_errors', '0');
require dirname(__DIR__) . '/app/config/DB.php';
try {
    $pdo = \App\Config\DB::pdo();
    $pdo->beginTransaction();
    $name = 'Rochdale Road — Suite test programme';
    $query = $pdo->prepare('SELECT id FROM projects WHERE name=? ORDER BY id');
    $query->execute([$name]); $ids = $query->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) > 1) throw new RuntimeException('Duplicate fixture');
    $created = !$ids;
    if ($created) {
        $calendar = $pdo->query('SELECT id FROM calendars ORDER BY id LIMIT 1')->fetchColumn();
        if (!$calendar) throw new RuntimeException('Missing calendar');
        $query = $pdo->prepare('INSERT INTO projects(name,start_date,timezone,calendar_id) VALUES(?,?,?,?)');
        $query->execute([$name,'2026-10-12','Europe/London',$calendar]);
        $project = (int)$pdo->lastInsertId();
        $query = $pdo->prepare('INSERT INTO apartments(project_id,block,floor,unit,type) VALUES(?,?,?,?,?)');
        $query->execute([$project,'TEST ONLY','Ground','Demo area','Synthetic fixture']);
        $apartment = (int)$pdo->lastInsertId();
        $query = $pdo->prepare('INSERT INTO tasks(project_id,apartment_id,name,operatives,duration_days,percent_complete,start_date,finish_date,notes) VALUES(?,?,?,1,5,0,?,?,?)');
        foreach (['Electrical first fix','Dry lining','Electrical second fix'] as $index => $task) {
            $start = (new DateTimeImmutable('2026-10-12'))->modify('+'.($index*7).' days');
            $query->execute([$project,$apartment,'TEST — '.$task,$start->format('Y-m-d'),$start->modify('+4 days')->format('Y-m-d'),'Synthetic Suite test activity; not an agreed construction programme.']);
        }
    } else { $project = (int)$ids[0]; }
    $pdo->commit();
    echo json_encode(['ok'=>true,'project_id'=>$project,'project'=>$name,'created'=>$created,'emails_sent'=>0,'users_changed'=>0], JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Programme test fixture could not be prepared.\n"); exit(1);
}

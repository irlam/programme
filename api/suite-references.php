<?php
declare(strict_types=1);
require __DIR__.'/_suite-reporting.php';
try {
    $items = [];
    foreach ($reportDb->query('SELECT id,name FROM projects ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = ['value'=>(string)$row['id'],'label'=>(string)$row['name']];
    }
    echo json_encode(['ok'=>true,'module'=>'programme','reference_type'=>'project','items'=>$items],JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) { programme_suite_fail(503,'references_unavailable'); }

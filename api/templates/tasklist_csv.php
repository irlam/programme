<?php
declare(strict_types=1);
// /api/templates/tasklist_csv.php
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="programme_tasklist_template.csv"');
$out = fopen('php://output','w');
fputcsv($out, ['Block','Floor','Unit','Apartment Type','Task','Contractor','Ops','Duration (wd)','Start','Finish','Zone','Predecessor','LinkType (FS/SS)','LagDays','ConstraintStart (SNET)','Notes']);
fclose($out);

<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
use App\Config\DB;
use App\Lib\Programmes;
header('Content-Type: application/json');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);echo '{"ok":false}';exit;}
require_role(['admin','planner','commenter','viewer']);
$db=DB::pdo();$projects=$db->query('SELECT id,name FROM projects ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$value=$_GET['project']??($projects[0]['id']??0);$project=(int)$value;
if(!preg_match('/^[1-9][0-9]*$/D',(string)$value)||!in_array($project,array_map('intval',array_column($projects,'id')),true)){http_response_code(404);echo '{"ok":false,"error":"Project not found"}';exit;}
echo json_encode(['ok'=>true,'projects'=>$projects,'project'=>$project,'programmes'=>Programmes::catalogue($db,$project)]);

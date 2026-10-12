<?php
declare(strict_types=1);
// Only the disposable CI service. Never connect this test to a hosted project DB.
require dirname(__DIR__).'/app/Lib/Programmes.php';
use App\Lib\Programmes;
if(getenv('PROGRAMME_MYSQL_FIXTURE')!=='1')throw new RuntimeException('Dedicated fixture required');
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=programme_catalogue_test;charset=utf8mb4','programme_fixture','fixture-only-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Fixture database must be empty');
$db->exec('CREATE TABLE projects(id INT PRIMARY KEY,name VARCHAR(150));CREATE TABLE users(id INT PRIMARY KEY);CREATE TABLE imports(id INT AUTO_INCREMENT PRIMARY KEY,project_id INT NOT NULL,filename VARCHAR(255),mapping_json JSON,created_by INT,created_at DATETIME,FOREIGN KEY(project_id) REFERENCES projects(id),FOREIGN KEY(created_by) REFERENCES users(id));CREATE TABLE tasks(id INT AUTO_INCREMENT PRIMARY KEY,project_id INT NOT NULL,name VARCHAR(190),source_import_id INT NULL,FOREIGN KEY(project_id) REFERENCES projects(id),FOREIGN KEY(source_import_id) REFERENCES imports(id) ON DELETE SET NULL)');
$db->exec("INSERT INTO projects VALUES(1,'Bound site'),(2,'Other site');INSERT INTO users VALUES(1);INSERT INTO imports(project_id,filename,mapping_json,created_by,created_at) VALUES(1,'historical.csv','{}',1,NOW());INSERT INTO tasks(project_id,name,source_import_id) VALUES(1,'Original',1)");
$db->beginTransaction();$a=Programmes::create($db,1,'Contract Rev A','a.csv',1);$b=Programmes::create($db,1,'Contract Rev B','b.csv',1);$db->commit();
$q=$db->prepare('INSERT INTO tasks(project_id,name,source_import_id) VALUES(1,?,?)');$q->execute(['A only',$a]);$q->execute(['B only',$b]);
foreach([0=>'Original',$a=>'A only',$b=>'B only']as$id=>$name){$q=$db->query('SELECT name FROM tasks t WHERE project_id=1 AND '.Programmes::condition($db,1,$id));if($q->fetchAll(PDO::FETCH_COLUMN)!==[$name])throw new RuntimeException('Wrong programme scope');}
if(count(Programmes::catalogue($db,1))!==3||count(Programmes::catalogue($db,2))!==1)throw new RuntimeException('Catalogue crossed project boundary');
$denied=false;try{Programmes::selected($db,2,$a);}catch(RuntimeException $e){$denied=true;}if(!$denied)throw new RuntimeException('Foreign project selected');
$db->beginTransaction();Programmes::create($db,1,'Rolled back','rollback.csv',1);$db->rollBack();if(count(Programmes::catalogue($db,1))!==3)throw new RuntimeException('Rollback failed');
echo "PASS: native MySQL JSON catalogue, existing import preservation, programme/project scope, foreign-key-compatible creation and rollback.\n";

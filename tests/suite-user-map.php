<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Lib/SuiteUserMap.php';
use App\Lib\SuiteUserMap;
$check = static function(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: '.$label); };
$deny = static function(callable $fn, string $label) use ($check): void {
    try { $fn(); } catch (RuntimeException $e) { return; }
    $check(false,$label);
};
$binding=['instance_id'=>1,'organization_id'=>2,'project_id'=>3,'local_project_id'=>7];
$fixture = static function(array $b): PDO {
    $pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY); INSERT INTO projects VALUES (7);
        CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,email TEXT UNIQUE,role TEXT,password_hash TEXT,confirmed INTEGER);
        CREATE TABLE suite_instance_binding (id INTEGER PRIMARY KEY CHECK(id=1),instance_id INTEGER,organization_id INTEGER,project_id INTEGER,local_project_id INTEGER);
        CREATE TABLE suite_user_map (suite_user_id INTEGER PRIMARY KEY,local_user_id INTEGER UNIQUE REFERENCES users(id));');
    $st=$pdo->prepare('INSERT INTO suite_instance_binding VALUES (1,?,?,?,?)');$st->execute(array_values($b));
    return $pdo;
};
$pdo=$fixture($binding);$mapper=new SuiteUserMap($pdo,$binding);
$identity=$binding+['module_key'=>'programme','user_id'=>12,'name'=>'Fixture user','email'=>'same@example.test',
    'role'=>'manager','local_role'=>'planner','session_expires_at'=>time()+3600];
$pdo->exec("INSERT INTO users VALUES (1,'Existing local admin','same@example.test','admin','unchanged-existing-hash',1)");
$first=$mapper->resolve($identity);
$check($first['id']!==1 && $first['role']==='planner','Email collision cannot adopt the existing administrator');
$identity['email']='changed@example.test';$identity['name']='Changed display name';
$identity['role']='contractor';$identity['local_role']='commenter';
$second=$mapper->resolve($identity);
$check($first['id']===$second['id'] && $second['role']==='commenter','Immutable Suite ID mapping and current role downgrade');
$check($second['email']===$first['email'],'Mutable email cannot rebind local identity');
$old=$pdo->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$check($old['role']==='admin' && $old['password_hash']==='unchanged-existing-hash','Unrelated local account remains untouched');
$check((int)$pdo->query('SELECT COUNT(*) FROM suite_user_map')->fetchColumn()===1,'Repeated mapping does not duplicate users');
foreach (['instance_id','organization_id','project_id','local_project_id'] as $field) {
    $bad=$identity;$bad[$field]++;
    $deny(fn()=>$mapper->resolve($bad),'Foreign '.$field.' is rejected');
}
foreach (['role'=>'unknown','local_role'=>'admin','module_key'=>'permits','session_expires_at'=>time()-1,'user_id'=>'12','name'=>"\xFF"] as $field=>$value) {
    $bad=$identity;$bad[$field]=$value;
    $deny(fn()=>$mapper->resolve($bad),'Invalid current identity '.$field.' is rejected');
}
$bad=$identity;$bad['user_id']=13;
$pdo->exec("CREATE TRIGGER refuse_fixture_map BEFORE INSERT ON suite_user_map BEGIN SELECT RAISE(ABORT,'fixture write failure'); END;");
$count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$deny(fn()=>$mapper->resolve($bad),'Mapping failure denies access');
$check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===$count && !$pdo->inTransaction(),'Failed mapping rolls back its new local user');
$pdo->exec('DROP TRIGGER refuse_fixture_map; UPDATE suite_instance_binding SET organization_id=99');
$deny(fn()=>$mapper->resolve($identity),'Wrong database binding rejected');
$pdo->exec('DELETE FROM suite_instance_binding');
$deny(fn()=>$mapper->resolve($identity),'Missing database binding rejected');
$betaBinding=$binding;$betaBinding['instance_id']=2;$betaBinding['organization_id']=4;$betaBinding['project_id']=5;
$beta=$fixture($betaBinding);$betaMapper=new SuiteUserMap($beta,$betaBinding);
$deny(fn()=>$betaMapper->resolve($identity),'Alpha identity cannot provision a Beta account');
$betaIdentity=array_replace($identity,$betaBinding);
$betaUser=$betaMapper->resolve($betaIdentity);
$check($betaUser['email']!==$first['email'],'Separate instances use different local synthetic identifiers');
$deny(fn()=>$beta->exec('DELETE FROM users WHERE id='.$betaUser['id']),'Foreign key protects mapped account');
$beta->exec('PRAGMA foreign_keys=OFF; DELETE FROM users WHERE id='.$betaUser['id']);
$deny(fn()=>$betaMapper->resolve($betaIdentity),'Dangling mapping fails closed');
echo "PASS: immutable Suite IDs, email collisions, current roles, foreign bindings, expiry and transactional rollback.\n";

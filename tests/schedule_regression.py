"""Exercise PHP endpoint logic against an isolated SQLite fixture; never uses live config."""
import json, os, pathlib, shutil, sqlite3, subprocess, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PROGRAMME_TEST_PHP', 'php')
PHP_ARGS = json.loads(os.environ.get('PROGRAMME_TEST_PHP_ARGS', '[]'))
with tempfile.TemporaryDirectory(prefix='programme-test-') as folder:
    base = pathlib.Path(folder)
    for path in ['api', 'app/Lib']:
        shutil.copytree(ROOT / path, base / path)
    (base / 'app/config').mkdir(parents=True)
    (base / 'app/config/config.php').write_text('<?php date_default_timezone_set("Europe/London");')
    (base / 'app/config/DB.php').write_text('''<?php namespace App\\Config;
final class DB { private static $pdo; public static function pdo(): \\PDO {
return self::$pdo ??= new \\PDO('sqlite:'.dirname(__DIR__, 2).'/fixture.sqlite', null, null, [\\PDO::ATTR_ERRMODE=>\\PDO::ERRMODE_EXCEPTION,\\PDO::ATTR_DEFAULT_FETCH_MODE=>\\PDO::FETCH_ASSOC]); } }
''')
    db = sqlite3.connect(base / 'fixture.sqlite')
    db.executescript('''
CREATE TABLE projects (id INTEGER PRIMARY KEY,name TEXT,start_date TEXT,timezone TEXT,calendar_id INTEGER);
INSERT INTO projects VALUES(1,'Test project','2026-10-01','Europe/London',1);
CREATE TABLE calendars(id INTEGER PRIMARY KEY,workdays_json TEXT);
INSERT INTO calendars VALUES(1,'{"mon":1,"tue":1,"wed":1,"thu":1,"fri":1,"sat":0,"sun":0}');
CREATE TABLE calendar_holidays(calendar_id INTEGER,date TEXT,is_working INTEGER);
INSERT INTO calendar_holidays VALUES(1,'2026-10-07',0);
CREATE TABLE apartments(id INTEGER PRIMARY KEY,project_id INTEGER,block TEXT,floor TEXT,unit TEXT,type TEXT);
INSERT INTO apartments VALUES(1,1,'A','1','01','Type A');
CREATE TABLE contractors(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,colour TEXT);
INSERT INTO contractors VALUES(1,'Brickwork','#559584');
CREATE TABLE tasks(id INTEGER PRIMARY KEY,project_id INTEGER,apartment_id INTEGER,name TEXT,contractor_id INTEGER,operatives INTEGER,duration_days INTEGER,zone TEXT,constraint_start TEXT,percent_complete INTEGER,is_milestone INTEGER,start_date TEXT,finish_date TEXT,slack_days INTEGER,baseline_start TEXT,baseline_finish TEXT,alerts_json TEXT,source_import_id INTEGER,notes TEXT);
INSERT INTO tasks VALUES(1,1,1,'Blockwork',1,4,3,'Apartment','2026-10-05',20,0,'2026-10-05','2026-10-09',NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO tasks VALUES(2,1,1,'Joinery',NULL,2,2,'Apartment',NULL,0,0,'2026-10-09','2026-10-13',NULL,NULL,NULL,NULL,NULL,NULL);
CREATE TABLE dependencies(task_id INTEGER,predecessor_id INTEGER,type TEXT,lag_days INTEGER);
INSERT INTO dependencies VALUES(2,1,'FS',0);
''')
    db.commit()
    # Adapt only HTTP's input transport to CLI stdin; endpoint business logic is unchanged.
    tasks_file = base / 'api/tasks.php'
    tasks_file.write_text(tasks_file.read_text().replace("file_get_contents('php://input')", "file_get_contents('php://stdin')"))
    (base / 'runner.php').write_text("""<?php
    session_save_path(__DIR__); session_start(); $_SESSION['csrf']='fixture-token';
    $_SESSION['user']=['id'=>1,'name'=>'Test planner','role'=>getenv('FIXTURE_ROLE') ?: 'planner'];
    $_SERVER['REQUEST_METHOD']=getenv('FIXTURE_METHOD'); $_SERVER['HTTP_X_CSRF']=getenv('FIXTURE_CSRF');
    $path=parse_url($argv[1], PHP_URL_PATH); parse_str(parse_url($argv[1], PHP_URL_QUERY) ?: '', $_GET);
    ob_start(); register_shutdown_function(function(){ $body=ob_get_clean(); echo json_encode(['status'=>http_response_code() ?: 200,'body'=>json_decode($body,true),'raw'=>$body]); });
    require __DIR__.$path;
    """)
    role = 'planner'
    def request(path, body=None, csrf='fixture-token'):
        if path.startswith('/test-session'):
            return 200, {'ok':True}
        env = {**os.environ,'FIXTURE_ROLE':role,'FIXTURE_METHOD':'GET' if body is None else 'POST','FIXTURE_CSRF':csrf}
        result = subprocess.run([PHP, *PHP_ARGS, str(base/'runner.php'), path],input='' if body is None else json.dumps(body),text=True,capture_output=True,env=env)
        try: data = json.loads(result.stdout)
        except json.JSONDecodeError: raise RuntimeError((result.returncode,result.stdout,result.stderr))
        assert data['body'] is not None, (result.stderr, data['raw'])
        return data['status'], data['body']
    def update(**kwargs): return request('/api/tasks.php?action=update', {'id':1,'name':'Blockwork',**kwargs})
    try:
        status, data = request('/api/workspace.php')
        assert status == 200 and len(data['tasks']) == 2 and data['project']['name'] == 'Test project'
        assert request('/api/workspace.php?project=999')[0] == 404
        assert update(percent_complete=55)[0] == 200
        assert db.execute('SELECT percent_complete FROM tasks WHERE id=1').fetchone()[0] == 55
        assert update(start_date='05/10/2026',finish_date='09/10/2026')[0] == 200
        assert db.execute('SELECT duration_days,finish_date FROM tasks WHERE id=1').fetchone() == (3,'2026-10-09'), 'resize must preserve scheduler duration across holiday'
        assert db.execute('SELECT start_date FROM tasks WHERE id=2').fetchone()[0] == '2026-10-09', 'linked activity should recalculate'
        assert update(start_date='2026-10-06')[0] == 200
        assert db.execute('SELECT duration_days,finish_date FROM tasks WHERE id=1').fetchone() == (3,'2026-10-12'), 'move must retain duration across weekend and holiday'
        assert update(contractor_name='',constraint_start=None)[0] == 200
        assert db.execute('SELECT contractor_id,constraint_start FROM tasks WHERE id=1').fetchone() == (None,None)
        assert update(percent_complete=101)[0] == 400
        assert update(duration_days=-1)[0] == 400
        assert update(constraint_start='2026-02-30')[0] == 400
        assert update(start_date='2026-10-09',finish_date='2026-10-01')[0] == 400
        assert request('/api/tasks.php?action=update', {'id':1,'name':'Blockwork'}, csrf='wrong')[0] == 419
        role = 'viewer'
        assert update(percent_complete=80)[0] == 403
        role = 'planner'
        old = db.execute('SELECT name,percent_complete FROM tasks WHERE id=1').fetchone()
        db.execute("INSERT INTO dependencies VALUES(1,2,'FS',0)"); db.commit()
        assert update(name='Changed name', contractor_name='New contractor',percent_complete=80)[0] == 400
        assert db.execute('SELECT name,percent_complete FROM tasks WHERE id=1').fetchone() == old, 'failed recalc must roll back activity change'
        assert db.execute("SELECT count(*) FROM contractors WHERE name='New contractor'").fetchone()[0] == 0, 'failed recalc must roll back contractor creation'
        print('PASS: workspace, project selection, progress, resize/holiday, duration-preserving move/weekend, dependencies, clearing fields, invalid input, CSRF, roles, transactional rollback')
    finally:
        db.close()

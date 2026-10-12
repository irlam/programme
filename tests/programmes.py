"""Exercise named-programme import/read/edit/export against a disposable database."""
import json,os,pathlib,shutil,sqlite3,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PROGRAMME_TEST_PHP','php')
with tempfile.TemporaryDirectory(prefix='programme-catalogue-')as folder:
 base=pathlib.Path(folder)
 for path in ['api','app/Lib']:shutil.copytree(ROOT/path,base/path)
 (base/'app/config').mkdir(parents=True)
 (base/'app/config/config.php').write_text('<?php date_default_timezone_set("Europe/London");')
 (base/'app/config/DB.php').write_text('''<?php namespace App\\Config;final class DB{public static function pdo():\\PDO{static $db;return $db??=new \\PDO('sqlite:'.dirname(__DIR__,2).'/fixture.sqlite',null,null,[\\PDO::ATTR_ERRMODE=>\\PDO::ERRMODE_EXCEPTION,\\PDO::ATTR_DEFAULT_FETCH_MODE=>\\PDO::FETCH_ASSOC]);}}''')
 db=sqlite3.connect(base/'fixture.sqlite')
 db.executescript('''CREATE TABLE projects(id INTEGER PRIMARY KEY,name TEXT,start_date TEXT,timezone TEXT,calendar_id INTEGER);
INSERT INTO projects VALUES(1,'Bound site','2026-10-01','Europe/London',1),(2,'Other project','2026-10-01','Europe/London',1);
CREATE TABLE users(id INTEGER PRIMARY KEY);INSERT INTO users VALUES(1);
CREATE TABLE imports(id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,filename TEXT,mapping_json TEXT,created_by INTEGER,created_at TEXT);
INSERT INTO imports VALUES(50,1,'historical.csv','{}',1,'2025-01-01');
CREATE TABLE calendars(id INTEGER PRIMARY KEY,workdays_json TEXT);INSERT INTO calendars VALUES(1,'{"mon":1,"tue":1,"wed":1,"thu":1,"fri":1,"sat":0,"sun":0}');
CREATE TABLE calendar_holidays(calendar_id INTEGER,date TEXT,is_working INTEGER);
CREATE TABLE apartments(id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,block TEXT,floor TEXT,unit TEXT,type TEXT);INSERT INTO apartments VALUES(1,1,'A','1','01','A');
CREATE TABLE contractors(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,colour TEXT DEFAULT '#4B5563');
CREATE TABLE tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,apartment_id INTEGER,name TEXT,contractor_id INTEGER,operatives INTEGER DEFAULT 1,duration_days INTEGER,zone TEXT,constraint_start TEXT,percent_complete INTEGER DEFAULT 0,is_milestone INTEGER DEFAULT 0,start_date TEXT,finish_date TEXT,slack_days INTEGER,baseline_start TEXT,baseline_finish TEXT,alerts_json TEXT,source_import_id INTEGER,notes TEXT);
INSERT INTO tasks(id,project_id,apartment_id,name,duration_days,start_date,finish_date,source_import_id)VALUES(1,1,1,'Original activity',2,'2026-10-01','2026-10-05',50);
CREATE TABLE dependencies(id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER,predecessor_id INTEGER,type TEXT,lag_days INTEGER);
CREATE TABLE baselines(id INTEGER PRIMARY KEY AUTOINCREMENT,project_id INTEGER,label TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);''')
 db.commit()
 for p in (base/'api').rglob('*.php'):p.write_text(p.read_text().replace("file_get_contents('php://input')","file_get_contents('php://stdin')").replace('NOW()','CURRENT_TIMESTAMP').replace('CURDATE()','CURRENT_DATE'))
 (base/'runner.php').write_text('''<?php session_save_path(__DIR__);session_start();$_SESSION['csrf']='fixture-csrf';$_SESSION['user']=['id'=>1,'role'=>getenv('FIXTURE_ROLE')];$_SERVER['REQUEST_METHOD']=getenv('FIXTURE_METHOD');$_SERVER['HTTP_X_CSRF']=getenv('FIXTURE_CSRF');parse_str(parse_url($argv[1],PHP_URL_QUERY)?:'',$_GET);ob_start();register_shutdown_function(function(){$body=ob_get_clean();echo json_encode(['status'=>http_response_code()?:200,'body'=>json_decode($body,true),'raw'=>$body]);});require __DIR__.parse_url($argv[1],PHP_URL_PATH);''')
 def call(path,body=None,role='planner',csrf='fixture-csrf'):
  result=subprocess.run([PHP,str(base/'runner.php'),path],input=json.dumps(body)if body is not None else'',text=True,capture_output=True,env={**os.environ,'FIXTURE_ROLE':role,'FIXTURE_METHOD':'POST'if body is not None else'GET','FIXTURE_CSRF':csrf})
  try:data=json.loads(result.stdout)
  except Exception:raise AssertionError((path,result.stdout,result.stderr))
  assert 'Fatal' not in data['raw'],(path,data,result.stderr)
  return data['status'],data['body'],data['raw']
 def activity(name,date='2026-10-05'):return{'name':name,'start':date,'finish':'2026-10-09','section':'First floor','duration_days':5}
 def new(name,tasks):return{'project':1,'destination':'new','programme_name':name,'filename':name+'.csv','tasks':tasks,'baseline':True}
 original=db.execute('SELECT * FROM tasks WHERE id=1').fetchone()
 status,data,_=call('/api/import/commit.php',new('Contract Rev A',[activity('Rev A only')]))
 assert status==200 and data['imported']==1,(status,data);a=data['programme']
 status,data,_=call('/api/import/commit.php',new('Contract Rev B',[activity('Rev B only')]))
 assert status==200;b=data['programme'];assert a!=b
 assert db.execute('SELECT * FROM tasks WHERE id=1').fetchone()==original
 assert len(call('/api/programmes.php?project=1')[1]['programmes'])==3
 assert [t['name']for t in call('/api/workspace.php?project=1')[1]['tasks']]==['Original activity']
 for programme,name in [(a,'Rev A only'),(b,'Rev B only')]:
  status,data,_=call(f'/api/workspace.php?project=1&programme={programme}');assert status==200 and [t['name']for t in data['tasks']]==[name]
  for endpoint in ['csv.php','tasklist_xlsx.php','lookahead_xlsx.php']:
   status,_,raw=call(f'/api/export/{endpoint}?project=1&programme={programme}&from=2026-10-01');assert status==200 and name in raw and 'Original activity'not in raw
  assert len(call(f'/api/gantt.php?project=1&programme={programme}')[1]['events'])==1
  assert len(call(f'/api/lookahead.php?project=1&programme={programme}&from=2026-10-01')[1]['activities'])==1
  status,_,raw=call(f'/api/export/shortterm.php?project=1&programme={programme}&from=2026-10-01&nofpdf=1');assert status==200 and name in raw and 'Original activity'not in raw
  assert len(call(f'/api/analytics.php?project=1&programme={programme}&type=variance')[1]['rows'])==1
  assert len(call(f'/api/baselines.php?project=1&programme={programme}')[1]['data'])==1
 assert call(f'/api/workspace.php?project=2&programme={a}')[0]==404
 assert call('/api/workspace.php?project=1&programme=invalid')[0]==404
 assert call('/api/export/csv.php?project=1&programme=999')[0]==404
 assert call('/api/recalc.php?project=1&programme=invalid',{})[0]==404
 assert call(f'/api/recalc.php?project=1&programme={a}',{},role='viewer')[0]==403
 assert call(f'/api/recalc.php?project=1&programme={a}',{},csrf='wrong')[0]==419
 taska=db.execute('SELECT id FROM tasks WHERE source_import_id=?',(a,)).fetchone()[0]
 taskb=db.execute('SELECT id FROM tasks WHERE source_import_id=?',(b,)).fetchone()[0]
 beforeb=db.execute('SELECT * FROM tasks WHERE id=?',(taskb,)).fetchone()
 update={'id':taska,'name':'Rev A edited','percent_complete':25}
 assert call(f'/api/tasks.php?action=update&programme={b}',update)[0]==404
 assert call(f'/api/tasks.php?action=update&programme={a}',update,role='viewer')[0]==403
 assert call(f'/api/tasks.php?action=update&programme={a}',update,csrf='wrong')[0]==419
 assert call(f'/api/tasks.php?action=update&programme={a}',update)[0]==200
 assert db.execute('SELECT * FROM tasks WHERE id=?',(taskb,)).fetchone()==beforeb
 assert db.execute('SELECT * FROM tasks WHERE id=1').fetchone()==original
 assert db.execute('SELECT finish_date FROM tasks WHERE id=?',(taska,)).fetchone()[0]=='2026-10-09'
 assert call('/api/dependencies.php',{'task_id':taska,'predecessor_id':taskb,'type':'FS'})[0]==400
 assert db.execute('SELECT COUNT(*)FROM dependencies').fetchone()[0]==0
 assert call('/api/import/commit.php',new('Contract Rev A',[activity('Duplicate')]))[0]==400
 count=db.execute('SELECT COUNT(*) FROM imports').fetchone()[0]
 assert call('/api/import/commit.php',new('Empty',[{'name':'Invalid'}]))[0]==400
 assert db.execute('SELECT COUNT(*) FROM imports').fetchone()[0]==count
 assert call('/api/import/commit.php',new('Viewer',[activity('Denied')]),role='viewer')[0]==403
 assert call('/api/import/commit.php',new('No CSRF',[activity('Denied')]),csrf='wrong')[0]==403
 assert call('/api/import/commit.php',{'project':1,'destination':'existing','programme':str(a),'tasks':[activity('Appended A')]})[0]==200
 assert len(call(f'/api/workspace.php?project=1&programme={a}')[1]['tasks'])==2
 assert len(call(f'/api/workspace.php?project=1&programme={b}')[1]['tasks'])==1
 assert call('/api/baselines.php',{'project_id':1,'programme':str(a),'label':'Snapshot A'})[0]==200
 assert db.execute('SELECT * FROM tasks WHERE id=?',(taskb,)).fetchone()==beforeb
 print('PASS: two named imports, original/history preservation, project rejection, separate reads/CSV/Excel fallback/analytics/baselines, edit isolation, dates, dependency separation, rollback, roles/CSRF and explicit append.')

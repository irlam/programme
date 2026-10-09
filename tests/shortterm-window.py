"""Exercise actual export controller date-window and review-date handling in isolation."""
import json, os, pathlib, shutil, subprocess, tempfile

root = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='programme-report-window-') as temp:
    temp = pathlib.Path(temp)
    (temp/'api/export').mkdir(parents=True)
    shutil.copy(root/'api/export/shortterm.php', temp/'api/export/shortterm.php')
    # Controlled in-memory project/calendar only. No live user, session or database.
    (temp/'api/_bootstrap.php').write_text('''<?php
    namespace App\\Config;
    final class DB { public static function pdo() { static $p;
      if (!$p) { $p=new \\PDO('sqlite::memory:');$p->setAttribute(\\PDO::ATTR_DEFAULT_FETCH_MODE,\\PDO::FETCH_ASSOC);
        $p->exec("CREATE TABLE projects(id INTEGER,start_date TEXT,calendar_id INTEGER,name TEXT);
          INSERT INTO projects VALUES(1,'2026-11-02',1,'Fixture');
          CREATE TABLE calendars(id INTEGER,workdays_json TEXT);
          CREATE TABLE calendar_holidays(calendar_id INTEGER,date TEXT,is_working INTEGER);
          CREATE TABLE contractors(id INTEGER,name TEXT,colour TEXT);
          CREATE TABLE apartments(id INTEGER,block TEXT,floor TEXT,unit TEXT,type TEXT);
          CREATE TABLE tasks(id INTEGER,project_id INTEGER,apartment_id INTEGER,contractor_id INTEGER,start_date TEXT,finish_date TEXT);");
      } return $p; } }
    require ''' + repr(str(root/'app/Lib/WorkingDays.php')) + ';')
    runner=temp/'run.php'
    runner.write_text("<?php $_GET=json_decode($argv[1],true); require __DIR__.'/api/export/shortterm.php';")
    def run(params):
        return subprocess.run([os.environ.get('PHP_BINARY','php'),str(runner),json.dumps(params)],check=True,capture_output=True,text=True).stdout
    base={'project':1,'from':'2026-11-02','days':14,'format':'html'}
    assert '02/11/2026' in run(base) and '19/11/2026' in run(base)
    calendar=run({**base,'calendar_window':'1'})
    assert '02/11/2026' in calendar and '13/11/2026' in calendar and '19/11/2026' not in calendar
    for invalid in ['2026-02-31','bad','2026-11-02&debug=1','']:
        assert run({**base,'progress_line':'1','status_date':invalid})=='Choose a valid review date.'
    assert 'Fixture' in run({**base,'progress_line':'1','status_date':'2026-11-04'})
print('PASS: actual report controller calendar/working-day windows and invalid review dates')

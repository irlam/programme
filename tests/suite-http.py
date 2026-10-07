"""Actual HTTP session/cookie/gate tests, with a controlled Suite transport."""
import http.cookies, json, os, pathlib, shutil, socket, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

root = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BINARY', 'php')
php_server = [php, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0']
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None
opener = urllib.request.build_opener(NoRedirect())
with tempfile.TemporaryDirectory(prefix='programme-suite-http-') as temp:
    temp = pathlib.Path(temp); web = temp/'httpdocs'; web.mkdir()
    sessions = temp/'sessions'; sessions.mkdir()
    for name in ['SuiteGateway','SuiteUserMap','SuiteSession','SuiteHttp']:
        dst=web/'app'/'Lib'/(name+'.php'); dst.parent.mkdir(parents=True,exist_ok=True)
        shutil.copyfile(root/'app'/'Lib'/(name+'.php'),dst)
    for name in ['api/_bootstrap.php','app/config/DB.php','app/config/config.php','app/suite-prepend.php']:
        dst=web/name;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(root/name,dst)
    private=temp/'private';private.mkdir()
    (private/'config.php').write_text('<?php return ["db"=>["dsn"=>"sqlite::memory:","user"=>"fixture","pass"=>"fixture","options"=>[]]];')
    fixture = r'''<?php
    foreach (['SuiteGateway','SuiteUserMap','SuiteSession','SuiteHttp'] as $name) require_once __DIR__.'/app/Lib/'.$name.'.php';
    require_once __DIR__.'/app/config/DB.php';
    $binding=['instance_id'=>1,'organization_id'=>2,'project_id'=>3,'local_project_id'=>1,
      'suite_origin'=>'https://suite.defecttracker.uk','origin'=>'https://alpha.programme.defecttracker.uk','key'=>str_repeat('a',64)];
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE projects(id INTEGER PRIMARY KEY); INSERT INTO projects VALUES(1);
      CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,email TEXT UNIQUE,role TEXT,password_hash TEXT,confirmed INTEGER);
      CREATE TABLE suite_instance_binding(id INTEGER PRIMARY KEY,instance_id INTEGER,organization_id INTEGER,project_id INTEGER,local_project_id INTEGER);
      INSERT INTO suite_instance_binding VALUES(1,1,2,3,1);
      CREATE TABLE suite_user_map(suite_user_id INTEGER PRIMARY KEY,local_user_id INTEGER UNIQUE);');
    $flags=json_decode(file_get_contents(__DIR__.'/../flags.json'),true);
    $gateway=new App\Lib\SuiteGateway($binding,static function($url,$payload,$key)use($flags){
      file_put_contents(__DIR__.'/../calls.log',json_encode($payload)."\n",FILE_APPEND);
      if (!empty($flags['outage'])) throw new RuntimeException('Fixture outage');
      if (!empty($flags['deny'])) return ['ok'=>false];
      if (($payload['action']??'')==='revoke') return ['ok'=>true];
      return ['ok'=>true,'identity'=>['instance_id'=>1,'organization_id'=>2,'project_id'=>3,'module_key'=>'programme',
        'user_id'=>12,'name'=>'Fixture','email'=>'fixture@example.test','role'=>$flags['role']??'manager',
        'session_expires_at'=>time()+3600,'session_token'=>str_repeat('b',64)]];
    });
    // Fixture-only simulation of a TLS-terminating web server; production never trusts a header.
    if (($_SERVER['HTTP_X_FIXTURE_HTTPS']??'on')==='on') $_SERVER['HTTPS']='on';
    $route=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $service=new App\Lib\SuiteSession($gateway,new App\Lib\SuiteUserMap($pdo,$binding),$binding);
    (new App\Lib\SuiteHttp($gateway,$service,$binding))->run($route);
    require __DIR__.'/api/_bootstrap.php';
    echo 'PROTECTED_APPLICATION_EXECUTED';
    '''
    router=web/'fixture-router.php';router.write_text(fixture)
    (temp/'flags.json').write_text('{}')
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    log=(temp/'server.log').open('w')
    server=subprocess.Popen(php_server+['-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{port}',str(router)],stdout=log,stderr=log,cwd=web)
    cookies={}
    def request(route, method='GET', body=None, headers=None, host='alpha.programme.defecttracker.uk'):
        h={'Host':host,'Cookie':'; '.join(k+'='+v for k,v in cookies.items())};h.update(headers or {})
        req=urllib.request.Request(f'http://127.0.0.1:{port}'+route,data=body,headers=h,method=method)
        try: response=opener.open(req,timeout=3)
        except urllib.error.HTTPError as e: response=e
        data=response.read(); sets=response.headers.get_all('Set-Cookie') or []
        for value in sets:
            parsed=http.cookies.SimpleCookie();parsed.load(value)
            for k,v in parsed.items():
                if not v.value or v['max-age']=='0': cookies.pop(k,None)
                else: cookies[k]=v.value
        return response.code,data,response.headers,sets
    def flags(**values): (temp/'flags.json').write_text(json.dumps(values))
    def login():
        flags(); status,data,headers,sets=request('/suite-login.php')
        assert status==303 and headers['Location'].startswith('https://suite.defecttracker.uk/launch.php?')
        state=urllib.parse.parse_qs(urllib.parse.urlparse(headers['Location']).query)['state'][0]
        state_header=next(x for x in sets if x.startswith('__Host-programme_state='))
        assert 'secure' in state_header.lower() and 'httponly' in state_header.lower() and 'samesite=none' in state_header.lower() and 'domain=' not in state_header.lower()
        old=cookies['__Host-programme_suite']
        status,data,headers,sets=request('/suite-login.php','POST',urllib.parse.urlencode({'code':'c'*64,'state':state}).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
        assert status==303 and headers['Location']=='/' and old!=cookies['__Host-programme_suite'],'Session ID must rotate'
        assert '__Host-programme_state' not in cookies,'One-use state cookie cleared'
        assert all('b'*64 not in s for s in sets),'Suite token must never be a browser cookie'
        status,data,headers,sets=request('/api/auth.php?action=whoami')
        assert status==200 and 'b'*64 not in data.decode(),'Whoami must not disclose token'
        return json.loads(data)['csrf']
    try:
        for attempt in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:time.sleep(.02)
        else: raise AssertionError('HTTP server unavailable')
        for route in ['/api/workspace.php','/api/export/csv.php','/api/import/commit.php','/admin/index.php']:
            assert request(route)[0]==401,(route,'Anonymous request escaped gate')
        for route in ['/api/raw.php','/api/recalc.php','/admin/users.php','/admin/debug_grid_probe.php','/tools/install_phpss_manual.php','/app/config/config.php']:
            assert request(route)[0]==404,(route,'Unapproved route exposed')
        assert request('/api/export/shortterm.php?debug=1')[0]==404
        assert request('/api/import/preview.php?selftest=1')[0]==404
        assert request('/api/auth.php?action=login','POST',b'{}')[0]==403
        assert request('/api/auth.php?action=create_user','POST',b'{}')[0]==403
        csrf=login()
        assert request('/api/export/csv.php?project=1')[1]==b'PROTECTED_APPLICATION_EXECUTED'
        assert request('/api/export/csv.php?project=2')[0]==403
        assert request('/api/tasks.php','POST',b'{"project_id":2}',{'X-CSRF':csrf,'Content-Type':'application/json'})[0]==403
        assert request('/api/tasks.php','POST',b'{}',{'Content-Type':'application/json'})[0]==419
        assert request('/api/tasks.php','POST',b'{}',{'X-CSRF':csrf,'Content-Type':'application/json'})[1]==b'PROTECTED_APPLICATION_EXECUTED'
        flags(role='contractor')
        assert request('/api/tasks.php','POST',b'{}',{'X-CSRF':csrf,'Content-Type':'application/json'})[0]==403
        assert request('/api/comments.php','POST',b'{}',{'X-CSRF':csrf,'Content-Type':'application/json'})[0]==200
        flags(role='viewer')
        status,data,_,_=request('/api/auth.php?action=whoami')
        assert status==200 and json.loads(data)['user']['read_only'] is True
        assert request('/api/workspace.php')[0]==200
        assert request('/api/export/csv.php?project=1')[0]==200
        assert request('/api/export/csv.php?project=2')[0]==403
        for route in ['/api/comments.php','/api/tasks.php','/api/import/preview.php','/api/import/commit.php','/api/baselines.php','/api/calendar.php','/admin/holidays.php','/admin/templates.php']:
            for method in ['POST','DELETE']:
                assert request(route,method,b'{}',{'X-CSRF':csrf,'Content-Type':'application/json'})[0]==403,(route,method,'Viewer mutation escaped gate')
        flags(role='manager')
        assert json.loads(request('/api/auth.php?action=whoami')[1])['user']['read_only'] is False
        assert request('/api/tasks.php','POST',b'{}',{'X-CSRF':csrf,'Content-Type':'application/json'})[0]==200
        assert request('/api/workspace.php',host='beta.programme.defecttracker.uk')[0]==403
        assert request('/api/workspace.php',headers={'X-Fixture-HTTPS':'off'})[0]==403
        flags(deny=True)
        assert request('/api/export/csv.php')[0]==401 and '__Host-programme_suite' not in cookies
        csrf=login();flags(outage=True)
        assert request('/api/auth.php?action=logout','POST',b'',{'X-CSRF':csrf})[0]==503 and '__Host-programme_suite' not in cookies
        csrf=login();flags(outage=True)
        assert request('/api/workspace.php')[0]==401 and '__Host-programme_suite' not in cookies
        csrf=login()
        status,data,headers,sets=request('/suite-login.php')
        status,data,headers,sets=request('/suite-login.php','POST',b'code='+b'c'*64+b'&state='+b'd'*64,{'Content-Type':'application/x-www-form-urlencoded'})
        assert status==401 and '__Host-programme_suite' not in cookies,'Failed callback clears previous login'
    finally:
        server.terminate();server.wait(timeout=3);log.close()
    errors=(temp/'server.log').read_text()
    assert 'Fatal error' not in errors and 'Warning:' not in errors,errors
    # Exercise the real prepend entrypoint: missing instance config must stop PHP execution.
    marker=web/'marker.php';marker.write_text('<?php echo "UNSAFE_APP_EXECUTED";')
    env=dict(os.environ);env['PROGRAMME_SUITE_CONFIG_FILE']=str(temp/'absent-config.php')
    server=subprocess.Popen(php_server+['-d',f'auto_prepend_file={web}/app/suite-prepend.php','-S',f'127.0.0.1:{port}','-t',str(web)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=env)
    try:
        for attempt in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:time.sleep(.02)
        assert request('/marker.php')[0:2]==(503,b'{"ok":false,"error":"access_unavailable"}')
    finally:
        server.terminate();server.wait(timeout=3)
print('PASS: HTTP callback/state, secure host-only cookies, session rotation, local-login refusal, protected routes, project checks, CSRF, current roles, logout and outage denial.')

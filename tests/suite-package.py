"""Check sanitized package contents, PHP syntax and fallback HTTP protection."""
import hashlib, importlib.util, json, os, pathlib, re, socket, subprocess, tempfile, time
import urllib.request, urllib.error, zipfile
root=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('builder',root/'bin/build-suite-package.py')
builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
php=os.environ.get('PHP_BINARY','php')
with tempfile.TemporaryDirectory(prefix='programme-package-') as temp:
    temp=pathlib.Path(temp);archive=temp/'staging.zip'
    # An untracked dependency must not silently become publicly deployed code.
    poison=root/'libs'/'suite-package-untracked-fixture.php'
    if poison.exists():raise AssertionError('Fixture collision')
    try:
        poison.write_text('<?php echo "UNTRACKED_FIXTURE";')
        builder.build(archive)
    finally:poison.unlink()
    original=archive.read_bytes()
    try:builder.build(archive)
    except ValueError:pass
    else:raise AssertionError('Existing package overwritten')
    assert archive.read_bytes()==original
    twin=temp/'twin.zip';builder.build(twin);assert twin.read_bytes()==original,'Build must be deterministic'
    with zipfile.ZipFile(archive) as package:
        names=package.namelist();manifest=json.loads(package.read('deployment/manifest.json'))
        assert manifest['tenant_ready'] is False and manifest['staging_only'] is True
        for name,digest in manifest['files'].items():assert hashlib.sha256(package.read(name)).hexdigest()==digest
        for name in names:
            assert not name.startswith('/') and '..' not in pathlib.PurePosixPath(name).parts
            assert not re.search(r'(?i)\.(sql|bak|backup|log|env|ini|sqlite|db)(\.|$)',name),name
            assert not any(name.startswith('httpdocs/'+p+'/') for p in ['tools','tests','bin','docs','Database','uploads','private','var']),name
            assert 'runtime.private.php' not in name and 'suite-package-untracked-fixture' not in name,name
        for name in builder.PUBLIC_PHP:
            assert "require_once " in package.read('httpdocs/'+name).decode()
            assert "'/app/suite-prepend.php'" in package.read('httpdocs/'+name).decode()
        gate=(root/'app/Lib/SuiteHttp.php').read_text().split('$allowed = [',1)[1].split('];',1)[0]
        allowed=set(re.findall(r"'(/[^']+\.php)'",gate))|{'/suite-login.php'}
        assert allowed=={'/'+p for p in builder.PUBLIC_PHP},'Package/gate route inventory drift'
        for name in ['httpdocs/index.html','httpdocs/lookahead.html','httpdocs/admin/index.php','httpdocs/assets/js/chrome.js']:
            assert '/admin/users.php' not in package.read(name).decode(),name
        login=package.read('httpdocs/login.html').decode()
        assert '/suite-login.php' in login and 'type="password"' not in login
        package.extractall(temp/'extracted')
    web=temp/'extracted'/'httpdocs'
    for file in web.rglob('*.php'):
        result=subprocess.run([php,'-l',str(file)],capture_output=True,text=True)
        assert result.returncode==0,result.stdout+result.stderr
    # No auto_prepend setting: every public packaged endpoint must still fail closed.
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    env=dict(os.environ);env['PROGRAMME_SUITE_CONFIG_FILE']=str(temp/'absent-config.php')
    server=subprocess.Popen([php,'-d','opcache.jit=0','-d','opcache.jit_buffer_size=0','-S',f'127.0.0.1:{port}','-t',str(web)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for attempt in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:time.sleep(.02)
        else:raise AssertionError('Package server unavailable')
        for route in builder.PUBLIC_PHP:
            for method in ['GET','POST']:
                request=urllib.request.Request(f'http://127.0.0.1:{port}/'+route,method=method,data=b'' if method=='POST' else None)
                try:urllib.request.urlopen(request,timeout=2);raise AssertionError('Unguarded endpoint '+route)
                except urllib.error.HTTPError as response:
                    assert response.code==503,(route,method,response.code)
                    assert response.read()==b'{"ok":false,"error":"access_unavailable"}',route
    finally:server.terminate();server.wait(timeout=3)
print('PASS: deterministic sanitized package, tracked dependencies, route inventory, no local-account UI, all packaged PHP syntax and every public endpoint fails closed without server prepend.')

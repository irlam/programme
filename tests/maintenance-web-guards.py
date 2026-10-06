"""Exercise maintenance routes over HTTP without app credentials or databases."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
root=pathlib.Path(__file__).resolve().parents[1]
routes=json.loads((root/'tests/maintenance-routes.json').read_text())
with tempfile.TemporaryDirectory(prefix='programme-guards-') as temp:
 temp=pathlib.Path(temp)/"domain/httpdocs";temp.mkdir(parents=True)
 for route in routes:
  target=temp/route;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(root/route,target)
 for route in ['admin/health.php','api/_bootstrap.php','app/config/DB.php','app/config/config.php','bin/install-runtime-config.php']:
  target=temp/route;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(root/route,target)
 private=temp.parent/'private';private.mkdir();(private/'config.php').write_text('<?php declare(strict_types=1); return ["db"=>["dsn"=>"sqlite::memory:","user"=>"fixture","pass"=>"fixture","options"=>[]]];')
 subprocess.run([os.environ.get('PHP_BINARY','php'),str(temp/'bin/install-runtime-config.php')],check=True,stdout=subprocess.DEVNULL)
 expected=sorted(str(p.relative_to(temp)) for p in temp.rglob('*') if p.is_file())
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
 server=subprocess.Popen([os.environ.get('PHP_BINARY','php'),'-S',f'127.0.0.1:{port}','-t',str(temp)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  for attempt in range(50):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
   except OSError:time.sleep(.02)
  else:raise AssertionError('Fixture HTTP server did not start')
  for route in routes:
   for method in ['GET','POST']:
    request=urllib.request.Request(f'http://127.0.0.1:{port}/{route}',method=method,data=b'' if method=='POST' else None)
    try:urllib.request.urlopen(request,timeout=2);raise AssertionError(f'Web maintenance route was allowed: {route}')
    except urllib.error.HTTPError as error:
     assert error.code==404,(route,method,error.code)
     assert error.read()==b'',(route,'response disclosed output')
  for route,status in [('admin/health.php',403),('app/config/runtime.private.php',404)]:
   try:urllib.request.urlopen(f'http://127.0.0.1:{port}/{route}',timeout=2);raise AssertionError(f'Protected route was allowed: {route}')
   except urllib.error.HTTPError as error:assert error.code==status,(route,error.code)
  assert sorted(str(p.relative_to(temp)) for p in temp.rglob('*') if p.is_file())==expected,'A blocked installer changed files' 
 finally:
  server.terminate();server.wait(timeout=3)
print(f'PASS: {len(routes)} maintenance routes deny GET/POST before file/database work.')

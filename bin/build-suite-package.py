#!/usr/bin/env python3
"""Build a dedicated Suite staging ZIP. Does not deploy or include credentials."""
import argparse, hashlib, json, pathlib, re, subprocess, zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
PUBLIC_PHP = [
    'suite-login.php','api/auth.php','api/workspace.php','api/tasks.php','api/comments.php',
    'api/dependencies.php','api/baselines.php','api/calendar.php','api/contractors.php',
    'api/templates.php','api/gantt.php','api/analytics.php','api/lookahead.php',
    'api/import/preview.php','api/import/commit.php','api/export/csv.php','api/export/excel.php',
    'api/export/lookahead_xlsx.php','api/export/tasklist_xlsx.php','api/export/shortterm.php',
    'api/templates/lookahead.php','api/templates/tasklist.php','api/templates/tasklist_csv.php',
    'admin/index.php','admin/baselines.php','admin/holidays.php','admin/templates.php',
]
SUPPORT = ['api/_bootstrap.php','app/suite-prepend.php','app/config/config.php','app/config/DB.php']
SUPPORT += ['app/Lib/'+name+'.php' for name in ['SuiteBinding','SuiteGateway','SuiteUserMap','SuiteSession','SuiteHttp','SuitePreflight','Scheduler','WorkingDays','ImportLookahead']]
SUPPORT += ['app/Lib/fpdf/fpdf.php']
STATIC = ['index.html','lookahead.html','analytics.html','admin/import.html',
    'assets/programme-mark.svg','assets/css/chrome.css','assets/css/workspace.css',
    'assets/js/workspace.js','assets/js/chrome.js','assets/js/analytics.js']

APACHE = '''Options -Indexes
RewriteEngine On
RewriteRule ^(?:app|libs|Database|private|var|uploads|tools|tests|bin|docs)(?:/|$) - [F,L,NC]
RewriteRule ^api/_bootstrap\\.php$ - [F,L,NC]
RewriteRule (?:^|/)\\. - [F,L]
<FilesMatch "(?i)\\.(sql|bak|backup|log|env|ini|zip|tar|gz|sqlite|db)(?:\\.|$)">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "no-referrer"
  Header always set Cache-Control "no-store"
</IfModule>
'''
NGINX = '''# Merge into this instance's server block only; verify with nginx -t.
# PHP prepend/.htaccess alone cannot protect nginx static responses.
'''+''.join('location ^~ /'+p+'/ { deny all; }\n' for p in ['app','libs','Database','private','var','uploads','tools','tests','bin','docs'])+'''location = /api/_bootstrap.php { deny all; }
location ~* \\.(sql|bak|backup|log|env|ini|zip|tar|gz|sqlite|db)(\\.|$) { deny all; }
# Retain the provider's existing dotfile-denial and PHP-FPM locations.
'''

def source(name):
    path=ROOT/name
    if path.is_symlink() or not path.is_file() or not path.resolve().is_relative_to(ROOT):
        raise ValueError('Unsafe or missing package source: '+name)
    return path.read_bytes()

def guarded(name, data):
    text=data.decode('utf-8')
    match=re.match(r'<\?php\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;',text)
    if not match: raise ValueError('Public endpoint needs a strict declaration: '+name)
    depth=len(pathlib.PurePosixPath(name).parts)-1
    expression='__DIR__' if depth==0 else 'dirname(__DIR__, '+str(depth)+')'
    guard="\nrequire_once "+expression+" . '/app/suite-prepend.php';\n"
    return (text[:match.end()]+guard+text[match.end():]).encode()

def navigation(data):
    return data.replace(b'/admin/users.php',b'https://suite.defecttracker.uk/').replace(b'href="https://suite.defecttracker.uk/" data-requires-edit hidden',b'href="https://suite.defecttracker.uk/"').replace(b'Team & access',b'Suite dashboard').replace(b'Manage programme accounts and their existing permissions.',b'Manage your team and project access through Construction Suite.').replace(b'Manage team',b'Open Suite')

def help_page(title, content):
    return ('''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'''+title+''' · Programme</title><link rel="icon" href="/assets/programme-mark.svg"><link rel="stylesheet" href="/assets/css/workspace.css"></head><body><main style="max-width:640px;margin:12vh auto;padding:24px"><img src="/assets/programme-mark.svg" width="52" alt="Programme"><h1>'''+title+'''</h1><p>'''+content+'''</p><p><a class="btn primary" href="/suite-login.php">Sign in through Construction Suite</a></p><p><a href="/">Programme</a> · <a href="https://suite.defecttracker.uk/">Suite dashboard</a></p></main></body></html>''').encode()

def build(destination):
    destination=pathlib.Path(destination).absolute()
    if destination.exists() or destination.is_symlink(): raise ValueError('Refusing to overwrite an existing artifact.')
    if destination.resolve().is_relative_to(ROOT): raise ValueError('Save the package outside the source checkout.')
    files={}
    for name in PUBLIC_PHP: files['httpdocs/'+name]=navigation(guarded(name,source(name)))
    for name in SUPPORT+STATIC: files['httpdocs/'+name]=navigation(source(name))
    # Only tracked dependency/font code is eligible, never an untracked local/runtime file.
    tracked=subprocess.run(['git','ls-files','-z','--','libs','app/Lib/fpdf/font'],cwd=ROOT,check=True,capture_output=True).stdout.decode().split('\0')
    for name in tracked:
        path=pathlib.PurePosixPath(name)
        if name and (path.suffix=='.php' or (name.startswith('libs/phpspreadsheet/src/PhpSpreadsheet/Calculation/locale/') and path.name in ['config','functions'])):
            files['httpdocs/'+name]=source(name)
    files['httpdocs/login.html']=help_page('Your project starts in Suite','Use your Construction Suite account to open this project. Your company and project permissions are checked when you sign in and while you work.')
    files['httpdocs/about.html']=help_page('Programme help','View the timeline and lookahead, review analytics, and export your plan. Project managers can import schedules and maintain calendars and baselines. Team access is managed in Construction Suite.')
    files['httpdocs/links.html']=help_page('Your project tools','Open the programme workspace to access lookahead, analytics, imports and exports. Sign in through Suite to use protected project tools.')
    files['httpdocs/.htaccess']=APACHE.encode()
    files['deployment/nginx-rules.conf']=NGINX.encode()
    files['deployment/README.md']=source('docs/SUITE-HTTP-DEPLOYMENT.md')
    files['deployment/suite-preflight.php']=source('bin/suite-preflight.php')
    manifest={'format':1,'staging_only':True,'tenant_ready':False,
        'public_php':PUBLIC_PHP,'files':{name:hashlib.sha256(data).hexdigest() for name,data in sorted(files.items())}}
    files['deployment/manifest.json']=(json.dumps(manifest,indent=2,sort_keys=True)+'\n').encode()
    # Exclusive creation: no source tree or existing archive is changed.
    with destination.open('xb') as output:
        with zipfile.ZipFile(output,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
            for name,data in sorted(files.items()):
                item=zipfile.ZipInfo(name,date_time=(1980,1,1,0,0,0));item.compress_type=zipfile.ZIP_DEFLATED
                item.external_attr=(0o100644 << 16)
                archive.writestr(item,data)
    return {'path':str(destination),'files':len(files),'sha256':hashlib.sha256(destination.read_bytes()).hexdigest(),'staging_only':True}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('destination')
    args=parser.parse_args();print(json.dumps(build(args.destination)))

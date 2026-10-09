#!/usr/bin/env python3
"""Prepare the sanitized Suite application at Git root for Plesk deployment."""
import argparse, hashlib, importlib.util, json, pathlib, re, tempfile, zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]

def build(destination, source_ref):
    destination = pathlib.Path(destination).absolute()
    if destination.exists() or destination.is_symlink():
        raise ValueError('Destination must be a new directory')
    if destination.resolve().is_relative_to(ROOT):
        raise ValueError('Destination must be outside the source checkout')
    if not re.fullmatch(r'[0-9a-f]{40}', source_ref):
        raise ValueError('An exact source commit SHA is required')
    spec = importlib.util.spec_from_file_location('suite_package', ROOT/'bin/build-suite-package.py')
    package = importlib.util.module_from_spec(spec); spec.loader.exec_module(package)
    with tempfile.TemporaryDirectory(prefix='programme-plesk-package-') as temp:
        archive = pathlib.Path(temp)/'package.zip'
        package.build(archive)
        with zipfile.ZipFile(archive) as z:
            files = {name.removeprefix('httpdocs/'): z.read(name)
                     for name in z.namelist() if name.startswith('httpdocs/')}
    # Site-owned runtime/configuration, uploads and database files are never tracked.
    blocked = ('private/', 'var/', 'uploads/', 'Database/', 'tools/', 'tests/', 'bin/', 'docs/')
    if any(name.startswith(blocked) or 'runtime.' in name or pathlib.PurePosixPath(name).suffix in ['.sql','.db','.sqlite','.env'] for name in files):
        raise ValueError('Unexpected deployment-owned or development file')
    metadata = {'format':1, 'source_commit':source_ref, 'staging_only':True, 'tenant_ready':False,
                'files':{name:hashlib.sha256(data).hexdigest() for name,data in sorted(files.items())}}
    files['.programme-deployment.json'] = (json.dumps(metadata, indent=2, sort_keys=True)+'\n').encode()
    files['.gitignore'] = b'/private/\n/var/\n/uploads/\n/app/config/runtime.*.php\n/.env\n/.user.ini\n'
    destination.mkdir(mode=0o700, parents=True)
    for name, data in files.items():
        path = destination/name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
    return {'path':str(destination), 'files':len(files), 'source_commit':source_ref, 'staging_only':True}

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination'); parser.add_argument('--source-ref', required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.destination, args.source_ref)))

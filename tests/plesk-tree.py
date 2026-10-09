"""Check Plesk root layout and preservation of server-owned configuration."""
import hashlib, json, pathlib, subprocess, sys, tempfile
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='programme-plesk-test-') as temp:
    destination=pathlib.Path(temp)/'deployment'
    subprocess.run([sys.executable,str(root/'bin/build-plesk-tree.py'),str(destination),'--source-ref','a'*40],check=True,capture_output=True)
    assert (destination/'index.html').is_file() and not (destination/'httpdocs').exists()
    assert not any((destination/name).exists() for name in ['private','var','uploads','Database','tools','tests','bin','docs','app/config/runtime.suite.private.php','.env','.user.ini'])
    assert 'app/config/runtime.*.php' in (destination/'.gitignore').read_text()
    manifest=json.loads((destination/'.programme-deployment.json').read_text())
    assert manifest['source_commit']=='a'*40 and manifest['tenant_ready'] is False
    for name, digest in manifest['files'].items(): assert hashlib.sha256((destination/name).read_bytes()).hexdigest()==digest
    for name in ['api/export/shortterm.php','api/import/commit.php','api/import/preview.php','suite-login.php']:
        assert 'app/suite-prepend.php' in (destination/name).read_text()
    assert '/admin/users.php' not in (destination/'index.html').read_text()
    for name in ['assets/vendor/pdfjs/pdf.min.js','assets/vendor/pdfjs/pdf.worker.min.js','assets/js/programme-pdf.js','app/Lib/ShortTermReport.php']:
        assert (destination/name).is_file()
print('PASS: Plesk deployment root, exact manifest, protected endpoints, dependencies and server-owned file exclusion')

<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__, 1); // /httpdocs

// ---- App autoload (vendor first, then App\ PSR-4) ----
$composer = $ROOT . '/vendor/autoload.php';
if (file_exists($composer)) {
  require $composer;
} else {
  spl_autoload_register(function(string $class) use ($ROOT) {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
      $rel = substr($class, strlen($prefix));
      $path = $ROOT . '/app/' . str_replace('\\', '/', $rel) . '.php';
      if (file_exists($path)) require $path;
    }
  });
}

// ---- Manual PhpSpreadsheet autoloader (so checks won't fatal) ----
$manualAuto = $ROOT . '/libs/autoload-phpss.php';
if (is_file($manualAuto)) { require_once $manualAuto; }

use App\Config\DB;

// ---- helpers ----
$checks = [];
function add_check(array &$arr, string $label, bool $pass, string $detail=''): void {
  $arr[] = ['label'=>$label, 'pass'=>$pass, 'detail'=>$detail];
}

add_check($checks, 'PHP Version', true, phpversion());
add_check($checks, 'PDO extension', extension_loaded('pdo'));
add_check($checks, 'PDO MySQL driver', in_array('mysql', PDO::getAvailableDrivers(), true));

// Database
try {
  $pdo = DB::pdo(); 
  $pdo->query('SELECT 1');
  add_check($checks, 'Database connection', true);
} catch (Throwable $e) {
  add_check($checks, 'Database connection', false, $e->getMessage());
}

// Key paths
$paths = [
  'App directory (/app)'                         => $ROOT . '/app',
  'Migrations (/scripts/migrations)'             => $ROOT . '/scripts/migrations',
  'Exports storage (/storage/exports)'           => $ROOT . '/storage/exports',
  'Uploads storage (/storage/uploads)'           => $ROOT . '/storage/uploads',
  'DayPilot JS (/assets/js/daypilot-lite.min.js)'=> $ROOT . '/assets/js/daypilot-lite.min.js',
  'FPDF library (/app/Lib/fpdf/fpdf.php)'        => $ROOT . '/app/Lib/fpdf/fpdf.php',
  'PhpSpreadsheet autoloader (/libs/autoload-phpss.php)' => $manualAuto,
];

foreach ($paths as $label => $path) {
  $exists = file_exists($path); 
  $writable = $exists && is_writable($path);
  $detail = ($exists ? 'exists' : 'missing') . ($exists ? ($writable ? '; writable' : '; not writable') : '');
  add_check($checks, $label, $exists, $detail);

  // Write tests for storage dirs
  if ($exists && is_dir($path) && preg_match('~/storage/(exports|uploads)$~', $path)) {
    $testFile = rtrim($path,'/'). '/_write_test.txt';
    $canWrite = @file_put_contents($testFile, 'ok') !== false;
    if ($canWrite) @unlink($testFile);
    add_check($checks, basename($path).' write test', $canWrite, $canWrite ? 'ok' : 'failed');
  }
}

// ---- PhpSpreadsheet / imports section ----
add_check($checks, 'ext-zip (ZipArchive)', extension_loaded('zip'));
add_check($checks, 'ext-xml', extension_loaded('xml'));
add_check($checks, 'ext-mbstring', extension_loaded('mbstring'));

// Class existence checks (don’t fatal thanks to manual autoloader above)
$hasPcre = class_exists('Composer\\Pcre\\Preg');
$hasSS   = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
$hasIOF  = class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');

add_check($checks, 'Composer\\Pcre\\Preg (manual dependency)', $hasPcre);
add_check($checks, 'PhpOffice\\PhpSpreadsheet\\Spreadsheet', $hasSS);
add_check($checks, 'PhpOffice\\PhpSpreadsheet\\IOFactory', $hasIOF);

// Reader probe
if ($hasIOF) {
  try {
    \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    add_check($checks, 'PhpSpreadsheet XLSX reader', true, 'OK');
  } catch (Throwable $e) {
    add_check($checks, 'PhpSpreadsheet XLSX reader', false, $e->getMessage());
  }
} else {
  add_check($checks, 'PhpSpreadsheet XLSX reader', false, 'IOFactory class missing');
}

// ---- PHP environment (useful for imports/exports) ----
$ini = fn(string $k): string => (string)ini_get($k);
$limits = [
  'memory_limit'        => $ini('memory_limit'),
  'upload_max_filesize' => $ini('upload_max_filesize'),
  'post_max_size'       => $ini('post_max_size'),
  'max_execution_time'  => $ini('max_execution_time'),
];

foreach ($limits as $k=>$v) add_check($checks, "PHP $k", true, $v);

// OPcache
$opcacheEnabled = function_exists('opcache_get_status') ? (bool)ini_get('opcache.enable') : false;
add_check($checks, 'OPcache enabled', $opcacheEnabled, $opcacheEnabled ? 'OK' : 'Disabled or unavailable');

// ---- handy links ----
$recalc_url   = '/api/recalc.php?project=1';
$fetch_dp_url = '/admin/fetch-daypilot.php';
$import_url   = '/admin/import.html';
$about_url    = '/about.html';
$links_url    = '/links.html';

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Admin • Health Check</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;}
  header{padding:14px 16px;background:#111827;border-bottom:1px solid #1f2937;display:flex;gap:10px;align-items:center}
  header .spacer{flex:1}
  .wrap{max-width:980px;margin:0 auto;padding:20px;}
  .card{background:#0b1220;border:1px solid #1f2937;border-radius:10px;padding:16px;margin-bottom:16px;}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px 10px;border-bottom:1px solid #1f2937;font-size:14px;vertical-align:top}
  th{text-align:left;color:#cbd5e1}
  .ok{color:#10b981}.bad{color:#ef4444}.warn{color:#f59e0b}
  a.button{background:#2563eb;color:white;text-decoration:none;padding:8px 12px;border-radius:8px;display:inline-block;border:1px solid #1d4ed8}
  a.link{color:#93c5fd;text-decoration:none}
  code{background:#111827;padding:2px 6px;border-radius:4px}
  .row{display:flex;gap:12px;flex-wrap:wrap}
</style>
</head>
<body>
<header>
  <div><strong>Apartment Programme</strong> • Health Check</div>
  <div class="spacer"></div>
  <a class="button" href="<?= htmlspecialchars($recalc_url) ?>" target="_blank">Run Recalc</a>
  <a class="button" href="<?= htmlspecialchars($fetch_dp_url) ?>" target="_blank">Fetch DayPilot JS</a>
  <a class="button" href="<?= htmlspecialchars($import_url) ?>" target="_blank">Importer</a>
  <a class="button" href="<?= htmlspecialchars($links_url) ?>" target="_blank">Links</a>
  <a class="button" href="<?= htmlspecialchars($about_url) ?>" target="_blank">About</a>
</header>

<div class="wrap">

  <div class="card">
    <h3 style="margin:0 0 10px 0">Status</h3>
    <table>
      <thead><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['label']) ?></td>
          <td><?php if ($c['pass']): ?><span class="ok">OK</span><?php else: ?><span class="bad">FAIL</span><?php endif; ?></td>
          <td><?= htmlspecialchars((string)$c['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:10px">Missing <code>daypilot-lite.min.js</code>? Click <em>Fetch DayPilot JS</em> or the UI will fall back to the CDN automatically.</p>
    <p style="margin-top:6px">For XLSX imports, ensure <code>ext-zip</code>, <code>ext-xml</code>, <code>ext-mbstring</code> are <span class="ok">OK</span> and <code>/storage/uploads</code> is writable.</p>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0">Quick Links</h3>
    <div class="row">
      <a class="button" href="/" target="_blank">Gantt</a>
      <a class="button" href="/admin/" target="_blank">Admin</a>
      <a class="button" href="/admin/users.php" target="_blank">Users</a>
      <a class="button" href="/admin/templates.php" target="_blank">Templates</a>
      <a class="button" href="/admin/baselines.php" target="_blank">Baselines & Variance</a>
      <a class="button" href="/api/export/shortterm.php?project=1&days=14&group=apartment&format=html" target="_blank">Short-term (HTML)</a>
      <a class="button" href="/api/export/shortterm.php?project=1&days=14&group=apartment" target="_blank">Short-term (PDF Apts)</a>
      <a class="button" href="/api/export/shortterm.php?project=1&days=14&group=contractor" target="_blank">Short-term (PDF Contractors)</a>
      <a class="button" href="/api/export/csv.php?project=1" target="_blank">CSV Export</a>
    </div>
  </div>

</div>
</body>
</html>

<?php
/* undo_injection.php — restore files from the backups created by the auto-injector
 * Default: restores only risky API/tools files that should NOT have <script> tags.
 * Optional: add ?extra=1 to also restore /about.html and /analytics.html.
 */
declare(strict_types=1);
$root = dirname(__DIR__); // /httpdocs

$risky = [
  '/api/export/excel.php',
  '/api/tasks.php',
  '/tools/install_contractor_patch.php',
];
$extra = isset($_GET['extra']) && $_GET['extra'] === '1';
if ($extra) {
  $risky = array_merge($risky, [
    '/about.html',
    '/analytics.html',
  ]);
}

function latest_backup_for(string $abs): ?string {
  $globs = glob($abs . '.bak.*');
  if (!$globs) return null;
  // Pick the newest by mtime
  usort($globs, fn($a,$b)=>filemtime($b)<=>filemtime($a));
  return $globs[0] ?? null;
}

$results = [];
foreach ($risky as $rel) {
  $abs = $root . $rel;
  if (!is_file($abs)) {
    $results[] = [$rel, 'SKIP', 'original not found'];
    continue;
  }
  $bak = latest_backup_for($abs);
  if (!$bak || !is_file($bak)) {
    $results[] = [$rel, 'FAIL', 'no backup found (*.bak.*)'];
    continue;
  }
  // Restore
  $ok = @copy($bak, $abs);
  $results[] = [$rel, $ok ? 'OK' : 'FAIL', $ok ? ('restored from ' . basename($bak)) : 'copy failed'];
}

// Output
?><!doctype html><html><head>
<meta charset="utf-8"><title>Undo Injection</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
 .wrap{max-width:900px;margin:0 auto;padding:20px}
 table{width:100%;border-collapse:collapse}
 th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
 .ok{color:#86efac}.fail{color:#fca5a5}.skip{color:#fbbf24}
 a{color:#93c5fd}
 code{background:#0b1220;border:1px solid #1f2937;padding:2px 6px;border-radius:6px}
</style></head><body><div class="wrap">
<h1>Undo Script Injection</h1>
<p>Root: <?=htmlspecialchars($root)?></p>
<table><thead><tr><th>File</th><th>Status</th><th>Details</th></tr></thead><tbody>
<?php foreach ($results as $r): [$rel,$st,$msg] = $r; ?>
  <tr><td><?=htmlspecialchars($rel)?></td>
      <td class="<?=strtolower($st)?>"><?=htmlspecialchars($st)?></td>
      <td><?=htmlspecialchars($msg)?></td></tr>
<?php endforeach; ?>
</tbody></table>

<p style="margin-top:12px"><strong>Notes:</strong></p>
<ul>
  <li>If you still see odd output from an API, clear any PHP OPcache and try again.</li>
  <li>To also revert <code>/about.html</code> and <code>/analytics.html</code>: <a href="?extra=1">run with ?extra=1</a></li>
  <li>Delete this file when finished for security.</li>
</ul>
</div></body></html>

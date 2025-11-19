<?php
/* install_inject_drawer_patch.php — auto-inject drawer patch <script> tags
 * - Scans for DayPilot/Gantt pages and injects:
 *     *     * - Backs up each edited file as filename.bak.Ymd_His
 * - Run:
 *     /admin/install_inject_drawer_patch.php            (scan & inject)
 *     /admin/install_inject_drawer_patch.php?scan=1     (scan only)
 *     /admin/install_inject_drawer_patch.php?target=/index.php   (inject into one file)
 */
declare(strict_types=1);

$root = dirname(__DIR__);             // /httpdocs
$scanOnly = isset($_GET['scan']) && $_GET['scan'] === '1';
$target   = isset($_GET['target']) ? (string)$_GET['target'] : null;

$TAG1 = '';
$TAG2 = '';

function relpath(string $abs, string $root): string {
  $root = rtrim(realpath($root), DIRECTORY_SEPARATOR);
  $abs  = realpath($abs) ?: $abs;
  if (strpos($abs, $root) === 0) {
    return substr($abs, strlen($root));
  }
  return $abs;
}

function findCandidates(string $root): array {
  $candidates = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) continue;
    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, ['php','html','htm'], true)) continue;
    if ($file->getSize() > 1024*1024) continue; // skip >1MB
    $p = $file->getPathname();
    // Skip vendor/minified libs dir if any
    if (stripos($p, '/vendor/') !== false || stripos($p, '/assets/') !== false) continue;

    $content = @file_get_contents($p);
    if ($content === false) continue;

    // Heuristics: pages that likely host the Gantt or the drawer
    if (stripos($content, 'daypilot') !== false ||
        stripos($content, 'daypilot-lite') !== false ||
        stripos($content, 'edit-save') !== false ||
        stripos($content, 'gantt') !== false) {
      $candidates[] = $p;
    }
  }
  sort($candidates);
  return $candidates;
}

function injectIntoFile(string $fullPath, string $root, string $TAG1, string $TAG2): array {
  $rel = relpath($fullPath, $root);
  if (!is_file($fullPath)) return ['file'=>$rel, 'status'=>'FAIL', 'details'=>'Not a file'];
  $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
  if (!in_array($ext, ['php','html','htm'], true)) return ['file'=>$rel, 'status'=>'SKIP', 'details'=>'Not HTML/PHP'];

  $content = @file_get_contents($fullPath);
  if ($content === false) return ['file'=>$rel, 'status'=>'FAIL', 'details'=>'Read failed'];

  // Already present?
  $has1 = stripos($content, 'contractor-datalist.js') !== false;
  $has2 = stripos($content, 'drawer-contractor-patch.js') !== false;
  if ($has1 && $has2) {
    return ['file'=>$rel, 'status'=>'OK', 'details'=>'Scripts already present'];
  }

  $injection = ($has1 ? '' : $TAG1."\n") . ($has2 ? '' : $TAG2."\n");

  // Insert before </body>, or append at end
  $new = null;
  if (preg_match('~</body>~i', $content)) {
    $new = preg_replace('~</body>~i', $injection.'</body>', $content, 1);
  } else {
    $new = $content . "\n" . $injection;
  }

  // Backup and write
  $backup = $fullPath . '.bak.' . date('Ymd_His');
  @copy($fullPath, $backup);
  $ok = @file_put_contents($fullPath, $new);
  if ($ok === false) return ['file'=>$rel, 'status'=>'FAIL', 'details'=>'Write failed'];

  return ['file'=>$rel, 'status'=>'OK', 'details'=>'Injected '.($has1&&$has2?'0':'tags').' • backup '.basename($backup)];
}

$results = [];
$cands = [];

if ($target) {
  // Targeted injection
  $full = realpath($root . '/' . ltrim($target, '/'));
  if (!$full || strpos($full, realpath($root)) !== 0) {
    $results[] = ['file'=>$target, 'status'=>'FAIL', 'details'=>'Invalid target (outside web root or not found)'];
  } else {
    $results[] = injectIntoFile($full, $root, $TAG1, $TAG2);
  }
} else {
  // Auto-scan
  $cands = findCandidates($root);
  if ($scanOnly) {
    // No injection, just list
  } else {
    if (!$cands) {
      // Try common fallbacks anyway
      foreach (['/index.php','/index.html','/gantt.php','/gantt.html'] as $f) {
        $full = $root . $f;
        if (is_file($full)) $cands[] = $full;
      }
    }
    foreach ($cands as $p) {
      $results[] = injectIntoFile($p, $root, $TAG1, $TAG2);
    }
  }
}

// ---- Output
?><!doctype html><html><head>
<meta charset="utf-8"><title>Auto-inject Drawer Patch</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
 .wrap{max-width:960px;margin:0 auto;padding:20px}
 table{width:100%;border-collapse:collapse}
 th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
 .ok{color:#86efac}.fail{color:#fca5a5}.skip{color:#fbbf24}
 code{background:#0b1220;border:1px solid #1f2937;padding:2px 6px;border-radius:6px}
 a{color:#93c5fd}
</style></head><body><div class="wrap">
<h1>Auto-inject Drawer Patch</h1>
<p>Root: <?=htmlspecialchars($root)?></p>

<?php if ($scanOnly): ?>
  <h3>Scan results (no changes made)</h3>
  <?php if (!$cands): ?>
    <p>No likely Gantt pages auto-detected. Try a direct target:</p>
    <ul>
      <li><a href="?target=/index.php">Inject /index.php</a></li>
      <li><a href="?target=/gantt.php">Inject /gantt.php</a></li>
    </ul>
  <?php else: ?>
    <table><thead><tr><th>Candidate file</th></tr></thead><tbody>
    <?php foreach ($cands as $p): ?>
      <tr><td><?=htmlspecialchars(relpath($p,$root))?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <p>Inject into all candidates: <a href="./install_inject_drawer_patch.php">run injector</a></p>
  <?php endif; ?>
<?php else: ?>
  <h3>Injection results</h3>
  <?php if (!$results): ?>
    <p>No candidates found. You can run scan mode or specify <code>?target=/index.php</code>.</p>
  <?php else: ?>
    <table><thead><tr><th>File</th><th>Status</th><th>Details</th></tr></thead><tbody>
    <?php foreach ($results as $r): ?>
      <?php $cls = strtolower($r['status']); ?>
      <tr><td><?=htmlspecialchars($r['file'])?></td><td class="<?=$cls?>"><?=htmlspecialchars($r['status'])?></td><td><?=htmlspecialchars($r['details'])?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
<?php endif; ?>

<h3>What this injected</h3>
<pre><?=htmlspecialchars($TAG1 . "\n" . $TAG2)?></pre>

<p><strong>Next:</strong> ensure your drawer includes these fields/IDs (the patch expects them):</p>
<pre>&lt;input id="edit-name"&gt;
&lt;input id="edit-contractor-name" list="contractor-list"&gt;
&lt;datalist id="contractor-list"&gt;&lt;/datalist&gt;
&lt;button id="edit-save"&gt;Save&lt;/button&gt;</pre>

<p><strong>Security:</strong> delete this installer file after use.</p>
</div></body></html>

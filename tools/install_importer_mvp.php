<?php
/* Importer MVP • One-file Installer
 * Creates:
 *   /admin/import.html          (upload + preview + import UI)
 *   /api/import/preview.php     (parse .xlsx/.csv, detect calendar grid or list)
 *   /api/import/commit.php      (write tasks to DB, optional baseline)
 *   /scripts/migrations/imports_001.sql  (DB changes: imports + task provenance)
 *   /README_IMPORTER.txt
 *
 * Usage:
 *   1) Save as /admin/install_importer_mvp.php
 *   2) Open in browser
 *   3) Run the SQL migration it creates
 *   4) Go to /admin/import.html
 *   5) Delete this installer for security
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__); // /httpdocs

function ensure_dir(string $path): void {
  if (!is_dir($path)) { @mkdir($path, 0775, true); }
}
function write_file(string $abs, string $content): array {
  ensure_dir(dirname($abs));
  $ok = @file_put_contents($abs, $content);
  return [$ok !== false, $ok !== false ? ('wrote ' . strlen($content) . ' bytes') : ('write failed: ' . error_get_last()['message'] ?? 'unknown')];
}

/* ---------- file contents (NOWDOC blocks) ---------- */

// /admin/import.html
$admin_import_html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Import Programme</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root { --bg:#0f172a; --panel:#0b1220; --line:#1f2937; --muted:#9ca3af; --accent:#93c5fd; }
    html,body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:#e5e7eb}
    header{padding:14px 16px;background:#111827;border-bottom:1px solid var(--line);display:flex;gap:10px;align-items:center}
    a{color:#93c5fd;text-decoration:none}
    main{max-width:1100px;margin:0 auto;padding:16px}
    .card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:16px}
    label{display:block;margin:8px 0 4px 0;color:#9ca3af;font-size:13px}
    input[type="file"], select, input[type="text"]{width:100%;padding:10px;border-radius:10px;border:1px solid var(--line);background:#111827;color:#e5e7eb}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .btn{background:#2563eb;border:1px solid #1d4ed8;color:#fff;border-radius:10px;padding:10px 12px;cursor:pointer}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid var(--line);padding:6px 8px;font-size:13px}
    th{color:#cbd5e1;text-align:left}
    .muted{color:#9ca3af}
    .inline{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .pill{background:#1f2937;border:1px solid var(--line);padding:6px 10px;border-radius:999px;font-size:12px}
  </style>
</head>
<body>
  <header>
    <a href="/admin/">← Admin</a>
    <strong>Import Programme</strong>
    <span style="margin-left:auto"></span>
    <a class="pill" href="/links.html">Links</a>
    <a class="pill" href="/about.html">About</a>
  </header>
  <main>
    <div class="card">
      <h3 style="margin:0 0 8px 0">Upload file</h3>
      <p class="muted" style="margin-top:0">Accepts <strong>.xlsx</strong> (PhpSpreadsheet required) or <strong>.csv</strong> (save from Excel). The 6-week lookahead “calendar grid” is supported.</p>
      <div class="grid">
        <div>
          <label>File (.xlsx or .csv)</label>
          <input id="file" type="file" accept=".xlsx,.csv" />
        </div>
        <div>
          <label>Project ID</label>
          <input id="project" type="text" value="1" />
        </div>
      </div>

      <div class="grid" style="margin-top:8px">
        <div>
          <label><input type="checkbox" id="ignoreWeekends" checked> Ignore weekends (Mon–Fri durations)</label>
          <div class="muted">If unticked, weekend marks are imported and tasks get a 7-day calendar tag.</div>
        </div>
        <div>
          <label><input type="checkbox" id="splitSpans" checked> Split non-contiguous blocks into separate tasks</label>
          <div class="muted">Matches the visual blocks on your grid (Part 1, Part 2…).</div>
        </div>
      </div>

      <div class="grid" style="margin-top:8px">
        <div>
          <label><input type="checkbox" id="autoFS"> Auto-link FS within sections</label>
          <div class="muted">Experimental: link tasks sequentially under the same section header.</div>
        </div>
        <div>
          <label><input type="checkbox" id="baseline" checked> Create baseline after import</label>
          <div class="muted">Creates “Import – timestamp” baseline so variance is meaningful.</div>
        </div>
      </div>

      <div class="inline" style="margin-top:12px">
        <button class="btn" id="previewBtn">Preview extraction</button>
        <span id="status" class="muted"></span>
      </div>
    </div>

    <div id="preview" class="card" style="display:none">
      <h3 style="margin:0 0 8px 0">Preview</h3>
      <div id="det" class="muted"></div>
      <div style="overflow:auto;max-height:400px;border:1px solid var(--line);border-radius:8px;margin-top:8px">
        <table id="tbl"><thead></thead><tbody></tbody></table>
      </div>
      <div class="inline" style="margin-top:12px">
        <button class="btn" id="importBtn">Import into project</button>
        <span id="importStatus" class="muted"></span>
      </div>
    </div>
  </main>

<script>
let __preview = null;

document.getElementById('previewBtn').onclick = async () => {
  const f = document.getElementById('file').files[0];
  const status = document.getElementById('status');
  status.textContent = '';
  if (!f) { status.textContent = 'Choose a file first.'; return; }
  const fd = new FormData();
  fd.append('file', f);
  fd.append('project', document.getElementById('project').value || '1');
  fd.append('ignore_weekends', document.getElementById('ignoreWeekends').checked ? '1' : '0');
  fd.append('split_spans', document.getElementById('splitSpans').checked ? '1' : '0');
  fd.append('auto_fs', document.getElementById('autoFS').checked ? '1' : '0');

  status.textContent = 'Uploading…';
  const res = await fetch('/api/import/preview.php', { method:'POST', body: fd });
  const j = await res.json().catch(()=>null);
  if (!res.ok || !j || !j.ok) {
    status.textContent = 'Failed: ' + (j && j.error ? j.error : 'server error');
    return;
  }
  status.textContent = 'OK';
  __preview = j;
  renderPreview(j);
};

function renderPreview(j){
  document.getElementById('preview').style.display = '';
  const det = document.getElementById('det');
  det.textContent = `Detected: ${j.mode} • Tasks: ${j.tasks.length} • Base date: ${j.base_date || 'n/a'} • Activity col: ${j.activity_col ?? '?'} • Contractor col: ${j.contractor_col ?? '?'}`;
  const thead = document.querySelector('#tbl thead'); const tbody = document.querySelector('#tbl tbody');
  thead.innerHTML = '<tr><th>Task</th><th>Contractor</th><th>Start</th><th>Finish</th><th>Duration (wd)</th><th>Section</th></tr>';
  tbody.innerHTML = '';
  (j.tasks||[]).slice(0,500).forEach(t => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${t.name}</td><td>${t.contractor||''}</td><td>${t.start||''}</td><td>${t.finish||''}</td><td>${t.duration_days??''}</td><td>${t.section||''}</td>`;
    tbody.appendChild(tr);
  });
}

document.getElementById('importBtn').onclick = async () => {
  const status = document.getElementById('importStatus');
  status.textContent = 'Importing…';
  if (!__preview) { status.textContent = 'No preview'; return; }
  const payload = {
    project: document.getElementById('project').value || '1',
    ignore_weekends: document.getElementById('ignoreWeekends').checked,
    split_spans: document.getElementById('splitSpans').checked,
    auto_fs: document.getElementById('autoFS').checked,
    baseline: document.getElementById('baseline').checked,
    tasks: __preview.tasks,
    mode: __preview.mode,
    base_date: __preview.base_date,
    mapping: __preview.mapping || {}
  };
  const who = await (await fetch('/api/auth.php?action=whoami')).json();
  const res = await fetch('/api/import/commit.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF':who.csrf},
    body: JSON.stringify(payload)
  });
  const j = await res.json().catch(()=>null);
  if (!res.ok || !j || !j.ok) { status.textContent = 'Failed: ' + (j && j.error ? j.error : 'server error'); return; }
  status.innerHTML = 'Imported. <a href="/">Open Gantt</a>';
};
</script>
</body>
</html>
HTML;

// /api/import/preview.php
$api_preview_php = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

use App\Config\DB;
use App\Lib\WorkingDays;

header('Content-Type: application/json');

$project = (int)($_POST['project'] ?? 1);
$ignoreWeekends = isset($_POST['ignore_weekends']) && $_POST['ignore_weekends']=='1';
$splitSpans = isset($_POST['split_spans']) && $_POST['split_spans']=='1';
$autoFS = isset($_POST['auto_fs']) && $_POST['auto_fs']=='1';

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No file uploaded']); exit;
}

$fname = $_FILES['file']['name'];
$tmp = $_FILES['file']['tmp_name'];
$ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));

// Parse base date like "Date 11.08.25" or "Date 11/08/2025"
function parse_base_date(string $s): ?string {
  $s = trim($s);
  if (preg_match('~date\\s+(\\d{1,2})[\\.\\/-](\\d{1,2})[\\.\\/-](\\d{2,4})~i', $s, $m)) {
    $y = (int)$m[3]; if ($y < 100) $y += 2000;
    return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
  }
  return null;
}

// Inclusive working-day count using project's calendar
function wd_count_inclusive(WorkingDays $wd, string $start, string $finish, bool $ignoreWeekends): int {
  $s = new DateTimeImmutable($start); $f = new DateTimeImmutable($finish);
  if ($f < $s) return 0;
  $n=0; $d=$s;
  while ($d <= $f) {
    if ($ignoreWeekends ? $wd->isWorkingDay($d) : true) $n++;
    $d = $d->add(new DateInterval('P1D'));
  }
  return $n;
}

// Load calendar
$pdo = DB::pdo();
$calId = (int)$pdo->query("SELECT calendar_id FROM projects WHERE id=".$project)->fetchColumn();
$wd = new WorkingDays($calId);

// CSV loader
function load_csv_rows(string $path): array {
  $rows = []; $h = fopen($path, 'r'); if (!$h) return [];
  while (($r = fgetcsv($h)) !== false) { $rows[] = $r; }
  fclose($h); return $rows;
}

// XLSX loader (PhpSpreadsheet)
function load_xlsx_cells(string $path): array {
  if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    return ['__ERROR__' => 'PhpSpreadsheet not installed. Install with Composer: composer require phpoffice/phpspreadsheet — or upload a CSV instead.'];
  }
  $io = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
  $sheet = $io->getActiveSheet();
  $maxRow = $sheet->getHighestRow();
  $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
  $rows = [];
  for ($r=1; $r<=$maxRow; $r++) {
    $row = [];
    for ($c=1; $c<=$maxCol; $c++) {
      $row[] = trim((string)$sheet->getCellByColumnAndRow($c,$r)->getFormattedValue());
    }
    $rows[] = $row;
  }
  return $rows;
}

$grid = [];
if ($ext === 'csv') {
  $grid = load_csv_rows($tmp);
} else if ($ext === 'xlsx') {
  $grid = load_xlsx_cells($tmp);
  if (isset($grid['__ERROR__'])) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>$grid['__ERROR__']]); exit;
  }
} else {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unsupported file type; use .xlsx or .csv']); exit;
}

if (!$grid || !is_array($grid) || count($grid) < 2) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'File is empty or unreadable']); exit;
}

// Detect headers
$activityCol = null; $contractorCol = null; $baseDate = null; $dayRow = null; $numRow = null; $dateCols = [];

// Base date + weekday/number rows
for ($r=0; $r<min(15, count($grid)); $r++) {
  for ($c=0; $c<min(120, count($grid[$r])); $c++) {
    $v = trim((string)($grid[$r][$c] ?? ''));
    if ($v === '') continue;
    $bd = parse_base_date($v);
    if ($bd && !$baseDate) { $baseDate = $bd; }
  }
  $vals = array_map('strtoupper', array_map('trim', $grid[$r]));
  $letters = array_filter($vals, function($x){ return in_array($x, ['M','T','W','F','S','SU','SAT','SUN','MON','TUE','WED','THU','FRI']); });
  if (count($letters) >= 5 && $dayRow === null) { $dayRow = $r; }
  $nums = array_filter($vals, function($x){ return preg_match('~^\\d{1,2}$~', $x); });
  if (count($nums) >= 5 && $numRow === null && $dayRow !== null && $r >= $dayRow) { $numRow = $r; }
}

// Find "Activity" / "Subcontractor"
$headerRowIdx = null;
for ($r=0; $r<min(20,count($grid)); $r++) {
  $row = $grid[$r];
  $hasHeader = false;
  foreach ($row as $idx=>$cell) {
    $cv = strtolower(trim((string)$cell));
    if (in_array($cv, ['activity','task','description'])) { $activityCol = $idx; $hasHeader = true; }
    if (in_array($cv, ['subcontractor','contractor'])) { $contractorCol = $idx; $hasHeader = true; }
  }
  if ($hasHeader && $headerRowIdx === null) $headerRowIdx = $r;
}

// Build date columns from base date + first number cell
if ($numRow !== null && $baseDate !== null) {
  $startCol = null;
  for ($c=0; $c<count($grid[$numRow]); $c++) {
    $cell = trim((string)($grid[$numRow][$c] ?? ''));
    if (preg_match('~^\\d{1,2}$~', $cell)) { $startCol = $c; break; }
  }
  if ($startCol !== null) {
    $d = new DateTimeImmutable($baseDate);
    for ($c=$startCol; $c<count($grid[$numRow]); $c++) {
      $cell = trim((string)($grid[$numRow][$c] ?? ''));
      if ($cell === '') break;
      if (!preg_match('~^\\d{1,2}$~', $cell)) continue;
      $dateCols[$c] = $d->format('Y-m-d');
      $d = $d->add(new DateInterval('P1D'));
    }
  }
}

// Fallback: list mode if grid not detected
$mode = 'calendar-grid';
if (!$dateCols || $activityCol === null) { $mode = 'list'; }

$tasks = [];
$mapping = [
  'activity_col' => $activityCol,
  'contractor_col' => $contractorCol,
  'day_row' => $dayRow,
  'num_row' => $numRow,
  'date_cols' => array_values($dateCols),
];

if ($mode === 'calendar-grid') {
  $startRow = max($headerRowIdx !== null ? $headerRowIdx+1 : 0, ($numRow !== null ? $numRow+1 : 0));
  $section = '';
  for ($r=$startRow; $r<count($grid); $r++) {
    $row = $grid[$r];
    $name = trim((string)($row[$activityCol] ?? ''));
    if ($name === '') continue;

    // Section header = name but no marks & no contractor
    $hasMark = false;
    foreach ($dateCols as $dc => $dt) {
      $val = trim((string)($row[$dc] ?? ''));
      if ($val !== '') { $hasMark = true; break; }
    }
    $contr = $contractorCol !== null ? trim((string)($row[$contractorCol] ?? '')) : '';

    if (!$hasMark && $contr === '') {
      $section = $name;
      continue;
    }

    // Collect marked days -> runs
    $marks = [];
    foreach ($dateCols as $dc => $dt) {
      $val = trim((string)($row[$dc] ?? ''));
      if ($val !== '') {
        if ($ignoreWeekends) {
          $dow = (int)(new DateTimeImmutable($dt))->format('N'); // 1..7 Mon..Sun
          if ($dow <= 5) $marks[$dt] = true;
        } else {
          $marks[$dt] = true;
        }
      }
    }
    if (!$marks) continue;

    $dates = array_keys($marks);
    sort($dates);
    $runs = [];
    $runStart = $dates[0];
    $prev = new DateTimeImmutable($dates[0]);
    for ($i=1; $i<count($dates); $i++) {
      $cur = new DateTimeImmutable($dates[$i]);
      $gap = $cur->diff($prev)->days;
      if ($gap !== 1) {
        $runs[] = [$runStart, $prev->format('Y-m-d')];
        $runStart = $dates[$i];
      }
      $prev = $cur;
    }
    $runs[] = [$runStart, $prev->format('Y-m-d')];

    // Split or merge spans
    if (!$splitSpans && count($runs) > 1) {
      $runs = [ [$runs[0][0], end($runs)[1]] ];
    }

    foreach ($runs as [$s,$f]) {
      $tasks[] = [
        'name' => $name,
        'contractor' => $contr ?: null,
        'start' => $s,
        'finish' => $f,
        'duration_days' => wd_count_inclusive($wd, $s, $f, $ignoreWeekends),
        'section' => $section ?: null,
      ];
    }
  }
} else {
  // Simple list mode (Task/Contractor/Start/Finish/Duration)
  $hdr = $grid[0];
  $idx = [];
  foreach ($hdr as $i=>$h) { $idx[strtolower(trim((string)$h))] = $i; }
  $cTask = $idx['task'] ?? $idx['activity'] ?? $idx['description'] ?? null;
  $cContr = $idx['contractor'] ?? $idx['subcontractor'] ?? null;
  $cStart = $idx['start'] ?? $idx['start_date'] ?? null;
  $cFinish = $idx['finish'] ?? $idx['finish_date'] ?? null;
  $cDur = $idx['duration'] ?? $idx['duration_days'] ?? null;

  if ($cTask === null) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Could not detect Task/Activity column in list mode']); exit;
  }

  $norm = function($s){
    $s=trim((string)$s); if($s==='')return null;
    if(preg_match('~^(\\d{4})-(\\d{2})-(\\d{2})$~',$s))return $s;
    if(preg_match('~^(\\d{1,2})/(\\d{1,2})/(\\d{2,4})$~',$s,$m)){ $y=(int)$m[3]; if($y<100)$y+=2000; return sprintf('%04d-%02d-%02d',$y,(int)$m[2],(int)$m[1]); }
    $ts=strtotime($s); return $ts?date('Y-m-d',$ts):null;
  };

  for ($r=1; $r<count($grid); $r++) {
    $row = $grid[$r];
    $name = trim((string)($row[$cTask] ?? '')); if ($name==='') continue;
    $contr = $cContr !== null ? trim((string)($row[$cContr] ?? '')) : null;
    $start = $cStart !== null ? $norm($row[$cStart] ?? '') : null;
    $finish = $cFinish !== null ? $norm($row[$cFinish] ?? '') : null;
    $dur = $cDur !== null ? (int)$row[$cDur] : null;
    if ($start && $finish) {
      $dur = wd_count_inclusive($wd, $start, $finish, $ignoreWeekends);
    }
    $tasks[] = ['name'=>$name,'contractor'=>$contr,'start'=>$start,'finish'=>$finish,'duration_days'=>$dur ?? null, 'section'=>null];
  }
}

echo json_encode([
  'ok'=>true,
  'mode'=>$mode,
  'base_date'=>$baseDate,
  'activity_col'=>$activityCol,
  'contractor_col'=>$contractorCol,
  'mapping'=>$mapping,
  'tasks'=>$tasks,
]);
PHP;

// /api/import/commit.php
$api_commit_php = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

use App\Config\DB;
use App\Lib\WorkingDays;
use App\Lib\Scheduler;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST only']); exit; }
require_role(['admin','planner']); csrf_check();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$project = (int)($body['project'] ?? 1);
$ignoreWeekends = !empty($body['ignore_weekends']);
$baselineAfter = !empty($body['baseline']);
$tasks = $body['tasks'] ?? [];
$mode = $body['mode'] ?? 'calendar-grid';
$baseDate = $body['base_date'] ?? null;
$mapping = $body['mapping'] ?? [];

$pdo = DB::pdo();
$pdo->beginTransaction();

try {
  // Ensure a placeholder apartment exists (block = 'Imported')
  $st = $pdo->prepare("SELECT id FROM apartments WHERE project_id=? AND block='Imported' LIMIT 1");
  $st->execute([$project]);
  $aptId = (int)$st->fetchColumn();
  if (!$aptId) {
    $insA = $pdo->prepare("INSERT INTO apartments (project_id, block, floor, unit, type) VALUES (?, 'Imported', NULL, NULL, 'Schedule')");
    $insA->execute([$project]);
    $aptId = (int)$pdo->lastInsertId();
  }

  // Contractor lookup/create
  $findC = $pdo->prepare("SELECT id FROM contractors WHERE LOWER(name)=LOWER(?) LIMIT 1");
  $insC  = $pdo->prepare("INSERT INTO contractors (name, colour) VALUES (?, '#60a5fa')");

  // imports record
  $insImp = $pdo->prepare("INSERT INTO imports (project_id, filename, mapping_json, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
  $userId = current_user_id();
  $filename = 'upload';
  $insImp->execute([$project, $filename, json_encode(['mode'=>$mode,'base_date'=>$baseDate,'mapping'=>$mapping]), $userId]);
  $importId = (int)$pdo->lastInsertId();

  // calendar
  $calId = (int)$pdo->query("SELECT calendar_id FROM projects WHERE id=".$project)->fetchColumn();
  $wd = new WorkingDays($calId);

  // Insert tasks (use minimal required columns to fit your schema)
  $insT = $pdo->prepare("INSERT INTO tasks (project_id, apartment_id, name, contractor_id, operatives, duration_days, constraint_start, start_date, finish_date, source_import_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

  $count = 0;
  foreach ($tasks as $t) {
    $name = trim((string)($t['name'] ?? ''));
    if ($name === '') continue;
    $contractor = trim((string)($t['contractor'] ?? ''));
    $start = $t['start'] ?? null;
    $finish = $t['finish'] ?? null;
    $ops = 2; // default
    $dur = $t['duration_days'] ?? null;

    // contractor id
    $cid = null;
    if ($contractor !== '') {
      $findC->execute([$contractor]);
      $cid = (int)$findC->fetchColumn();
      if (!$cid) { $insC->execute([$contractor]); $cid = (int)$pdo->lastInsertId(); }
    } else {
      $cid = null;
    }

    if ($start && $finish && !$dur) {
      $s = new DateTimeImmutable($start);
      $f = new DateTimeImmutable($finish);
      $n=0; $d=$s;
      while ($d <= $f) {
        if ($ignoreWeekends ? $wd->isWorkingDay($d) : true) $n++;
        $d=$d->add(new DateInterval('P1D'));
      }
      $dur = max(1,$n);
    }
    if (!$dur) $dur = 1;

    $constraint = $start; // SNET as imported start; users can edit later

    $insT->execute([$project, $aptId, $name, $cid, $ops, $dur, $constraint, $start, $finish, $importId]);
    $count++;
  }

  // Recalc
  (new Scheduler($project))->recalc();

  // Optional baseline
  if ($baselineAfter) {
    $lbl = 'Import - ' . date('Y-m-d H:i');
    $insB = $pdo->prepare("INSERT INTO baselines (project_id, label, created_at) VALUES (?, ?, NOW())");
    $insB->execute([$project, $lbl]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true, 'imported'=>$count]);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
PHP;

// /scripts/migrations/imports_001.sql
$migration_sql = <<<'SQL'
-- Importer MVP schema additions

CREATE TABLE IF NOT EXISTS imports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mapping_json JSON NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  INDEX (project_id),
  CONSTRAINT fk_imports_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: track provenance of tasks created by the importer
ALTER TABLE tasks
  ADD COLUMN IF NOT EXISTS source_import_id INT NULL,
  ADD INDEX source_import_id (source_import_id);

-- Add the FK separately to avoid failures if column already exists
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_tasks_import');
SET @sql := IF(@fk_exists=0, 'ALTER TABLE tasks ADD CONSTRAINT fk_tasks_import FOREIGN KEY (source_import_id) REFERENCES imports(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SQL;

// README
$readme_txt = <<<'TXT'
Importer MVP • Read Me
======================

Adds:
- /admin/import.html — upload an XLSX/CSV and preview extracted tasks
- /api/import/preview.php — parses the file; supports:
    * Mode A: Calendar grid (like a 6-week lookahead)
    * Mode B: Simple list (Task/Contractor/Start/Finish/Duration)
- /api/import/commit.php — writes tasks into the project and optionally creates a baseline
- /scripts/migrations/imports_001.sql — DB changes (imports table + task provenance on tasks)

Requirements
------------
- PHP 8.x (present)
- PhpSpreadsheet for .xlsx support:
    composer require phpoffice/phpspreadsheet
  (CSV works without it.)

DB migration
------------
Run /scripts/migrations/imports_001.sql in your DB (phpMyAdmin → SQL tab).

How calendar-grid detection works
---------------------------------
- Finds a “Date 11.08.25” style base date near the top rows.
- Finds a weekday row (M T W T F S S) and a number row beneath.
- Builds continuous date columns rightwards.
- For each activity row, contiguous marked cells become a task (or split into multiple tasks).
- Weekends ignored by default (toggle on the form).

Commit behaviour
----------------
- Creates/uses an apartment placeholder: block = “Imported”.
- Creates contractors if names are new.
- Sets constraint_start = start, writes start/finish, duration (working days).
- Recalculates the project; optional baseline “Import – timestamp”.

Notes
-----
- Non-contiguous spans per row can be split (default) or merged (toggle).
- Section header rows (no marks) are captured as “section” in the preview (future grouping).
- If PhpSpreadsheet isn’t installed, .xlsx upload returns a clear error; use CSV or install the package.
TXT;

/* ---------- write files ---------- */
$targets = [
  '/admin/import.html'                   => $admin_import_html,
  '/api/import/preview.php'              => $api_preview_php,
  '/api/import/commit.php'               => $api_commit_php,
  '/scripts/migrations/imports_001.sql'  => $migration_sql,
  '/README_IMPORTER.txt'                 => $readme_txt,
];

$rows = [];
foreach ($targets as $rel => $content) {
  $abs = $ROOT . $rel;
  [$ok, $msg] = write_file($abs, $content);
  $rows[] = [$rel, $ok ? 'OK' : 'FAIL', $msg];
}

/* ---------- output ---------- */
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><title>Importer MVP • Installer</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
 .wrap{max-width:920px;margin:0 auto;padding:20px}
 table{width:100%;border-collapse:collapse} th,td{padding:8px;border-bottom:1px solid #1f2937}
 .ok{color:#86efac}.fail{color:#fca5a5} a{color:#93c5fd}
 .pill{background:#1f2937;border:1px solid #1f2937;padding:6px 10px;border-radius:999px;font-size:12px;color:#e5e7eb;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
  <h1>Importer MVP • Installer</h1>
  <p>Root: <?=htmlspecialchars($ROOT)?></p>
  <table><thead><tr><th>File</th><th>Status</th><th>Details</th></tr></thead><tbody>
  <?php foreach ($rows as [$rel,$st,$msg]): ?>
    <tr><td><?=htmlspecialchars($rel)?></td><td class="<?=strtolower($st)==='ok'?'ok':'fail'?>"><?=htmlspecialchars($st)?></td><td><?=htmlspecialchars($msg)?></td></tr>
  <?php endforeach; ?>
  </tbody></table>

  <h3>Next steps</h3>
  <ol>
    <li>Run the SQL migration at <code>/scripts/migrations/imports_001.sql</code> (phpMyAdmin → SQL).</li>
    <li>(Optional for .xlsx) Install PhpSpreadsheet with Composer in <code>/httpdocs</code>:
      <pre>composer require phpoffice/phpspreadsheet</pre>
      If not installed, you can upload CSV instead.
    </li>
    <li>Open <a href="/admin/import.html">/admin/import.html</a> and try a preview/import.</li>
    <li><strong>Security:</strong> delete this installer file when finished.</li>
  </ol>

  <p>
    Quick links:
    <a class="pill" href="/admin/import.html">Importer</a>
    <a class="pill" href="/admin/health.php">Health</a>
    <a class="pill" href="/">Gantt</a>
  </p>
</div>
</body>
</html>

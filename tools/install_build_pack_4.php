<?php
/* install_build_pack_4.php  —  writes Build Pack 4 files to /httpdocs
 * Steps: upload to /httpdocs/admin/, run once in browser, then delete.
 */
declare(strict_types=1);
$root = dirname(__DIR__); // /httpdocs

$files = [];

/* ---------- api/_bootstrap.php ---------- */
$files['api/_bootstrap.php'] = <<<'PHP'
<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__, 1);  // /httpdocs

spl_autoload_register(function(string $class) use ($ROOT) {
  $prefix = 'App\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
  $rel = substr($class, strlen($prefix));
  $primary = $ROOT . '/app/' . str_replace('\\','/',$rel) . '.php';
  $fallback = $ROOT . '/app/' . strtolower(str_replace('\\','/',$rel)) . '.php';
  if (is_file($primary)) { require $primary; return; }
  if (is_file($fallback)) { require $fallback; return; }
});

require $ROOT . '/app/Config/DB.php';
require $ROOT . '/app/Config/config.php';

@session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function current_user(): ?array { return $_SESSION['user'] ?? null; }
function user_role(): ?string { return $_SESSION['user']['role'] ?? null; }
function is_logged_in(): bool { return !empty($_SESSION['user']); }

function require_role($roles): void {
  if (!is_array($roles)) $roles = [$roles];
  $role = user_role();
  if (!$role || !in_array($role, $roles, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
  }
}
function csrf_check(): void {
  $given = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
  if (!$given || !hash_equals($_SESSION['csrf'], $given)) {
    http_response_code(419);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'csrf_failed']);
    exit;
  }
}
PHP;

/* ---------- api/auth.php ---------- */
$files['api/auth.php'] = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');
$pdo = DB::pdo();
$action = $_GET['action'] ?? 'whoami';

if ($action === 'whoami') {
  echo json_encode(['ok'=>true,'user'=>current_user(),'csrf'=>$_SESSION['csrf']]); exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
  $body = json_decode(file_get_contents('php://input'), true) ?: [];
  $email = trim((string)($body['email'] ?? ''));
  $pass  = (string)($body['password'] ?? '');
  $st = $pdo->prepare("SELECT id,name,email,role,password_hash FROM users WHERE email=?");
  $st->execute([$email]);
  $u = $st->fetch();
  if ($u && password_verify($pass, $u['password_hash'])) {
    $_SESSION['user'] = ['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']];
    echo json_encode(['ok'=>true,'user'=>current_user(),'csrf'=>$_SESSION['csrf']]); exit;
  }
  http_response_code(401); echo json_encode(['ok'=>false,'error'=>'invalid_login']); exit;
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $_SESSION = [];
  @session_destroy();
  echo json_encode(['ok'=>true]); exit;
}

if ($action === 'create_user' && $_SERVER['REQUEST_METHOD']==='POST') {
  $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  if ($count > 0) { require_role('admin'); csrf_check(); }
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  foreach (['name','email','password','role'] as $f) if (empty($b[$f])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>"missing_$f"]); exit; }
  $hash = password_hash((string)$b['password'], PASSWORD_DEFAULT);
  $st = $pdo->prepare("INSERT INTO users (name,email,role,password_hash) VALUES (?,?,?,?)");
  $st->execute([$b['name'],$b['email'],$b['role'],$hash]);
  echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
}

http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown_action']);
PHP;

/* ---------- api/raw.php ---------- */
$files['api/raw.php'] = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
use App\Config\DB;

header('Content-Type: application/json');
$me = current_user();
if (!$me || $me['role']!=='admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

$sql = $_GET['sql'] ?? '';
if (!preg_match('~^\s*SELECT\s~i', $sql)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'select_only']); exit; }

$pdo = DB::pdo();
$st = $pdo->query($sql);
echo json_encode(['ok'=>true,'rows'=>$st->fetchAll()]);
PHP;

/* ---------- api/templates.php ---------- */
$files['api/templates.php'] = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;
use App\Lib\Scheduler;

header('Content-Type: application/json');
$pdo = DB::pdo();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

function ok($d){ echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function bad($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

if ($action === 'list' && $method==='GET') {
  $st = $pdo->query("SELECT * FROM templates ORDER BY id DESC");
  ok($st->fetchAll());
}

if ($action === 'create' && $method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  if (empty($b['name'])) bad('name required');
  $pdo->prepare("INSERT INTO templates (name, description) VALUES (?,?)")->execute([$b['name'],$b['description'] ?? null]);
  ok(['id'=>$pdo->lastInsertId()]);
}

if ($action === 'from_apartment' && $method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  $apt = (int)($b['apartment_id'] ?? 0);
  $name = $b['name'] ?? ('Template from apt '.$apt);
  if (!$apt) bad('apartment_id required');

  $pdo->prepare("INSERT INTO templates (name) VALUES (?)")->execute([$name]);
  $tid = (int)$pdo->lastInsertId();

  $tasks = $pdo->prepare("SELECT * FROM tasks WHERE apartment_id=? ORDER BY start_date IS NULL, start_date, id");
  $tasks->execute([$apt]); $tasks = $tasks->fetchAll();
  if (!$tasks) ok(['id'=>$tid,'note'=>'no tasks in apartment']);

  $codeMap=[]; $i=1;
  foreach ($tasks as $t) {
    $code = sprintf('T%02d',$i++);
    $pdo->prepare("INSERT INTO template_tasks (template_id, code, name, contractor_id, operatives, duration_days, zone, is_milestone)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$tid,$code,$t['name'],$t['contractor_id'],$t['operatives'],$t['duration_days'],$t['zone'],$t['is_milestone']]);
    $codeMap[$t['id']] = $code;
  }

  $ids = implode(',', array_map('intval', array_keys($codeMap)));
  if ($ids) {
    $dep = $pdo->query("SELECT task_id, predecessor_id, type, lag_days FROM dependencies WHERE task_id IN ($ids)");
    while ($d = $dep->fetch()) {
      $pdo->prepare("INSERT INTO template_dependencies (template_id, task_code, predecessor_code, type, lag_days)
                     VALUES (?,?,?,?,?)")
          ->execute([$tid, $codeMap[$d['task_id']], $codeMap[$d['predecessor_id']] ?? null, $d['type'], (int)$d['lag_days']]);
    }
  }
  ok(['id'=>$tid,'tasks'=>count($tasks)]);
}

if ($action === 'detail' && $method==='GET') {
  $tid = (int)($_GET['id'] ?? 0); if (!$tid) bad('id required');
  $t = $pdo->prepare("SELECT * FROM templates WHERE id=?"); $t->execute([$tid]); $tpl=$t->fetch();
  if (!$tpl) bad('not found',404);
  $tt = $pdo->prepare("SELECT * FROM template_tasks WHERE template_id=? ORDER BY id"); $tt->execute([$tid]); $tasks=$tt->fetchAll();
  $td = $pdo->prepare("SELECT * FROM template_dependencies WHERE template_id=? ORDER BY id"); $td->execute([$tid]); $deps=$td->fetchAll();
  ok(['template'=>$tpl,'tasks'=>$tasks,'dependencies'=>$deps]);
}

if ($action === 'clone' && $method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  $tid = (int)($b['template_id'] ?? 0);
  $project = (int)($b['project_id'] ?? 1);
  $apartment_ids = array_filter(array_map('intval', $b['apartment_ids'] ?? []));
  if (!$tid || !$apartment_ids) bad('template_id and apartment_ids required');

  $tt = $pdo->prepare("SELECT * FROM template_tasks WHERE template_id=? ORDER BY id"); $tt->execute([$tid]); $tasks=$tt->fetchAll();
  $td = $pdo->prepare("SELECT * FROM template_dependencies WHERE template_id=?"); $td->execute([$tid]); $deps=$td->fetchAll();

  $createdTotal=0;
  foreach ($apartment_ids as $apt) {
    $newIdByCode=[];
    foreach ($tasks as $t) {
      $pdo->prepare("INSERT INTO tasks (project_id, apartment_id, name, contractor_id, operatives, duration_days, zone, is_milestone)
                     VALUES (?,?,?,?,?,?,?,?)")
          ->execute([$project,$apt,$t['name'],$t['contractor_id'],$t['operatives'],$t['duration_days'],$t['zone'],$t['is_milestone']]);
      $newIdByCode[$t['code']] = (int)$pdo->lastInsertId();
      $createdTotal++;
    }
    foreach ($deps as $d) {
      $to   = $newIdByCode[$d['task_code']] ?? null;
      $pred = $newIdByCode[$d['predecessor_code']] ?? null;
      if ($to && $pred) {
        $pdo->prepare("INSERT INTO dependencies (task_id, predecessor_id, type, lag_days) VALUES (?,?,?,?)")
            ->execute([$to,$pred,$d['type'],(int)$d['lag_days']]);
      }
    }
    (new Scheduler($project))->recalc();
  }
  ok(['created'=>$createdTotal]);
}

bad('unknown_action',400);
PHP;

/* ---------- api/baselines.php ---------- */
$files['api/baselines.php'] = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');
$pdo = DB::pdo();
$method = $_SERVER['REQUEST_METHOD'];

function ok($d){ echo json_encode(['ok'=>true,'data'=>$d]); exit; }
function bad($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

if ($method==='GET') {
  $p=(int)($_GET['project']??1);
  $st=$pdo->prepare("SELECT * FROM baselines WHERE project_id=? ORDER BY id DESC");
  $st->execute([$p]); ok($st->fetchAll());
}

if ($method==='POST') {
  require_role(['admin','planner']); csrf_check();
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  $p = (int)($b['project_id'] ?? 1);
  $label = $b['label'] ?? ('Baseline '.date('Y-m-d H:i'));
  $pdo->prepare("UPDATE tasks SET baseline_start = start_date, baseline_finish = finish_date WHERE project_id=?")->execute([$p]);
  $pdo->prepare("INSERT INTO baselines (project_id, label) VALUES (?,?)")->execute([$p,$label]);
  ok(['saved'=>true, 'label'=>$label]);
}

bad('unsupported',405);
PHP;

/* ---------- api/analytics.php ---------- */
$files['api/analytics.php'] = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;
use App\Lib\WorkingDays;

header('Content-Type: application/json');

$pdo = DB::pdo();
$project = (int)($_GET['project'] ?? 1);
$type = $_GET['type'] ?? 'workforce';

$calId = (int)$pdo->query("SELECT calendar_id FROM projects WHERE id=$project")->fetchColumn();
$wd = new WorkingDays($calId);

$st = $pdo->prepare("SELECT t.*, c.name contractor FROM tasks t LEFT JOIN contractors c ON c.id=t.contractor_id WHERE t.project_id=?");
$st->execute([$project]);
$all = $st->fetchAll();

function workdays_between(string $a, string $b, WorkingDays $wd): int {
  $d1 = new DateTimeImmutable($a);
  $d2 = new DateTimeImmutable($b);
  if ($d1 == $d2) return 0;
  $step = ($d1 < $d2) ? 1 : -1;
  $cnt = 0; $d = $d1;
  while (($step>0 && $d < $d2) || ($step<0 && $d > $d2)) {
    if ($wd->isWorkingDay($d)) $cnt += $step;
    $d = $d->add(new DateInterval('P1D'));
  }
  return $cnt;
}

if ($type === 'workforce') {
  $byDay = []; $byDayContractor=[];
  foreach ($all as $t) {
    if (!$t['start_date'] || !$t['finish_date']) continue;
    $d1 = new DateTimeImmutable($t['start_date']);
    $d2 = new DateTimeImmutable($t['finish_date']);
    for ($d=$d1; $d <= $d2; $d=$d->add(new DateInterval('P1D'))) {
      if (!$wd->isWorkingDay($d)) continue;
      $k=$d->format('Y-m-d');
      $byDay[$k] = ($byDay[$k] ?? 0) + (int)$t['operatives'];
      $c = $t['contractor'] ?: 'Unassigned';
      $byDayContractor[$k][$c] = ($byDayContractor[$k][$c] ?? 0) + (int)$t['operatives'];
    }
  }
  ksort($byDay);
  echo json_encode(['ok'=>true,'type'=>'workforce','labels'=>array_keys($byDay),'total'=>array_values($byDay),'stack'=>$byDayContractor]); exit;
}

if ($type === 'tight') {
  $weekly = [];
  foreach ($all as $t) {
    if (empty($t['alerts_json'])) continue;
    $alerts = json_decode($t['alerts_json'], true) ?: [];
    if (empty($alerts['tight'])) continue;
    if (!$t['start_date']) continue;
    $week = (new DateTimeImmutable($t['start_date']))->format('o-\\WW');
    $c = $t['contractor'] ?: 'Unassigned';
    $weekly[$week][$c] = ($weekly[$week][$c] ?? 0) + count($alerts['tight']);
  }
  ksort($weekly);
  echo json_encode(['ok'=>true,'type'=>'tight','weeks'=>array_keys($weekly),'data'=>$weekly]); exit;
}

if ($type === 'throughput') {
  $startW=[]; $finishW=[];
  foreach ($all as $t) {
    if ($t['start_date'])  { $w=(new DateTimeImmutable($t['start_date']))->format('o-\\WW'); $startW[$w]=($startW[$w]??0)+1; }
    if ($t['finish_date']) { $w=(new DateTimeImmutable($t['finish_date']))->format('o-\\WW'); $finishW[$w]=($finishW[$w]??0)+1; }
  }
  $weeks = array_values(array_unique(array_merge(array_keys($startW), array_keys($finishW)))); sort($weeks);
  $s=[]; $f=[]; foreach ($weeks as $w){ $s[]=$startW[$w]??0; $f[]=$finishW[$w]??0; }
  echo json_encode(['ok'=>true,'type'=>'throughput','weeks'=>$weeks,'started'=>$s,'finished'=>$f]); exit;
}

if ($type === 'variance') {
  $rows=[];
  foreach ($all as $t) {
    if (!$t['baseline_start'] && !$t['baseline_finish']) continue;
    $vs = null; $vf = null;
    if ($t['baseline_start'] && $t['start_date'])  $vs = workdays_between($t['baseline_start'],$t['start_date'],$wd);
    if ($t['baseline_finish'] && $t['finish_date']) $vf = workdays_between($t['baseline_finish'],$t['finish_date'],$wd);
    $rows[] = [
      'id'=>$t['id'],'task'=>$t['name'],'contractor'=>$t['contractor'],
      'baseline_start'=>$t['baseline_start'],'start'=>$t['start_date'],'start_slip_days'=>$vs,
      'baseline_finish'=>$t['baseline_finish'],'finish'=>$t['finish_date'],'finish_slip_days'=>$vf
    ];
  }
  echo json_encode(['ok'=>true,'type'=>'variance','rows'=>$rows]); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown type']);
PHP;

/* ---------- api/export/shortterm.php ---------- */
$files['api/export/shortterm.php'] = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

use App\Config\DB;
use App\Lib\WorkingDays;

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug) { @ini_set('display_errors','1'); error_reporting(E_ALL); }
register_shutdown_function(function() use ($debug) {
  if (!$debug) return;
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "FATAL: {$e['message']} in {$e['file']}:{$e['line']}";
  }
});

$pdo     = DB::pdo();
$project = (int)($_GET['project'] ?? 1);
$days    = max(7, (int)($_GET['days'] ?? 14));
$group   = ($_GET['group'] ?? 'apartment');
$from    = $_GET['from'] ?? date('Y-m-d');
$format  = ($_GET['format'] ?? 'pdf');
$nofpdf  = isset($_GET['nofpdf']) && $_GET['nofpdf'] == '1';

$proj = $pdo->prepare("SELECT start_date, calendar_id, name FROM projects WHERE id=?");
$proj->execute([$project]);
$p = $proj->fetch();
if (!$p) { http_response_code(404); exit('Project not found'); }

$wd = new WorkingDays((int)$p['calendar_id']);
$start = new DateTimeImmutable($from);

$dates = [];
$d = $start;
while (count($dates) < $days) {
  if ($wd->isWorkingDay($d)) $dates[] = $d->format('Y-m-d');
  $d = $d->add(new DateInterval('P1D'));
}
$winStart = reset($dates);
$winEnd   = end($dates);

$sql = "SELECT t.*, c.name contractor, c.colour,
               a.block, a.floor, a.unit, a.type AS apt_type
        FROM tasks t
        LEFT JOIN contractors c ON c.id=t.contractor_id
        JOIN apartments a ON a.id=t.apartment_id
        WHERE t.project_id=? AND t.start_date <= ? AND t.finish_date >= ?
        ORDER BY a.block, a.floor, a.unit, t.start_date, t.id";
$st = $pdo->prepare($sql);
$st->execute([$project, $winEnd, $winStart]);
$rows = $st->fetchAll();

$uk = function(?string $ymd): string {
  if (!$ymd) return '';
  try { return (new DateTimeImmutable($ymd))->format('d/m/Y'); }
  catch (Throwable) { return $ymd; }
};
$txt = function(string $s): string {
  $s = str_replace(["\u{2013}", "\u{2022}", "\u{2192}"], ['-', '•', '->'], $s);
  $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
  return $out !== false ? $out : $s;
};

$bucketed = [];
foreach ($rows as $r) {
  $apt = trim(($r['block'] ?? '') . ' ' . ($r['floor'] ?? '') . ' ' . ($r['unit'] ?? '') . ' ' . ($r['apt_type'] ?? ''));
  $apt = ($apt !== '') ? $apt : 'Apartment';
  $key = ($group === 'contractor')
    ? ((($r['contractor'] ?? '') !== '') ? $r['contractor'] : 'Unassigned')
    : $apt;
  $bucketed[$key][] = $r;
}

$render_html = function(string $title, array $dates, array $bucketed, string $group) use ($p, $winStart, $winEnd, $uk) {
  ob_start(); ?>
  <!doctype html><html><head>
  <meta charset="utf-8"><title>Short-term Programme</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#0f172a;color:#e5e7eb}
    .wrap{max-width:1100px;margin:0 auto;padding:20px}
    .board{border:1px solid #1f2937;border-radius:10px;overflow:hidden;background:#0b1220}
    table{width:100%;border-collapse:collapse}
    th,td{font-size:12px;border-bottom:1px solid #1f2937;padding:8px 6px;vertical-align:top}
    th{position:sticky;top:0;background:#111827}
    .day{display:inline-block;width:14px;height:10px;margin:0 1px;background:#111827;border:1px solid #1f2937;border-radius:2px}
    .on{background:#60a5fa;border-color:#2563eb}
    @media print { body{background:#fff;color:#000} .board{border-color:#ccc} th{background:#eee} .day{border-color:#ccc} .on{background:#000} }
  </style></head><body>
  <div class="wrap">
    <h1><?=htmlspecialchars($title)?></h1>
    <p><?=htmlspecialchars($p['name'])?> • Window: <?=htmlspecialchars($uk($winStart))?> → <?=htmlspecialchars($uk($winEnd))?></p>
    <div class="board"><table><thead>
      <tr>
        <th style="width:260px"><?= $group==='contractor' ? 'Contractor' : 'Apartment' ?></th>
        <th>Task</th><th style="width:80px">Ops</th>
        <th style="width:110px">Start</th><th style="width:110px">Finish</th>
        <th style="width:<?=count($dates)*16?>px"></th>
      </tr>
    </thead><tbody>
    <?php foreach ($bucketed as $g => $tasks): ?>
      <tr><td colspan="6" style="background:#0f172a;color:#93c5fd;font-weight:600"><?=htmlspecialchars($g)?></td></tr>
      <?php foreach ($tasks as $t): ?>
        <tr>
          <td><?= $group==='contractor' ? htmlspecialchars($t['contractor']?:'Unassigned') : htmlspecialchars(trim(($t['block']?:'')." ".($t['floor']?:'')." ".($t['unit']?:'')." ".($t['apt_type']?:''))) ?></td>
          <td><?=htmlspecialchars($t['name'])?></td>
          <td><?= (int)$t['operatives'] ?></td>
          <td><?= htmlspecialchars($uk($t['start_date'])) ?></td>
          <td><?= htmlspecialchars($uk($t['finish_date'])) ?></td>
          <td><?php foreach ($dates as $d): $on = ($t['start_date'] <= $d && $t['finish_date'] >= $d); ?>
            <span class="day <?=$on?'on':''?>" title="<?=$d?>"></span>
          <?php endforeach; ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody></table></div>
  </div></body></html>
  <?php return ob_get_clean();
};

if ($format === 'html') {
  header('Content-Type: text/html; charset=utf-8');
  echo $render_html('Short-term Programme ('.$group.')', $dates, $bucketed, $group);
  exit;
}

$fpdfPath = null;
if (!$nofpdf) {
  $root = dirname(__DIR__, 2);
  foreach ([
    $root.'/app/Lib/fpdf/fpdf.php',
    $root.'/app/Lib/FPDF.php',
    $root.'/app/lib/fpdf/fpdf.php',
    $root.'/app/lib/FPDF.php',
  ] as $cand) if (is_file($cand)) { $fpdfPath = $cand; break; }
}

if ($fpdfPath && !$nofpdf) {
  require_once $fpdfPath;

  class ShortTermPDF extends FPDF {
    function headerRow(array $labels, array $widths) {
      $this->SetFont('Arial','B',10);
      foreach ($labels as $i=>$t) { $this->Cell($widths[$i], 8, $t, 1, 0, 'L', true); }
      $this->Ln();
    }
  }

  $palette = [
    'header_fill'  => [17,24,39],
    'header_text'  => [255,255,255],
    'header_line'  => [31,41,55],
    'group_fill'   => [15,23,42],
    'group_text'   => [147,197,253],
    'group_line'   => [31,41,55],
    'timeline_bg'  => [11,18,32],
    'timeline_grid'=> [45,55,72],
    'cell_line'    => [210,215,220],
    'text'         => [0,0,0],
  ];

  $pdf = new ShortTermPDF('L','mm','A4');
  $pdf->SetMargins(12,12,12);
  $pdf->SetAutoPageBreak(true, 12);
  $pdf->AddPage();

  $txt = function(string $s): string {
    $s = str_replace(["\u{2013}", "\u{2022}", "\u{2192}"], ['-', '•', '->'], $s);
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $out !== false ? $out : $s;
  };

  $uk = function(?string $ymd): string {
    if (!$ymd) return '';
    try { return (new DateTimeImmutable($ymd))->format('d/m/Y'); }
    catch (Throwable) { return $ymd; }
  };

  $pdf->SetFont('Arial','B',14);
  $pdf->SetTextColor(...$palette['text']);
  $pdf->Cell(0,8,$txt('Short-term Programme ('.ucfirst($group).')'),0,1,'L');
  $pdf->SetFont('Arial','',10);
  $pdf->Cell(0,6,$txt($p['name'].'  –  Window: '.$uk($winStart).' → '.$uk($winEnd)),0,1,'L');
  $pdf->Ln(2);

  $colGroup=54; $colTask=80; $colOps=12; $colStart=22; $colFinish=22;
  $timelineW = 273 - ($colGroup+$colTask+$colOps+$colStart+$colFinish);
  $dayCellW  = max(2.5, min(6.0, $timelineW / max(1,count($dates))));
  $rowH=6.5;

  $pdf->SetFillColor(...$palette['header_fill']);
  $pdf->SetTextColor(...$palette['header_text']);
  $pdf->SetDrawColor(...$palette['header_line']);
  $pdf->headerRow(
    [$txt($group==='contractor'?'Contractor':'Apartment'),$txt('Task'),$txt('Ops'),$txt('Start'),$txt('Finish'),$txt('Timeline')],
    [$colGroup,$colTask,$colOps,$colStart,$colFinish,$timelineW]
  );

  $dateIndex = array_flip($dates);
  $hex2rgb = function(?string $hex): array {
    $hex = $hex ?: '#60a5fa'; $hex = ltrim($hex,'#');
    if (strlen($hex)===3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
  };

  foreach ($bucketed as $gkey=>$tasks) {
    $pdf->SetFillColor(...$palette['group_fill']);
    $pdf->SetTextColor(...$palette['group_text']);
    $pdf->SetDrawColor(...$palette['group_line']);
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell($colGroup+$colTask+$colOps+$colStart+$colFinish+$timelineW,7,$txt(' '.$gkey),1,1,'L',true);

    $pdf->SetFont('Arial','',9);
    $pdf->SetTextColor(...$palette['text']);
    $pdf->SetDrawColor(...$palette['cell_line']);

    foreach ($tasks as $t) {
      if ($pdf->GetY() > 190) {
        $pdf->AddPage();
        $pdf->SetFillColor(...$palette['header_fill']);
        $pdf->SetTextColor(...$palette['header_text']);
        $pdf->SetDrawColor(...$palette['header_line']);
        $pdf->headerRow(
          [$txt($group==='contractor'?'Contractor':'Apartment'),$txt('Task'),$txt('Ops'),$txt('Start'),$txt('Finish'),$txt('Timeline')],
          [$colGroup,$colTask,$colOps,$colStart,$colFinish,$timelineW]
        );
        $pdf->SetFont('Arial','',9);
        $pdf->SetTextColor(...$palette['text']);
        $pdf->SetDrawColor(...$palette['cell_line']);
      }

      $apt = trim(($t['block']?:'').' '.($t['floor']?:'').' '.($t['unit']?:'').' '.($t['apt_type']?:''));
      $who = ($group==='contractor') ? ($t['contractor'] ?: 'Unassigned') : ($apt ?: 'Apartment');

      $pdf->Cell($colGroup,$rowH,$txt($who),1,0,'L');
      $pdf->Cell($colTask,$rowH,$txt($t['name']),1,0,'L');
      $pdf->Cell($colOps,$rowH,(string)(int)$t['operatives'],1,0,'C');
      $pdf->Cell($colStart,$rowH,$uk($t['start_date']),1,0,'C');
      $pdf->Cell($colFinish,$rowH,$uk($t['finish_date']),1,0,'C');

      $x=$pdf->GetX(); $y=$pdf->GetY();
      $pdf->SetFillColor(...$palette['timeline_bg']);
      $pdf->SetDrawColor(...$palette['cell_line']);
      $pdf->Cell($timelineW,$rowH,'',1,0,'L',true);

      $pdf->SetDrawColor(...$palette['timeline_grid']);
      for ($i=0;$i<count($dates);$i++) $pdf->Line($x+$i*$dayCellW,$y,$x+$i*$dayCellW,$y+$rowH);

      $s = max($t['start_date'],$winStart); $f = min($t['finish_date'],$winEnd);
      if (isset($dateIndex[$s]) && isset($dateIndex[$f])) {
        $i0=$dateIndex[$s]; $i1=$dateIndex[$f];
        $barX=$x+$i0*$dayCellW+0.3; $barW=max(1.8,($i1-$i0+1)*$dayCellW-0.6);
        [$r,$g,$b]=$hex2rgb($t['colour']??'#60a5fa');
        $pdf->SetFillColor($r,$g,$b);
        $pdf->SetDrawColor(0,0,0);
        $pdf->Rect($barX,$y+1.2,$barW,$rowH-2.4,'F');
      }

      $pdf->Ln();
    }
  }

  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="shortterm.pdf"');
  $pdf->Output('I'); exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $render_html('Short-term Programme ('.$group.')', $dates, $bucketed, $group);
PHP;

/* ---------- admin/users.php ---------- */
$files['admin/users.php'] = <<<'PHP'
<?php declare(strict_types=1); ?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Users</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
  header{padding:14px 16px;background:#111827;border-bottom:1px solid #1f2937;display:flex;gap:10px;align-items:center}
  main{max-width:900px;margin:0 auto;padding:16px}
  input,select,button{padding:8px;border-radius:8px;border:1px solid #374151;background:#111827;color:#e5e7eb}
  button{background:#2563eb;border-color:#1d4ed8}
  table{width:100%;border-collapse:collapse;margin-top:14px}
  th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
  a{color:#93c5fd;text-decoration:none}
</style></head><body>
<header>
  <a href="/admin/">← Admin</a>
  <strong>Users</strong>
</header>
<main>
  <div id="me" style="margin-bottom:10px;opacity:.85"></div>

  <form id="add" onsubmit="return false" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-width:640px">
    <input id="name" placeholder="Name" required>
    <input id="email" placeholder="Email" required type="email">
    <input id="password" placeholder="Password" required type="password">
    <select id="role">
      <option value="admin">admin</option>
      <option value="planner">planner</option>
      <option value="commenter">commenter</</option>
      <option value="viewer">viewer</option>
    </select>
    <button id="create" style="grid-column:1/-1">Create user</button>
  </form>

  <table id="tbl"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th></tr></thead><tbody></tbody></table>
</main>
<script>
let CSRF=null, ME=null;
async function who() {
  const j=await (await fetch('/api/auth.php?action=whoami')).json();
  ME=j.user; CSRF=j.csrf; document.getElementById('me').textContent = ME ? `Logged in as ${ME.name} (${ME.role})` : 'Not logged in';
}
async function listUsers() {
  const res = await fetch('/api/raw.php?sql='+encodeURIComponent('SELECT id,name,email,role FROM users ORDER BY id DESC'));
  const j = await res.json();
  const tb=document.querySelector('#tbl tbody'); tb.innerHTML='';
  (j.rows||[]).forEach(r=>{
    const tr=document.createElement('tr');
    tr.innerHTML=`<td>${r.id}</td><td>${r.name}</td><td>${r.email}</td><td>${r.role}</td>`;
    tb.appendChild(tr);
  });
}
document.getElementById('create').onclick = async ()=>{
  const body = {
    name: document.getElementById('name').value.trim(),
    email: document.getElementById('email').value.trim(),
    password: document.getElementById('password').value,
    role: document.getElementById('role').value
  };
  const url = '/api/auth.php?action=create_user';
  const opts = {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)};
  if (ME) { opts.headers['X-CSRF']=CSRF; }
  const res = await fetch(url, opts);
  if (!res.ok) return alert('Create failed');
  await listUsers();
  alert('User created');
};
(async()=>{ await who(); await listUsers(); })();
</script>
</body></html>
PHP;

/* ---------- admin/templates.php ---------- */
$files['admin/templates.php'] = <<<'PHP'
<?php declare(strict_types=1); ?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Templates</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
  header{padding:14px 16px;background:#111827;border-bottom:1px solid #1f2937;display:flex;gap:10px;align-items:center}
  main{max-width:960px;margin:0 auto;padding:16px}
  input,button{padding:8px;border-radius:8px;border:1px solid #374151;background:#111827;color:#e5e7eb}
  button{background:#2563eb;border-color:#1d4ed8}
  table{width:100%;border-collapse:collapse;margin-top:12px} th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
  .row{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
  a{color:#93c5fd;text-decoration:none}
</style></head><body>
<header>
  <a href="/admin/">← Admin</a>
  <strong>Templates</strong>
</header>
<main>
  <div id="me" style="opacity:.85"></div>

  <div class="row">
    <input id="tname" placeholder="New template name">
    <button onclick="create()">Create</button>
    <input id="aptid" placeholder="Apartment ID to snapshot">
    <button onclick="fromApt()">Create from apartment</button>
  </div>

  <div class="row">
    <select id="tpl"></select>
    <input id="aptlist" placeholder="Apartment IDs (comma separated)">
    <button onclick="cloneTo()">Clone to apartments</button>
    <button onclick="preview()">Preview</button>
  </div>

  <table id="tbl"><thead><tr><th>Code</th><th>Task</th><th>Ops</th><th>Dur</th><th>Contractor ID</th></tr></thead><tbody></tbody></table>
</main>
<script>
let CSRF=null, ME=null;
async function who(){ const j=await (await fetch('/api/auth.php?action=whoami')).json(); ME=j.user; CSRF=j.csrf; document.getElementById('me').textContent = ME? `Signed in as ${ME.name} (${ME.role})`:'Not signed in'; }
async function loadList(){ const j=await (await fetch('/api/templates.php?action=list')).json(); const s=tpl; s.innerHTML=''; (j.data||[]).forEach(t=>{ const o=document.createElement('option'); o.value=t.id; o.textContent=`#${t.id} ${t.name}`; s.appendChild(o); }); }
async function create(){ const name=tname.value.trim(); if(!name) return alert('Name'); const r=await fetch('/api/templates.php?action=create',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF':CSRF},body:JSON.stringify({name})}); if(!r.ok) return alert('Failed'); await loadList(); }
async function fromApt(){ const name=tname.value.trim()||undefined; const apartment_id=+aptid.value; if(!apartment_id) return alert('Apartment ID'); const r=await fetch('/api/templates.php?action=from_apartment',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF':CSRF},body:JSON.stringify({name,apartment_id})}); if(!r.ok) return alert('Failed'); await loadList(); }
async function cloneTo(){ const template_id=+tpl.value; const ids=aptlist.value.split(',').map(s=>+s.trim()).filter(Boolean); if(!template_id||ids.length===0) return alert('Pick template and apartments'); const r=await fetch('/api/templates.php?action=clone',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF':CSRF},body:JSON.stringify({template_id,project_id:1,apartment_ids:ids})}); if(!r.ok) return alert('Failed'); alert('Cloned'); }
async function preview(){ const id=+tpl.value; const j=await (await fetch('/api/templates.php?action=detail&id='+id)).json(); const tb=document.querySelector('#tbl tbody'); tb.innerHTML=''; (j.data.tasks||[]).forEach(t=>{ const tr=document.createElement('tr'); tr.innerHTML=`<td>${t.code}</td><td>${t.name}</td><td>${t.operatives}</td><td>${t.duration_days}</td><td>${t.contractor_id||''}</td>`; tb.appendChild(tr); }); }
(async()=>{ await who(); await loadList(); })();
</script>
</body></html>
PHP;

/* ---------- admin/baselines.php ---------- */
$files['admin/baselines.php'] = <<<'PHP'
<?php declare(strict_types=1); ?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Baselines</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
  header{padding:14px 16px;background:#111827;border-bottom:1px solid #1f2937;display:flex;gap:10px;align-items:center}
  main{max-width:1100px;margin:0 auto;padding:16px}
  input,button{padding:8px;border-radius:8px;border:1px solid #374151;background:#111827;color:#e5e7eb}
  button{background:#2563eb;border-color:#1d4ed8}
  table{width:100%;border-collapse:collapse;margin-top:14px} th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
  .slipneg{color:#16a34a}.slippos{color:#f87171}
  a{color:#93c5fd;text-decoration:none}
</style></head><body>
<header>
  <a href="/admin/">← Admin</a>
  <strong>Baselines & Variance</strong>
</header>
<main>
  <div id="me" style="opacity:.85;margin-bottom:8px"></div>
  <div>
    <input id="label" placeholder="Baseline label (optional)">
    <button onclick="setBaseline()">Set Baseline Now</button>
  </div>

  <h3>Existing baselines</h3>
  <ul id="list"></ul>

  <h3>Variance (working days)</h3>
  <table id="tbl"><thead><tr>
    <th>Task</th><th>Contractor</th>
    <th>Base Start</th><th>Start</th><th>Δ Start</th>
    <th>Base Finish</th><th>Finish</th><th>Δ Finish</th>
  </tr></thead><tbody></tbody></table>
</main>
<script>
let CSRF=null, ME=null;
async function who(){ const j=await (await fetch('/api/auth.php?action=whoami')).json(); ME=j.user; CSRF=j.csrf; document.getElementById('me').textContent = ME? `Signed in as ${ME.name} (${ME.role})`:'Not signed in'; }
async function list(){ const j=await (await fetch('/api/baselines.php?project=1')).json(); const ul=listEl; ul.innerHTML=''; (j.data||[]).forEach(b=>{ const li=document.createElement('li'); li.textContent=`#${b.id} — ${b.label} (${b.created_at})`; ul.appendChild(li); }); }
async function variance(){ const j=await (await fetch('/api/analytics.php?project=1&type=variance')).json(); const tb=document.querySelector('#tbl tbody'); tb.innerHTML=''; (j.rows||j.data?.rows||[]).forEach(r=>{ const tr=document.createElement('tr'); const s=r.start_slip_days, f=r.finish_slip_days; tr.innerHTML = `
  <td>${r.task}</td><td>${r.contractor||''}</td>
  <td>${r.baseline_start||''}</td><td>${r.start||''}</td><td class="${s>0?'slippos': (s<0?'slipneg':'')}">${s??''}</td>
  <td>${r.baseline_finish||''}</td><td>${r.finish||''}</td><td class="${f>0?'slippos': (f<0?'slipneg':'')}">${f??''}</td>
  `; tb.appendChild(tr); }); }
async function setBaseline(){ const label=document.getElementById('label').value.trim()||undefined; const r=await fetch('/api/baselines.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF':CSRF},body:JSON.stringify({project_id:1,label})}); if(!r.ok) return alert('Failed'); await list(); await variance(); alert('Baseline captured'); }
const listEl = document.getElementById('list');
(async()=>{ await who(); await list(); await variance(); })();
</script>
</body></html>
PHP;

/* ---------- migration ---------- */
$files['scripts/migrations/002_auth_templates_baselines.sql'] = <<<'SQL'
-- USERS
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  role ENUM('admin','planner','commenter','viewer') NOT NULL DEFAULT 'viewer',
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- BASELINES
CREATE TABLE IF NOT EXISTS baselines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  label VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ensure baseline fields on tasks
ALTER TABLE tasks
  ADD COLUMN IF NOT EXISTS baseline_start DATE NULL,
  ADD COLUMN IF NOT EXISTS baseline_finish DATE NULL;

-- TEMPLATES
CREATE TABLE IF NOT EXISTS templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS template_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(255) NOT NULL,
  contractor_id INT NULL,
  operatives INT NOT NULL DEFAULT 1,
  duration_days INT NOT NULL DEFAULT 1,
  zone VARCHAR(120) NULL,
  is_milestone TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY (template_id, code),
  INDEX (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS template_dependencies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  task_code VARCHAR(40) NOT NULL,
  predecessor_code VARCHAR(40) NOT NULL,
  type ENUM('FS','SS') NOT NULL DEFAULT 'FS',
  lag_days INT NOT NULL DEFAULT 0,
  INDEX (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

/* ---------- write all files ---------- */
$results = [];
foreach ($files as $rel => $content) {
  $path = $root . '/' . $rel;
  $dir  = dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      $results[] = [$rel, 'FAIL', 'Could not create directory'];
      continue;
    }
  }
  $ok = @file_put_contents($path, $content);
  $results[] = [$rel, $ok!==false?'OK':'FAIL', $ok!==false?('wrote '.strlen($content).' bytes'):'write failed'];
}

/* ---------- output ---------- */
?><!doctype html><html><head>
<meta charset="utf-8"><title>Build Pack 4 Installer</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
 .wrap{max-width:900px;margin:0 auto;padding:20px}
 h1{margin:0 0 12px 0}
 table{width:100%;border-collapse:collapse}
 th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
 .ok{color:#86efac}.fail{color:#fca5a5}
 a{color:#93c5fd}
 .hint{opacity:.85}
</style></head><body><div class="wrap">
<h1>Build Pack 4 • Installer</h1>
<p class="hint">Root: <?=htmlspecialchars($root)?></p>
<table><thead><tr><th>File</th><th>Status</th><th>Details</th></tr></thead><tbody>
<?php foreach ($results as [$rel,$st,$msg]): ?>
  <tr><td><?=htmlspecialchars($rel)?></td>
      <td class="<?=strtolower($st)==='ok'?'ok':'fail'?>"><?=htmlspecialchars($st)?></td>
      <td><?=htmlspecialchars($msg)?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p style="margin-top:12px">Next steps:</p>
<ol>
  <li>Run migration in phpMyAdmin: <code>/scripts/migrations/002_auth_templates_baselines.sql</code></li>
  <li>Create your first admin at <a href="/admin/users.php">/admin/users.php</a></li>
  <li>Then delete this installer file for security.</li>
</ol>
</div></body></html>

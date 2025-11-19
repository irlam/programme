<?php
/* install_frontend_fix.php
 * Writes:
 *  - /assets/js/drawer-contractor-patch.js      (drawer logic + save)
 *  - /assets/js/contractor-datalist.js         (autocomplete via safe API)
 *  - /api/contractors.php?action=list          (returns id,name)
 * Run once, then delete this file.
 */
declare(strict_types=1);
$root = dirname(__DIR__); // /httpdocs

$files = [];

/* -------- /assets/js/drawer-contractor-patch.js -------- */
$files['assets/js/drawer-contractor-patch.js'] = <<<'JS'
/*! Drawer Contractor Patch — allows contractor NAME, fixes name prefill, UK date parsing, CSRF send.
   Requires inputs with ids:
    #edit-name, #edit-contractor-name, #edit-ops, #edit-duration, #edit-zone, #edit-constraint
   And a save button with id:
    #edit-save
*/
(function(){
  const SEL = {
    saveBtn:           '#edit-save',
    name:              '#edit-name',
    contractorName:    '#edit-contractor-name',
    ops:               '#edit-ops',
    duration:          '#edit-duration',
    zone:              '#edit-zone',
    constraint:        '#edit-constraint'
  };

  let CSRF = null;
  async function ensureCsrf(){
    if (CSRF) return CSRF;
    const j = await (await fetch('/api/auth.php?action=whoami')).json();
    CSRF = j.csrf;
    return CSRF;
  }

  function parseUK(s){
    s = (s||'').trim();
    if (!s || /^dd/i.test(s)) return null;
    const m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    return m ? `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}` : s;
  }

  // Public: load a task into the drawer
  async function openTaskIntoDrawer(taskId){
    const r = await fetch(`/api/tasks.php?action=get&id=${encodeURIComponent(taskId)}`);
    const j = await r.json();
    if (!r.ok || !j.ok) { alert('Load failed'); return; }
    const t = j.task || {};
    const $ = s => document.querySelector(s);
    if ($(SEL.name))           $(SEL.name).value           = (t.name ?? t.text ?? '');
    if ($(SEL.contractorName)) $(SEL.contractorName).value = (t.contractor || '');
    if ($(SEL.ops))            $(SEL.ops).value            = (t.operatives ?? 0);
    if ($(SEL.duration))       $(SEL.duration).value       = (t.duration_days ?? 0);
    if ($(SEL.zone))           $(SEL.zone).value           = (t.zone || '');
    if ($(SEL.constraint))     $(SEL.constraint).value     = (t.constraint_start || 'dd / mm / yyyy');
    const save = document.querySelector(SEL.saveBtn);
    if (save){ save.dataset.taskId = String(t.id); }
  }

  // Public: save current drawer values
  async function saveDrawerTask(){
    const $ = s => document.querySelector(s);
    const save = $(SEL.saveBtn);
    const id = save && save.dataset.taskId ? +save.dataset.taskId : null;
    if (!id) { alert('No task id set'); return; }

    await ensureCsrf();
    const body = {
      id,
      name: ($(`${SEL.name}`)?.value || '').trim(),
      contractor_name: ($(`${SEL.contractorName}`)?.value || '').trim(),
      operatives: +($(`${SEL.ops}`)?.value || 0),
      duration_days: +($(`${SEL.duration}`)?.value || 0),
      zone: ($(`${SEL.zone}`)?.value || '').trim() || null,
      constraint_start: parseUK($(`${SEL.constraint}`)?.value)
    };
    if (body.contractor_name === '') delete body.contractor_name;
    if (body.constraint_start === null) delete body.constraint_start;

    const res = await fetch('/api/tasks.php?action=update', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF': CSRF},
      body: JSON.stringify(body)
    });
    const j = await res.json().catch(()=>({}));
    if (!res.ok || !j.ok) { alert('Save failed' + (j.error?`: ${j.error}`:'')); return; }

    // Notify host page to refresh its Gantt/table
    window.dispatchEvent(new CustomEvent('programme:task-saved', {detail:{id}}));
  }

  // Auto-bind save button if present
  function tryAutobind(){
    const save = document.querySelector(SEL.saveBtn);
    if (save && !save.dataset.patched){
      save.addEventListener('click', function(ev){
        ev.preventDefault();
        saveDrawerTask().catch(console.error);
      });
      save.dataset.patched = '1';
    }
  }

  // Expose helpers
  window.openTaskIntoDrawer = openTaskIntoDrawer;
  window.saveTaskPatched = saveDrawerTask;
  window.applyContractorNamePatch = tryAutobind;

  // Bind on load
  (document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', tryAutobind)
    : tryAutobind());
})();
JS;

/* -------- /assets/js/contractor-datalist.js -------- */
$files['assets/js/contractor-datalist.js'] = <<<'JS'
/* Autocomplete list for contractor names (safe API) */
async function setupContractorDatalist(inputId){
  const inp = document.getElementById(inputId);
  if (!inp) return;
  let dl = document.getElementById('contractor-list');
  if (!dl){ dl = document.createElement('datalist'); dl.id='contractor-list'; document.body.appendChild(dl); }
  inp.setAttribute('list','contractor-list');
  try {
    const res = await fetch('/api/contractors.php?action=list');
    const j = await res.json();
    dl.innerHTML = (j.items||[]).map(r=>`<option value="${r.name}"></option>`).join('');
  } catch(e){
    console.warn('contractor list load failed', e);
  }
}
JS;

/* -------- /api/contractors.php -------- */
$files['api/contractors.php'] = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');
$pdo = DB::pdo();

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
  $st = $pdo->query("SELECT id, name FROM contractors ORDER BY name");
  $items = $st->fetchAll();
  echo json_encode(['ok'=>true, 'items'=>$items]);
  exit;
}

http_response_code(404);
echo json_encode(['ok'=>false, 'error'=>'unknown_action']);
PHP;

/* -------- write files -------- */
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

/* -------- output -------- */
?><!doctype html><html><head>
<meta charset="utf-8"><title>Frontend Fix • Installer</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
 .wrap{max-width:920px;margin:0 auto;padding:20px}
 table{width:100%;border-collapse:collapse}
 th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
 .ok{color:#86efac}.fail{color:#fca5a5}
 a{color:#93c5fd} code{background:#0b1220;border:1px solid #1f2937;padding:2px 6px;border-radius:6px}
</style></head><body><div class="wrap">
<h1>Frontend Fix • Installer</h1>
<p>Root: <?=htmlspecialchars($root)?></p>
<table><thead><tr><th>File</th><th>Status</th><th>Details</th></tr></thead><tbody>
<?php foreach ($results as [$rel,$st,$msg]): ?>
  <tr><td><?=htmlspecialchars($rel)?></td>
      <td class="<?=strtolower($st)==='ok'?'ok':'fail'?>"><?=htmlspecialchars($st)?></td>
      <td><?=htmlspecialchars($msg)?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p>Next:</p>
<ol>
  <li>Hard refresh <code>/index.html</code> (Ctrl/Cmd+Shift+R).</li>
  <li>Click a task — drawer opens; contractor autocomplete should populate.</li>
</ol>
<p><strong>Security:</strong> delete this installer file now.</p>
</div></body></html>

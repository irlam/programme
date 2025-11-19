<?php
/* install_drawer_contractor_patch.php — Front-end drawer patch (contractor by NAME + proper name prefill)
 * Run once, then delete this file.
 */
declare(strict_types=1);
$root = dirname(__DIR__); // /httpdocs

$files = [];

/* ---------- /assets/js/contractor-datalist.js (idempotent) ---------- */
$files['assets/js/contractor-datalist.js'] = <<<'JS'
// contractor-datalist helper (optional)
// Usage: call setupContractorDatalist('edit-contractor-name');
async function setupContractorDatalist(inputId){
  const inp = document.getElementById(inputId);
  if (!inp) return;
  let dl = document.getElementById('contractor-list');
  if (!dl){ dl = document.createElement('datalist'); dl.id='contractor-list'; document.body.appendChild(dl); }
  inp.setAttribute('list','contractor-list');
  try {
    const sql='SELECT name FROM contractors ORDER BY name';
    const res = await fetch('/api/raw.php?sql='+encodeURIComponent(sql));
    const j = await res.json();
    dl.innerHTML = (j.rows||[]).map(r=>`<option value="${r.name}"></option>`).join('');
  } catch(e){ console.warn('contractor list load failed', e); }
}
JS;

/* ---------- /assets/js/drawer-contractor-patch.js ---------- */
$files['assets/js/drawer-contractor-patch.js'] = <<<'JS';
/*! Drawer Contractor Patch — allows contractor NAME, fixes name prefill, UK date parsing, CSRF send.
   Drop a single on your Gantt page.
   Optional: keep /assets/js/contractor-datalist.js for autocomplete.
*/
(function(){
  const SEL = {
    drawer:            '#edit-drawer',         // container (optional)
    saveBtn:           '#edit-save',           // save button
    name:              '#edit-name',           // task name input
    contractorName:    '#edit-contractor-name',// contractor name input (text)
    contractorList:    '#contractor-list',     // datalist id (optional)
    ops:               '#edit-ops',
    duration:          '#edit-duration',
    zone:              '#edit-zone',
    constraint:        '#edit-constraint'
  };

  // Utilities
  let CSRF = null;
  async function ensureCsrf(){
    if (CSRF) return CSRF;
    const j = await (await fetch('/api/auth.php?action=whoami')).json();
    CSRF = j.csrf; return CSRF;
  }
  function parseUK(s){
    s = (s||'').trim();
    if (!s || /^dd/i.test(s)) return null;
    const m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    return m ? `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}` : s;
  }
  async function loadContractorsDatalist(){
    if (typeof setupContractorDatalist === 'function'){
      setupContractorDatalist(SEL.contractorName.replace(/^#/, ''));
      return;
    }
    // Lightweight inline loader if helper isn't present
    const inp = document.querySelector(SEL.contractorName);
    if (!inp) return;
    let dl = document.getElementById('contractor-list');
    if (!dl){ dl = document.createElement('datalist'); dl.id='contractor-list'; document.body.appendChild(dl); }
    inp.setAttribute('list','contractor-list');
    try{
      const sql='SELECT name FROM contractors ORDER BY name';
      const j = await (await fetch('/api/raw.php?sql='+encodeURIComponent(sql))).json();
      dl.innerHTML = (j.rows||[]).map(r=>`<option value="${r.name}"></option>`).join('');
    }catch(e){ console.warn('inline contractor list load failed',e); }
  }

  // Public: open task into drawer
  async function openTaskIntoDrawer(taskId){
    const r = await fetch(`/api/tasks.php?action=get&id=${encodeURIComponent(taskId)}`);
    const j = await r.json();
    if (!r.ok || !j.ok) { alert('Load failed'); return; }
    const t = j.task || {};
    // Prefill fields
    const $ = s => document.querySelector(s);
    const nameInput = $(SEL.name);
    const contrInput= $(SEL.contractorName);
    const opsInput  = $(SEL.ops);
    const durInput  = $(SEL.duration);
    const zoneInput = $(SEL.zone);
    const consInput = $(SEL.constraint);

    if (nameInput)  nameInput.value  = (t.name ?? t.text ?? '');
    if (contrInput) contrInput.value = (t.contractor || '');
    if (opsInput)   opsInput.value   = (t.operatives ?? 0);
    if (durInput)   durInput.value   = (t.duration_days ?? 0);
    if (zoneInput)  zoneInput.value  = (t.zone || '');
    if (consInput)  consInput.value  = (t.constraint_start || 'dd / mm / yyyy');

    const save = document.querySelector(SEL.saveBtn);
    if (save){ save.dataset.taskId = String(t.id); }
  }

  // Public: save
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
    // Event hook: allow host page to refresh its Gantt/table
    window.dispatchEvent(new CustomEvent('programme:task-saved', {detail:{id}}));
  }

  // Auto-wire save button if present
  function tryAutobind(){
    const save = document.querySelector(SEL.saveBtn);
    if (save && !save.dataset.patched){
      save.addEventListener('click', function(ev){
        ev.preventDefault();
        saveDrawerTask().catch(console.error);
      });
      save.dataset.patched = '1';
    }
    // Ensure contractor datalist is available
    loadContractorsDatalist();
  }

  // Expose small API
  window.applyContractorNamePatch = tryAutobind;
  window.openTaskIntoDrawer = openTaskIntoDrawer;
  window.saveTaskPatched = saveDrawerTask;

  // Run once when script loads
  document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', tryAutobind)
    : tryAutobind();
})();
JS;

/* ---------- /admin/drawer_patch_instructions.html ---------- */
$files['admin/drawer_patch_instructions.html'] = <<<'HTML'
<!doctype html><html><head>
<meta charset="utf-8"><title>Drawer Patch • Instructions</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:20px}
 code{background:#0b1220;border:1px solid #1f2937;padding:2px 6px;border-radius:6px}
 pre{background:#0b1220;border:1px solid #1f2937;border-radius:8px;padding:10px;white-space:pre-wrap}
 a{color:#93c5fd}
</style></head><body>
<h1>Drawer Contractor Patch — How to wire</h1>
<ol>
  <li>Add the name + contractor inputs inside your edit drawer:
<pre>&lt;label&gt;Task name&lt;/label&gt;
&lt;input id="edit-name"&gt;

&lt;label&gt;Contractor&lt;/label&gt;
&lt;input id="edit-contractor-name" list="contractor-list" placeholder="Start typing…"&gt;
&lt;datalist id="contractor-list"&gt;&lt;/datalist&gt;

&lt;button id="edit-save"&gt;Save&lt;/button&gt;</pre></li>
  <li>Include the scripts near the bottom of your page:
<pre>&lt;script src="/assets/js/contractor-datalist.js"&gt;&lt;/script&gt;
&lt;script src="/assets/js/drawer-contractor-patch.js"&gt;&lt;/script&gt;</pre></li>
  <li>When a user clicks a task, load its data into the drawer:
<pre>openTaskIntoDrawer(taskId); // sets #edit-name, #edit-contractor-name, etc.
applyContractorNamePatch();  // binds the Save button if not already bound</pre></li>
  <li>Listen for save completion to refresh your Gantt/table:
<pre>window.addEventListener('programme:task-saved', (e) =&gt; { reloadGantt(); /* your refresh */ });</pre></li>
</ol>
<p>The Save button sends <code>contractor_name</code> to <code>/api/tasks.php?action=update</code>. If the name is new, the contractor is created automatically. Name field is prefixed from either <code>task.name</code> or <code>task.text</code>.</p>
</body></html>
HTML;

/* ---------- write files ---------- */
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

/* ---------- output result ---------- */
?><!doctype html><html><head>
<meta charset="utf-8"><title>Drawer Contractor Patch • Installer</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
 .wrap{max-width:920px;margin:0 auto;padding:20px}
 table{width:100%;border-collapse:collapse}
 th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
 .ok{color:#86efac}.fail{color:#fca5a5} a{color:#93c5fd}
 code{background:#0b1220;border:1px solid #1f2937;padding:2px 6px;border-radius:6px}
</style></head><body><div class="wrap">
<h1>Drawer Contractor Patch • Installer</h1>
<p>Root: <?=htmlspecialchars($root)?></p>
<table><thead><tr><th>File</th><th>Status</th><th>Details</th></tr></thead><tbody>
<?php foreach ($results as [$rel,$st,$msg]): ?>
  <tr><td><?=htmlspecialchars($rel)?></td>
      <td class="<?=strtolower($st)==='ok'?'ok':'fail'?>"><?=htmlspecialchars($st)?></td>
      <td><?=htmlspecialchars($msg)?></td></tr>
<?php endforeach; ?>
</tbody></table>

<h3>One-time wiring</h3>
<ol>
  <li>Add the inputs in your drawer:
    <pre>&lt;input id="edit-name"&gt;
&lt;input id="edit-contractor-name" list="contractor-list"&gt;
&lt;datalist id="contractor-list"&gt;&lt;/datalist&gt;
&lt;button id="edit-save"&gt;Save&lt;/button&gt;</pre>
  </li>
  <li>Load the scripts on your Gantt page:
    <pre>&lt;script src="/assets/js/contractor-datalist.js"&gt;&lt;/script&gt;
&lt;script src="/assets/js/drawer-contractor-patch.js"&gt;&lt;/script&gt;</pre>
  </li>
  <li>When opening a task, call:
    <pre>openTaskIntoDrawer(taskId); applyContractorNamePatch();</pre>
  </li>
  <li>After save, listen for:
    <pre>window.addEventListener('programme:task-saved', () => reloadGantt());</pre>
  </li>
</ol>

<p>Full instructions: <a href="/admin/drawer_patch_instructions.html">/admin/drawer_patch_instructions.html</a></p>
<p><strong>Security:</strong> Delete this installer file now.</p>
</div></body></html>

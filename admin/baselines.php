<?php declare(strict_types=1); ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Baselines</title>
<style>
  /* Page chrome (yours, unchanged) */
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
  header{padding:14px 16px;background:#111827;border-bottom:1px solid #1f2937;display:flex;gap:10px;align-items:center}
  main{max-width:1100px;margin:0 auto;padding:16px}
  input,button{padding:8px;border-radius:8px;border:1px solid #374151;background:#111827;color:#e5e7eb}
  button{background:#2563eb;border-color:#1d4ed8}
  table{width:100%;border-collapse:collapse;margin-top:14px}
  th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
  .slipneg{color:#16a34a}.slippos{color:#f87171}
  a{color:#93c5fd;text-decoration:none}

  /* Help panel styling (new) */
  :root { --bg:#0f172a; --panel:#0b1220; --line:#1f2937; --muted:#9ca3af; --accent:#93c5fd; --good:#86efac; --warn:#fbbf24; --bad:#f87171; }
  .bl-card {background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:16px; color:#e5e7eb;}
  .bl-card h2 {margin:0 0 8px 0; font-size:18px}
  .bl-lead {color:var(--muted); margin:0 0 12px 0; font-size:14px}
  .bl-grid {display:grid; grid-template-columns:repeat(auto-fit, minmax(260px,1fr)); gap:12px; margin-top:10px}
  .bl-sub {background:rgba(255,255,255,.02); border:1px dashed var(--line); border-radius:10px; padding:12px}
  .bl-sub h3 {margin:0 0 6px 0; font-size:14px}
  .bl-sub p, .bl-list {margin:0; font-size:13px; color:#cbd5e1}
  .bl-list li {margin:6px 0}
  .bl-badges {display:flex; gap:8px; flex-wrap:wrap; margin-top:8px}
  .bl-badge {font-size:11px; border:1px solid var(--line); padding:3px 8px; border-radius:999px; color:#cbd5e1}
  .bl-row {border-top:1px dashed var(--line); margin:14px 0 0 0; padding-top:12px}
  .bl-buttons {display:flex; gap:8px; flex-wrap:wrap; margin-top:10px}
  .bl-pill {background:#1f2937; padding:6px 10px; border-radius:999px; font-size:12px; color:#e5e7eb; text-decoration:none; border:1px solid var(--line)}
  .bl-right {margin-left:auto}
  .bl-kv {display:grid; grid-template-columns:160px 1fr; gap:6px; margin-top:8px; font-size:13px; color:#cbd5e1}
  .bl-kv div:first-child {color:#9ca3af}
  .bl-hint {font-size:12px; color:#9ca3af; margin-top:8px}
  .bl-hidden {display:none}
</style>
</head>
<body>
<header>
  <a href="/admin/">← Admin</a>
  <strong>Baselines & Variance</strong>
  <span style="margin-left:auto"></span>
  <a href="/links.html" class="bl-pill" style="margin-left:auto">Links</a>
  <a href="/about.html" class="bl-pill">About</a>
</header>

<main>
  <!-- NEW: End-user explainer panel -->
  <div id="bl-help" class="bl-card" style="margin-bottom:14px;">
    <div style="display:flex; align-items:center; gap:12px;">
      <h2>Baselines &amp; Variance</h2>
      <span class="bl-badge">Planner</span><span class="bl-badge">Admin</span>
      <a class="bl-pill bl-right" href="#" id="bl-hide">Hide help</a>
    </div>
    <p class="bl-lead">
      A <strong>baseline</strong> is a snapshot of the programme (start/finish/duration) at a moment in time.
      The <strong>variance</strong> compares today’s plan to the selected baseline so you can see slippage, gains,
      and handover pressure at a glance (in <em>working days</em>).
    </p>

    <div class="bl-grid">
      <div class="bl-sub">
        <h3>Typical workflow</h3>
        <ol class="bl-list">
          <li><strong>Create a baseline</strong> (e.g., “V1 – Contract Award” or “V2 – Re-sequence 2025-08-01”).</li>
          <li><strong>Set Active</strong> baseline (the one used for variance across the site).</li>
          <li><strong>Review variance</strong> by block/floor/contractor; focus on late tasks and tight handovers.</li>
          <li><strong>Export</strong> tables to CSV/PDF for weekly reviews and board packs.</li>
        </ol>
        <p class="bl-hint">Baselines are read-only snapshots; changing today’s plan won’t alter a past baseline.</p>
      </div>

      <div class="bl-sub">
        <h3>What the variance shows</h3>
        <ul class="bl-list">
          <li><strong>Δ Start</strong> (days): + = starting later than baseline; − = earlier.</li>
          <li><strong>Δ Finish</strong> (days): + = finishing later; − = earlier.</li>
          <li><strong>Δ Duration</strong> (days): change in planned working-day duration (if exposed).</li>
          <li><strong>Critical path</strong>: tasks with ≤ 0 slack are highlighted elsewhere in the app.</li>
          <li><strong>Tight handovers</strong>: successor starts &lt; 1 working day after predecessor finish.</li>
        </ul>
        <div class="bl-kv">
          <div>Calendar</div><div>Mon–Fri working days, UK bank holidays</div>
          <div>Sign convention</div><div><span style="color:var(--bad)">+ late</span>, <span style="color:var(--good)">– early</span></div>
          <div>Scope</div><div>Project-wide (filter by block/floor/unit/contractor where available)</div>
        </div>
      </div>

      <div class="bl-sub">
        <h3>Who can do what?</h3>
        <ul class="bl-list">
          <li><strong>Viewer</strong>: view variance and export.</li>
          <li><strong>Planner/Admin</strong>: create/delete baselines, set Active, export, and edit the live plan.</li>
        </ul>
        <p class="bl-hint">If you don’t see the Create button, sign in with a Planner or Admin account.</p>
      </div>

      <div class="bl-sub">
        <h3>Tips &amp; gotchas</h3>
        <ul class="bl-list">
          <li>Create a baseline at major milestones (award, re-sequence, start of fit-out).</li>
          <li>Variance uses <strong>working days</strong>; a “+2” can span a long weekend.</li>
          <li>To revert, adjust today’s tasks or import a prior snapshot—baselines themselves don’t change.</li>
          <li>Use “tight handovers” to spot risk hotspots quickly.</li>
        </ul>
      </div>
    </div>

    <div class="bl-row bl-buttons">
      <a class="bl-pill" href="/">← Back to Gantt</a>
      <a class="bl-pill" href="/links.html">Links</a>
      <a class="bl-pill" href="/about.html">About / Help</a>
      <a class="bl-pill" href="/api/export/csv.php?project=1">Export CSV</a>
    </div>
  </div>

  <!-- Your existing UI -->
  <div id="me" style="opacity:.85;margin-bottom:8px"></div>
  <div>
    <input id="label" placeholder="Baseline label (optional)">
    <button onclick="setBaseline()">Set Baseline Now</button>
  </div>

  <h3>Existing baselines</h3>
  <ul id="list"></ul>

  <h3>Variance (working days)</h3>
  <table id="tbl">
    <thead>
      <tr>
        <th>Task</th><th>Contractor</th>
        <th>Base Start</th><th>Start</th><th>Δ Start</th>
        <th>Base Finish</th><th>Finish</th><th>Δ Finish</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
</main>

<script>
/* Help panel hide/show (remembered in this browser) */
(function(){
  const box = document.getElementById('bl-help');
  const btn = document.getElementById('bl-hide');
  if (!box || !btn) return;
  const KEY = 'programme.baselines.help.hidden';
  function apply(){
    const hidden = localStorage.getItem(KEY) === '1';
    box.classList.toggle('bl-hidden', hidden);
    btn.textContent = hidden ? 'Show help' : 'Hide help';
  }
  btn.addEventListener('click', function(e){
    e.preventDefault();
    const hidden = localStorage.getItem(KEY) === '1';
    localStorage.setItem(KEY, hidden ? '0' : '1');
    apply();
  });
  apply();
})();

/* Your existing JS (unchanged) */
let CSRF=null, ME=null;
async function who(){
  const j=await (await fetch('/api/auth.php?action=whoami')).json();
  ME=j.user; CSRF=j.csrf;
  document.getElementById('me').textContent = ME? `Signed in as ${ME.name} (${ME.role})`:'Not signed in';
}
async function list(){
  const j=await (await fetch('/api/baselines.php?project=1')).json();
  const ul=listEl; ul.innerHTML='';
  (j.data||[]).forEach(b=>{
    const li=document.createElement('li');
    li.textContent=`#${b.id} — ${b.label} (${b.created_at})${b.is_active?' • Active':''}`;
    ul.appendChild(li);
  });
}
async function variance(){
  const j=await (await fetch('/api/analytics.php?project=1&type=variance')).json();
  const tb=document.querySelector('#tbl tbody'); tb.innerHTML='';
  (j.rows||j.data?.rows||[]).forEach(r=>{
    const tr=document.createElement('tr');
    const s=r.start_slip_days, f=r.finish_slip_days;
    tr.innerHTML = `
      <td>${r.task}</td><td>${r.contractor||''}</td>
      <td>${r.baseline_start||''}</td><td>${r.start||''}</td><td class="${s>0?'slippos': (s<0?'slipneg':'')}">${s??''}</td>
      <td>${r.baseline_finish||''}</td><td>${r.finish||''}</td><td class="${f>0?'slippos': (f<0?'slipneg':'')}">${f??''}</td>
    `;
    tb.appendChild(tr);
  });
}
async function setBaseline(){
  const label=document.getElementById('label').value.trim()||undefined;
  const r=await fetch('/api/baselines.php',{
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF':CSRF},
    body:JSON.stringify({project_id:1,label})
  });
  if(!r.ok) return alert('Failed');
  await list(); await variance(); alert('Baseline captured');
}
const listEl = document.getElementById('list');
(async()=>{ await who(); await list(); await variance(); })();
</script>
</body>
</html>

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
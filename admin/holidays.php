<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Admin • Holidays</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;}
  header{padding:14px 16px;background:#111827;border-bottom:1px solid #1f2937;display:flex;gap:10px;align-items:center}
  .wrap{max-width:900px;margin:0 auto;padding:20px;}
  table{width:100%;border-collapse:collapse} th,td{padding:8px 10px;border-bottom:1px solid #1f2937;font-size:14px}
  input,button{padding:8px;border-radius:6px;border:1px solid #374151;background:#111827;color:#e5e7eb}
  button{background:#2563eb;border-color:#1d4ed8}
</style>
</head>
<body>
<header>
  <a href="/admin/" style="color:#93c5fd;text-decoration:none">← Health</a>
  <strong>Holiday / Exception Editor</strong>
</header>
<div class="wrap">
  <form id="f" onsubmit="return false;">
    <label>Date: <input type="date" id="date" required></label>
    <label style="margin-left:10px;">Working? <input type="checkbox" id="is_working"></label>
    <label style="margin-left:10px;">Name: <input type="text" id="name" placeholder="Bank Holiday"></label>
    <button onclick="save()">Save</button>
  </form>
  <p style="opacity:.8;">Tick “Working?” to force a normally non-working day into a working day; untick to mark a holiday.</p>
  <table id="tbl"><thead><tr><th>Date</th><th>Working</th><th>Name</th><th>Source</th><th></th></tr></thead><tbody></tbody></table>
</div>
<script>
async function load() {
  const res = await fetch('/api/calendar.php?project=1');
  const json = await res.json();
  const tb = document.querySelector('#tbl tbody'); tb.innerHTML='';
  json.data.items.forEach(r=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${r.date}</td>
                    <td>${r.is_working? 'Yes':'No'}</td>
                    <td>${r.name||''}</td>
                    <td>${r.source}</td>
                    <td><button onclick="del(${r.id})">Delete</button></td>`;
    tb.appendChild(tr);
  });
}
async function save(){
  const body = {
    date: document.getElementById('date').value,
    is_working: document.getElementById('is_working').checked ? 1 : 0,
    name: document.getElementById('name').value
  };
  const res = await fetch('/api/calendar.php?project=1',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  if (res.ok) { await load(); alert('Saved'); } else { alert('Save failed'); }
}
async function del(id){
  if (!confirm('Delete this exception?')) return;
  const res = await fetch('/api/calendar.php?project=1&id='+id,{method:'DELETE'});
  if (res.ok) { await load(); }
}
load();
</script>
</body>
</html>

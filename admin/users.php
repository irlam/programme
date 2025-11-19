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
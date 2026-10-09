/* Shared navigation for the existing import and project control screens. */
(() => {
  const path=location.pathname;
  const links=[['/index.html','▤','Programme'],['/lookahead.html','▦','Lookahead'],['/analytics.html','▥','Analytics'],['/admin/import.html','↥','Import programme'],['/admin/baselines.php','◎','Baselines & variance'],['/admin/holidays.php','□','Working calendar'],['/admin/templates.php','▧','Templates'],['/admin/users.php','♧','Team & access']];
  const nav=document.createElement('aside');nav.className='sidebar';nav.setAttribute('aria-label','Main navigation');
  nav.innerHTML='<a href="/" class="brand"><img src="/assets/programme-mark.svg" alt=""><span>Programme<small>DefectTracker suite</small></span></a><div class="nav-label">Workspace & controls</div><nav>'+links.map(([url,icon,label])=>`<a href="${url}" ${url.startsWith('/admin/')?'data-requires-edit hidden':''} ${path===url?'class="active" aria-current="page"':''}><span class="nav-icon" aria-hidden="true">${icon}</span>${label}</a>`).join('')+'</nav><div class="sidebar-foot">Built for the working day.<br><a href="/about.html">Help & guidance ↗</a><br><a href="/links.html">All tools & exports ↗</a></div>';
  document.body.prepend(nav);document.body.classList.add('legacy-page');
  fetch('/api/auth.php?action=whoami',{headers:{Accept:'application/json'}})
    .then(response => { if(!response.ok) throw new Error('Access unavailable'); return response.json(); })
    .then(data => {
      const editable=data.ok!==false && !data.user?.read_only && ['admin','planner'].includes(data.user?.role);
      document.querySelectorAll('[data-requires-edit]').forEach(link => { link.hidden=!editable; });
    }).catch(() => {}); // Editing navigation remains hidden until identity is verified.

  const header=document.querySelector('body>header');
  if(header){const button=document.createElement('button');button.className='menu-toggle';button.textContent='☰';button.setAttribute('aria-label','Toggle navigation');button.setAttribute('aria-expanded','false');button.onclick=()=>{const open=document.body.classList.toggle('nav-open');button.setAttribute('aria-expanded',String(open));};header.prepend(button);}
  const backdrop=document.createElement('div');backdrop.className='menu-backdrop';backdrop.onclick=()=>{document.body.classList.remove('nav-open');document.querySelector('.menu-toggle')?.setAttribute('aria-expanded','false');};document.body.prepend(backdrop);
  document.addEventListener('keydown',event=>{if(event.key==='Escape')backdrop.click();});
})();

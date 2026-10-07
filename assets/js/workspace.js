/* Programme workspace: same scheduling APIs, no front-end build or CDN required. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const iso = date => date.toISOString().slice(0, 10);
  const date = value => new Date(`${value}T12:00:00Z`);
  const addDays = (value, n) => { const d = date(value); d.setUTCDate(d.getUTCDate() + n); return iso(d); };
  const dayDiff = (a, b) => Math.round((date(b) - date(a)) / 86400000);
  const format = value => value ? date(value).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric', timeZone:'UTC'}) : 'Not scheduled';
  const statusNames = {planned:'Planned', in_progress:'Scheduled today', delayed:'Overdue', complete:'Complete', unscheduled:'Unscheduled'};
  const initialView = document.body.dataset.initialView || 'timeline';
  const state = {view:initialView, tasks:[], projects:[], contractors:[], project:null, today:iso(new Date()), user:null, csrf:null, editing:null, pendingMove:null, loaded:false, editable:false, request:0};
  const params = new URLSearchParams(location.search);
  if (['timeline','list','lookahead'].includes(params.get('view'))) state.view = params.get('view');
  if (/^\d+$/.test(params.get('project') || '')) $('project').value = params.get('project');
  const requestedProject = params.get('project');
  let toastTimer, searchTimer, drag;
  function toast(message) { $('toast').textContent = message; $('toast').hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => $('toast').hidden = true, 5000); }
  async function api(url, options) {
    const response = await fetch(url, {...options, credentials:'same-origin', headers:{Accept:'application/json', ...(options?.headers || {})}});
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('The server returned an unexpected response. Please try again or check the site health.'); }
    if (!response.ok || data.ok === false || data.error) {
      if (response.status === 403) throw new Error('Sign in with an admin or planner account to make changes.');
      if (response.status === 419) throw new Error('Your session has expired. Sign in again before saving.');
      throw new Error(data.error || 'Could not complete this request.');
    }
    return data;
  }
  function locationName(t) { return [t.block && `Block ${t.block}`, t.floor && `Floor ${t.floor}`, t.unit && `Unit ${t.unit}`].filter(Boolean).join(' / ') || `Location #${t.apartment_id}`; }
  function options(id, label, values) {
    const selected = $(id).value;
    $(id).innerHTML = `<option value="">${label}</option>` + [...new Set(values.filter(v => v != null && v !== '').map(String))].sort((a,b) => a.localeCompare(b,undefined,{numeric:true})).map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
    if ([...$(id).options].some(o => o.value === selected)) $(id).value = selected;
  }
  function filterOptions() {
    options('block','All blocks',state.tasks.map(t=>t.block));
    options('floor','All floors',state.tasks.filter(t=>!$('block').value || String(t.block) === $('block').value).map(t=>t.floor));
    options('contractor','All contractors',state.tasks.map(t=>t.contractor || 'Unassigned'));
    $('contractor-options').innerHTML = [...new Set([...state.tasks.map(t=>t.contractor),...state.contractors.map(c=>c.name)].filter(Boolean))].map(v=>`<option value="${esc(v)}"></option>`).join('');
  }
  function filtered() {
    const query = $('search').value.trim().toLowerCase();
    return state.tasks.filter(t => (!query || [t.name,t.contractor,t.zone,locationName(t)].join(' ').toLowerCase().includes(query)) && (!$('block').value || String(t.block) === $('block').value) && (!$('floor').value || String(t.floor) === $('floor').value) && (!$('contractor').value || (t.contractor || 'Unassigned') === $('contractor').value) && (!$('status').value || t.status === $('status').value));
  }
  function summary() {
    const tasks = state.tasks, total = tasks.length, completed = tasks.filter(t=>t.status==='complete').length;
    const progress = total ? Math.round(tasks.reduce((sum,t)=>sum+Number(t.percent_complete||0),0)/total) : 0;
    const active = tasks.filter(t=>t.status==='in_progress');
    const overdue = tasks.filter(t=>t.status==='delayed').length;
    const unknown = tasks.filter(t=>t.status==='unscheduled').length;
    $('stat-total').textContent = total.toLocaleString();
    $('stat-total-detail').textContent = `${new Set(tasks.map(t=>t.apartment_id)).size} locations${unknown ? ` · ${unknown} unscheduled` : ''}`;
    $('stat-percent').textContent = `${progress}%`;
    $('overall-progress').style.width = `${progress}%`;
    $('stat-progress-detail').textContent = `${completed} of ${total} activities complete · unweighted`;
    $('stat-running').textContent = active.length.toLocaleString();
    $('stat-workforce').textContent = `${active.reduce((sum,t)=>sum+Number(t.operatives||0),0)} planned operatives · ${format(state.today)}`;
    $('stat-overdue').textContent = overdue.toLocaleString();
  }
  function updateExports() {
    const project = state.project?.id || 1;
    const days = $('window').value, from = $('from').value, group = $('group').value === 'contractor' ? 'contractor' : 'apartment';
    const queries = new URLSearchParams({project, days, from, group});
    const urls = {csv:`/api/export/csv.php?project=${project}`, tasklist:`/api/export/tasklist_xlsx.php?project=${project}`, lookahead:`/api/export/lookahead_xlsx.php?${queries}`, shortterm:`/api/export/shortterm.php?${queries}`};
    document.querySelectorAll('[data-export]').forEach(a => a.href = urls[a.dataset.export]);
  }
  function updateView() {
    document.querySelectorAll('[data-view]').forEach(button => { const active = button.dataset.view === state.view; button.classList.toggle('active',active); button.setAttribute('aria-pressed', String(active)); });
    document.querySelectorAll('[data-nav]').forEach(a => { const active = a.dataset.nav === (state.view === 'lookahead' ? 'lookahead' : 'timeline'); a.classList.toggle('active',active); if (active) a.setAttribute('aria-current','page'); else a.removeAttribute('aria-current'); });
    $('workspace-title').textContent = state.view === 'list' ? 'Activity register' : state.view === 'lookahead' ? 'Lookahead planner' : 'Programme timeline';
    $('workspace-description').textContent = state.view === 'list' ? 'Every activity, including work still to be scheduled.' : state.view === 'lookahead' ? 'Coordinate the work ahead, one date window at a time.' : 'The detail you need. The bigger picture you want.';
    $('page-title').textContent = state.view === 'lookahead' ? 'Make the next few weeks count.' : 'Your programme, in focus.';
    $('breadcrumb-title').textContent = state.view === 'lookahead' ? 'Lookahead planner' : 'Construction programme';
    $('zoom').hidden = state.view === 'list'; $('baseline').closest('label').hidden = state.view === 'list';
  }
  function empty(title, message, retry=false) { $('content').innerHTML = `<div class="empty"><div><h3>${esc(title)}</h3><p>${esc(message)}</p>${retry ? '<button id="retry-load" class="primary">Try again</button>' : ''}</div></div>`; if (retry) $('retry-load').onclick = () => load(); }
  function render() {
    updateView(); updateExports();
    if (!state.loaded) return;
    const start = $('from').value, days = Number($('window').value), end = addDays(start,days-1);
    $('date-label').textContent = `${format(start).replace(/ \d{4}$/,'')} – ${format(end).replace(/ \d{4}$/,'')}`;
    const tasks = filtered();
    const visible = state.view === 'list' ? tasks : tasks.filter(t=>t.start_date && t.finish_date && t.start_date<=end && t.finish_date>=start);
    $('results').textContent = `${visible.length} shown / ${state.tasks.length} activities`;
    $('edit-hint').textContent = state.editable ? 'Click to edit · drag to move · drag right edge to resize' : 'Click an activity to see the detail';
    if (!visible.length) {
      if (!state.tasks.length) empty('Your programme starts here','Import an Excel or CSV programme to bring your activities into the workspace.');
      else if (tasks.length && state.view !== 'list') empty('No scheduled work in this window','Choose Programme start, change the date window, or use Task list to see all activities.');
      else empty('No matching activities','Try a different search or reset your filters.');
      return;
    }
    if (state.view === 'list') renderList(visible); else renderTimeline(visible,start,days);
  }
  function renderList(tasks) {
    $('content').innerHTML = `<div class="task-table-wrap"><table class="task-table"><thead><tr><th>Activity / location</th><th>Contractor</th><th>Start</th><th>Finish</th><th>Duration</th><th>Ops</th><th>Progress</th><th>Status</th></tr></thead><tbody>${tasks.map(t=>`<tr><td><button data-task="${t.id}">${esc(t.name)}</button><small>${esc(locationName(t))}${t.zone ? ` · ${esc(t.zone)}` : ''}</small></td><td>${esc(t.contractor||'Unassigned')}</td><td>${format(t.start_date)}</td><td>${format(t.finish_date)}</td><td>${t.duration_days} days</td><td>${t.operatives}</td><td>${t.percent_complete}%</td><td><span class="badge ${t.status}">${statusNames[t.status]}</span></td></tr>`).join('')}</tbody></table></div>`;
  }
  function renderTimeline(tasks,start,days) {
    const cell = Number($('zoom').value), width = days*cell;
    let headers = '', bands = '';
    for(let i=0;i<days;i++) {
      const value = addDays(start,i), d = date(value), weekend = [0,6].includes(d.getUTCDay());
      headers += `<div class="date-cell ${weekend?'weekend':''} ${value===state.today?'today':''}"><span>${d.toLocaleDateString('en-GB',{weekday:'short',timeZone:'UTC'}).slice(0,1)}</span><b>${d.getUTCDate()}</b><span>${d.getUTCDate()===1 || i===0 ? d.toLocaleDateString('en-GB',{month:'short',timeZone:'UTC'}) : ''}</span></div>`;
      if(weekend) bands += `<span class="weekend-band" style="left:${i*cell}px"></span>`;
    }
    const todayIndex = dayDiff(start,state.today);
    if(todayIndex>=0 && todayIndex<days) bands += `<span class="today-line" style="left:${todayIndex*cell}px"></span>`;
    const groupBy = $('group').value;
    const groups = new Map();
    tasks.forEach(t => { const key = groupBy==='contractor' ? t.contractor||'Unassigned' : locationName(t); if(!groups.has(key)) groups.set(key,[]); groups.get(key).push(t); });
    let rows = '';
    for(const [group, items] of groups) {
      rows += `<div class="group-title">${esc(group)} <span class="muted">/ ${items.length} activities</span></div>`;
      for(const t of items) {
        const left = Math.max(0,dayDiff(start,t.start_date))*cell;
        const right = Math.min(days,dayDiff(start,t.finish_date)+1)*cell;
        const label = `${t.is_milestone ? 'Milestone: ' : ''}${t.name} · ${t.contractor||'Unassigned'} · ${format(t.start_date)} to ${format(t.finish_date)} · ${t.percent_complete}% complete`;
        let baseline = '';
        if($('baseline').checked && t.baseline_start && t.baseline_finish) {
          const bl = Math.max(0,dayDiff(start,t.baseline_start))*cell, br = Math.min(days,dayDiff(start,t.baseline_finish)+1)*cell;
          if(br>bl) baseline = `<span class="baseline-bar" title="Baseline: ${format(t.baseline_start)} – ${format(t.baseline_finish)}" style="left:${bl}px;width:${br-bl}px"></span>`;
        }
        rows += `<div class="timeline-label"><button data-task="${t.id}" title="${esc(label)}"><span class="task-name">${esc(t.name)}</span><small>${esc(groupBy==='contractor'?locationName(t):t.contractor||'Unassigned')}${t.zone ? ` · ${esc(t.zone)}` : ''}</small></button></div><div class="timeline-lane">${bands}${baseline}<button class="task-bar ${t.status}" data-task="${t.id}" data-bar="true" data-editable="${state.editable}" aria-label="${esc(label)}" title="${esc(label)}" style="left:${left}px;width:${Math.max(5,right-left)}px"><span class="bar-progress" style="width:${t.percent_complete}%"></span><span class="bar-text">${t.is_milestone ? '◆ ' : ''}${esc(t.name)}${right-left>150 ? ` · ${t.percent_complete}%` : ''}</span>${state.editable && t.finish_date<=addDays(start,days-1) ? '<span class="resize-handle" aria-hidden="true"></span>' : ''}</button></div>`;
      }
    }
    $('content').innerHTML = `<div class="timeline-scroll"><div class="timeline-grid" style="--timeline-width:${width}px;--day:${cell}px"><div class="timeline-label head">Activity / ${groupBy==='contractor'?'location':'contractor'}</div><div class="timeline-dates">${headers}</div>${rows}</div></div>`;
  }
  async function load(projectId) {
    const request = ++state.request;
    $('refresh').disabled = true; $('content').setAttribute('aria-busy','true');
    $('connection').innerHTML = '<i></i>Updating';
    try {
      const project = projectId || state.project?.id || requestedProject;
      const result = await api(`/api/workspace.php${project ? '?project='+encodeURIComponent(project) : ''}`);
      if (request !== state.request) return;
      const changed = state.project?.id !== result.project.id;
      state.tasks = result.tasks; state.contractors = result.contractors || []; state.projects = result.projects; state.project = result.project; state.today = result.today; state.loaded = true;
      $('project').innerHTML = state.projects.map(p=>`<option value="${Number(p.id)}">${esc(p.name)}</option>`).join('');
      $('project').value = String(state.project.id);
      $('project-subtitle').textContent = `${state.project.name} · Plan, coordinate and track your construction programme.`;
      if(changed || !$('from').value) {
        const scheduled = state.tasks.filter(t=>t.start_date).map(t=>t.start_date).sort();
        const latest = state.tasks.filter(t=>t.finish_date).map(t=>t.finish_date).sort().at(-1);
        $('from').value = state.view==='lookahead' ? state.today : latest && latest<state.today ? scheduled[0] : state.today;
        if(!$('from').value) $('from').value = state.today;
      }
      filterOptions(); summary(); render();
      $('updated').textContent = `Updated ${new Date().toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'})} · ${state.project.timezone}`;
      $('connection').innerHTML = '<i></i>Up to date';
    } catch(err) {
      if (request !== state.request) return;
      state.loaded = false;
      empty('Programme unavailable',err.message,true);
      $('connection').textContent = 'Could not update';
      $('results').textContent = 'Data could not be loaded';
    } finally { if(request===state.request) { $('refresh').disabled = false; $('content').setAttribute('aria-busy','false'); } }
  }
  async function auth() {
    try {
      const data = await api('/api/auth.php?action=whoami');
      state.user = data.user; state.csrf = data.csrf;
      state.editable = ['admin','planner'].includes(data.user?.role);
      $('account-link').textContent = data.user ? `${data.user.name} · ${data.user.read_only ? 'Read-only' : data.user.role}` : 'Sign in';
      $('account-link').href = data.user ? '/admin/' : '/login.html';
      $('avatar').textContent = data.user ? data.user.name.split(' ').map(n=>n[0]).slice(0,2).join('').toUpperCase() : 'P';
      $('logout').hidden = !data.user;
      if(state.loaded) render();
    } catch (_) { $('account-link').textContent = 'Sign in'; }
  }
  async function openTask(id) {
    const task = state.tasks.find(t=>String(t.id)===String(id)); if(!task) return;
    state.editing = task;
    $('task-location').textContent = locationName(task);
    $('task-dialog-title').textContent = state.editable ? 'Edit activity' : 'Activity details';
    $('task-status').className = `badge ${task.status}`; $('task-status').textContent = statusNames[task.status];
    $('task-dates').textContent = `${format(task.start_date)} → ${format(task.finish_date)}`;
    const fields = {'edit-name':task.name,'edit-contractor':task.contractor||'','edit-ops':task.operatives,'edit-duration':task.duration_days,'edit-zone':task.zone||'','edit-progress':task.percent_complete,'edit-constraint':task.constraint_start||''};
    Object.entries(fields).forEach(([id,value])=>{ $(id).value=value; $(id).disabled=!state.editable; });
    $('save-task').hidden = !state.editable;
    $('edit-notice').textContent = state.editable ? 'Dates are recalculated using project dependencies and the working calendar. Leave a constraint empty to remove it.' : 'This is a read-only view. Sign in as an admin or planner to update this activity.';
    const alerts=[];
    if(task.alerts?.tight?.length) alerts.push(`${task.alerts.tight.length} tight predecessor handover(s)`);
    if(task.alerts?.overlap_with?.length) alerts.push(`${task.alerts.overlap_with.length} activity overlap(s) in this zone`);
    if(task.baseline_finish && task.finish_date) {const slip=dayDiff(task.baseline_finish,task.finish_date); if(slip) alerts.push(`Finish ${Math.abs(slip)} calendar days ${slip>0?'later':'earlier'} than baseline`);}
    $('task-alerts').hidden = !alerts.length; $('task-alerts').textContent = alerts.join(' · ');
    $('form-error').textContent = ''; $('task-dialog').showModal();
  }
  async function writeTask(body) {
    if(!state.csrf) await auth();
    if(!state.editable) throw new Error('Sign in as an admin or planner to update the programme.');
    return api('/api/tasks.php?action=update', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF':state.csrf},body:JSON.stringify(body)});
  }
  $('task-form').addEventListener('submit',async event => {
    event.preventDefault(); if(!state.editing || !state.editable) return;
    $('save-task').disabled = true; $('save-task').textContent = 'Saving…'; $('form-error').textContent = '';
    try {
      await writeTask({id:state.editing.id,name:$('edit-name').value.trim(),contractor_name:$('edit-contractor').value.trim(),operatives:Number($('edit-ops').value),duration_days:Number($('edit-duration').value),zone:$('edit-zone').value.trim(),percent_complete:Number($('edit-progress').value),constraint_start:$('edit-constraint').value||null});
      $('task-dialog').close(); await load(); toast('Activity saved. Programme dates recalculated.');
    } catch(err) { $('form-error').textContent=err.message; }
    finally { $('save-task').disabled=false; $('save-task').textContent='Save changes'; }
  });
  $('close-task').onclick = $('cancel-task').onclick = () => $('task-dialog').close();
  $('content').addEventListener('click',event => {
    const button=event.target.closest('[data-task]');
    if(button && !button.dataset.suppressClick) openTask(button.dataset.task);
    if(button) delete button.dataset.suppressClick;
  });
  // A drag proposes a change; the user applies it after reviewing the dates.
  $('content').addEventListener('pointerdown',event=>{
    const bar=event.target.closest('[data-bar]');
    if(!bar || !state.editable || event.button!==0 || event.pointerType==='touch') return;
    const task=state.tasks.find(t=>String(t.id)===bar.dataset.task);
    drag={bar,task,pointer:event.pointerId,x:event.clientX,left:parseFloat(bar.style.left),width:parseFloat(bar.style.width),resize:!!event.target.closest('.resize-handle'),delta:0};
    bar.setPointerCapture(event.pointerId);
  });
  $('content').addEventListener('pointermove',event=>{
    if(!drag || event.pointerId!==drag.pointer) return;
    drag.delta=Math.round((event.clientX-drag.x)/Number($('zoom').value));
    const px=drag.delta*Number($('zoom').value);
    if(drag.resize) drag.bar.style.width=Math.max(5,drag.width+px)+'px';
    else drag.bar.style.left=(drag.left+px)+'px';
  });
  function resetDrag() { if(drag) {drag.bar.style.left=drag.left+'px';drag.bar.style.width=drag.width+'px';} drag=null; }
  $('content').addEventListener('pointercancel',resetDrag);
  $('content').addEventListener('pointerup',event=>{
    if(!drag || event.pointerId!==drag.pointer) return;
    const {bar,task,delta,resize}=drag;
    resetDrag(); if(!delta) return;
    bar.dataset.suppressClick='1'; setTimeout(()=>delete bar.dataset.suppressClick,150);
    const start=resize?task.start_date:addDays(task.start_date,delta), finish=addDays(task.finish_date,delta);
    if(finish<start) {toast('Finish must be on or after the start date.');return;}
    state.pendingMove={id:task.id,name:task.name,start_date:start};
    if(resize) state.pendingMove.finish_date=finish;
    $('move-description').textContent = `${resize?'Resize':'Move'} “${task.name}” from ${format(task.start_date)} – ${format(task.finish_date)} to ${format(start)} – ${format(finish)}?`;
    $('move-error').textContent='';$('move-dialog').showModal();
  });
  $('cancel-move').onclick=()=>{$('move-dialog').close();state.pendingMove=null;};
  $('confirm-move').onclick=async()=>{
    if(!state.pendingMove) return; $('confirm-move').disabled=true;
    try{await writeTask(state.pendingMove);$('move-dialog').close();state.pendingMove=null;await load();toast('Programme change applied. Linked dates recalculated.');}
    catch(err){$('move-error').textContent=err.message;}finally{$('confirm-move').disabled=false;}
  };
  document.querySelectorAll('[data-view]').forEach(button=>button.onclick=()=>{state.view=button.dataset.view;render();});
  $('search').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(render,120);});
  ['floor','contractor','status','window','zoom','group','baseline'].forEach(id=>$(id).addEventListener('change',render));
  $('block').onchange=()=>{filterOptions();render();};
  $('from').onchange=()=>{if($('from').value) render();};
  $('clear-filters').onclick=()=>{['search','block','floor','contractor','status'].forEach(id=>$(id).value='');filterOptions();render();};
  $('project').onchange=()=>{['search','block','floor','contractor','status'].forEach(id=>$(id).value='');load($('project').value);};
  $('previous').onclick=()=>{$('from').value=addDays($('from').value,-Number($('window').value));render();};
  $('next').onclick=()=>{$('from').value=addDays($('from').value,Number($('window').value));render();};
  $('today').onclick=()=>{$('from').value=state.today;render();};
  $('fit').onclick=()=>{const starts=filtered().filter(t=>t.start_date).map(t=>t.start_date).sort();if(starts.length){$('from').value=starts[0];render();}else toast('No scheduled activities match these filters.');};
  $('refresh').onclick=()=>load();
  function focus(enabled){document.body.classList.toggle('focus-mode',enabled);$('focus').setAttribute('aria-pressed',String(enabled));}
  $('focus').onclick=()=>focus(true);$('focus-exit').onclick=()=>focus(false);
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){focus(false);document.body.classList.remove('nav-open');$('menu-toggle').setAttribute('aria-expanded','false');}});
  $('menu-toggle').onclick=()=>{const open=document.body.classList.toggle('nav-open');$('menu-toggle').setAttribute('aria-expanded',String(open));};
  $('menu-backdrop').onclick=()=>{document.body.classList.remove('nav-open');$('menu-toggle').setAttribute('aria-expanded','false');};
  $('print').onclick=event=>{event.preventDefault();window.print();};
  function statusFilter(status){$('status').value=status;if(status==='delayed' || status==='complete') state.view='list'; if(status==='in_progress') $('from').value=state.today; render();}
  $('stat-all').onclick=()=>{state.view='list';$('status').value='';render();};
  $('stat-progress').onclick=()=>statusFilter('complete');$('stat-active').onclick=()=>statusFilter('in_progress');$('stat-delayed').onclick=()=>statusFilter('delayed');
  $('logout').onclick=async()=>{try{await api('/api/auth.php?action=logout',{method:'POST',headers:{'X-CSRF':state.csrf}});location.href='/login.html';}catch(err){toast(err.message);}};
  updateView(); load(); auth();
})();

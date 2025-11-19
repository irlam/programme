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
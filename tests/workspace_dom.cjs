/* Run with jsdom installed: node tests/workspace_dom.cjs. DOM checks, not browser layout tests. */
const {JSDOM, VirtualConsole} = require(process.env.PROGRAMME_TEST_JSDOM || 'jsdom');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname,'..');
const tasks = [
  ['Ground floor slab','Groundworks',0,4,100,'complete','Ground','01'],
  ['Blockwork to level 01','Brickwork',2,9,70,'in_progress','1','01'],
  ['First fix mechanical','Mechanical',4,12,35,'in_progress','1','01'],
  ['First fix electrical','Electrical',4,15,25,'in_progress','1','02'],
  ['Plasterboard partitions','Drylining',10,18,0,'planned','1','02'],
  ['Tape & joint finishes','Drylining',16,24,0,'planned','1','02'],
  ['Second fix joinery','Joinery',20,29,0,'planned','2','03'],
  ['Kitchen installation','Joinery',24,33,0,'planned','2','03'],
  ['Decorations & finishes','Decorating',28,38,0,'planned','2','03'],
  ['Quality inspection','Site team',35,40,0,'planned','2','03'],
  ['Waterproofing remedials','Groundworks',-8,-1,40,'delayed','Ground','01'],
  ['Roof access detail','Metalwork',null,null,0,'unscheduled','3','04'],
].map(([name,contractor,start,finish,progress,status,floor,unit],i)=>({id:i+1,project_id:1,apartment_id:Number(unit),name,contractor,block:'A',floor,unit,operatives:3+i%4,duration_days:finish-start,start_date:start===null?null:add('2026-10-01',start),finish_date:finish===null?null:add('2026-10-01',finish),percent_complete:progress,status,zone:'Apartment',constraint_start:'2026-10-01',baseline_start:start===null?null:add('2026-10-01',start-1),baseline_finish:finish===null?null:add('2026-10-01',finish-2),alerts:{}}));
function add(d,n){const date=new Date(d+'T12:00:00Z');date.setUTCDate(date.getUTCDate()+n);return date.toISOString().slice(0,10);}
const fixture={ok:true,project:{id:1,name:'Riverside Residences',timezone:'Europe/London',start_date:'2026-10-01'},projects:[{id:1,name:'Riverside Residences'},{id:2,name:'Project two'}],today:'2026-10-05',tasks};

const tick = ms => new Promise(resolve=>setTimeout(resolve,ms || 10));
function setup(file='index.html',role='planner',fail=false){
  const errors=[];const writes=[];
  const vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(e.message));
  const dom=new JSDOM(fs.readFileSync(path.join(root,file),'utf8'),{url:'https://programme.test/'+file,runScripts:'outside-only',virtualConsole:vc});
  const w=dom.window;
  w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
  w.HTMLDialogElement.prototype.close=function(){this.open=false;};
  w.HTMLElement.prototype.setPointerCapture=function(){};
  w.fetch=async(url,opts={})=>{
    let data={ok:true};
    if(url.includes('workspace.php'))data=fail?{ok:false,error:'Test unavailable'}:fixture;
    if(url.includes('whoami'))data={ok:true,user:role?{name:'Chris Irlam',role}:null,csrf:'test-csrf'};
    if(url.includes('tasks.php')){writes.push(JSON.parse(opts.body));data={ok:true};}
    if(url.includes('analytics.php')){const type=new URL(url,'https://programme.test').searchParams.get('type');data=type==='workforce'?{ok:true,labels:['2026-10-01','2026-10-02'],total:[8,14]}:type==='tight'?{ok:true,weeks:['2026-W40'],data:{'2026-W40':{Brickwork:2}}}:type==='throughput'?{ok:true,weeks:['2026-W40','2026-W41'],started:[3,5],finished:[1,2]}:{ok:true,rows:[]};}
    return {ok:!data.error,status:data.error?503:200,json:async()=>data};
  };
  w.eval(fs.readFileSync(path.join(root,file==='analytics.html'?'assets/js/analytics.js':'assets/js/workspace.js'),'utf8'));
  return {dom,w,errors,writes,$:id=>w.document.getElementById(id),all:s=>w.document.querySelectorAll(s)};
}
(async()=>{
 const t=setup();await tick();const {$,w,all,writes}=t;
 assert.equal($('stat-total').textContent,'12');assert(all('.task-bar').length>0);
 w.document.querySelector('[data-view="list"]').click();assert.equal(all('.task-table tbody tr').length,12);
 $('search').value='electrical';$('search').dispatchEvent(new w.Event('input'));await tick(160);assert.equal(all('.task-table tbody tr').length,1);
 w.document.querySelector('[data-task="4"]').click();assert($('task-dialog').open);$('edit-progress').value='55';$('task-form').dispatchEvent(new w.Event('submit',{cancelable:true}));await tick();assert.equal(writes.at(-1).percent_complete,55);assert.equal(writes.at(-1).id,4);assert(!$('task-dialog').open);
 $('clear-filters').click();$('stat-delayed').click();assert.equal(all('.task-table tbody tr').length,1);
 $('clear-filters').click();w.document.querySelector('[data-view="timeline"]').click();$('fit').click();$('baseline').checked=true;$('baseline').dispatchEvent(new w.Event('change'));assert(all('.baseline-bar').length>0);
 const bar=w.document.querySelector('.task-bar[data-task="2"]');
 for(const [name,x] of [['pointerdown',100],['pointermove',160],['pointerup',160]]){const e=new w.MouseEvent(name,{bubbles:true,clientX:x,button:0});Object.defineProperty(e,'pointerId',{value:1});bar.dispatchEvent(e);}
 assert($('move-dialog').open);$('confirm-move').click();await tick();assert.equal(writes.at(-1).start_date,'2026-10-05');assert(!('finish_date' in writes.at(-1)));
 $('focus').click();assert(w.document.body.classList.contains('focus-mode'));w.document.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape'}));assert(!w.document.body.classList.contains('focus-mode'));
 $('menu-toggle').click();assert.equal($('menu-toggle').getAttribute('aria-expanded'),'true');$('menu-backdrop').click();assert.equal($('menu-toggle').getAttribute('aria-expanded'),'false');
 const ahead=setup('lookahead.html');await tick();assert.equal(ahead.$('workspace-title').textContent,'Lookahead planner');
 const read=setup('index.html',null);await tick();read.w.document.querySelector('[data-task="2"]').click();assert(read.$('save-task').hidden);assert(read.$('edit-name').disabled);
 const unavailable=setup('index.html',null,true);await tick();assert(unavailable.$('content').textContent.includes('Programme unavailable'));assert(unavailable.$('retry-load'));
 const reports=setup('analytics.html');await tick();assert.equal(reports.all('svg').length,3);assert(reports.$('variance-table').textContent.includes('No baseline'));
 for(const obj of [t,ahead,read,unavailable,reports]){assert.deepEqual(obj.errors,[]);obj.dom.window.close();}
 console.log('PASS: timeline, register, search, progress edit, overdue filter, baseline overlay, confirmed move payload, focus exit, navigation, lookahead, read-only roles, retry state and reports. DOM simulation; no browser layout verification.');
})().catch(err=>{console.error(err);process.exit(1)});

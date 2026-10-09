const {JSDOM}=require(process.env.PROGRAMME_TEST_JSDOM||'jsdom');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const html=fs.readFileSync(path.join(__dirname,'../admin/import.html'),'utf8');
function setup(){
 const calls=[];let auth='manager',fail=false;
 const dom=new JSDOM(html.replace("await import('/assets/js/programme-pdf.js')", 'await window.testPdf()'),{url:'https://alpha.programme.defecttracker.uk/admin/import.html',runScripts:'dangerously',beforeParse(w){
  w.testPdf=async()=>({extractProgrammePdf:async()=>({source_rows:3,tasks:[{name:'PDF activity'}],warnings:['Review required.']}),programmeCsv:()=> 'Task,Start,Finish\nPDF activity,2026-10-01,2026-10-02'});
  w.fetch=async(url,options={})=>{calls.push({url,options});if(url.includes('/api/auth.php'))return{ok:auth!=='expired',json:async()=>auth==='expired'?{ok:false}:{ok:true,csrf:'fresh-token',user:{read_only:auth==='viewer'}}};
   if(url.includes('/preview.php'))return{ok:!fail,status:fail?419:200,text:async()=>JSON.stringify(fail?{ok:false,error:'access_unavailable'}:{ok:true,mode:'tasklist',tasks:[{name:'Test',start:'2026-10-01',finish:'2026-10-02'}]})};
   return{ok:true,json:async()=>({ok:true})};
  };
 }});
 const w=dom.window,d=w.document;
 Object.defineProperty(d.getElementById('file'),'files',{value:[new w.File(['test'],'test.csv')],configurable:true});
 return{w,d,calls,setAuth(v){auth=v},setFail(v){fail=v}};
}
(async()=>{
 const t=setup();t.d.getElementById('debugBtn').click();
 await t.d.getElementById('previewBtn').onclick();
 assert.equal(t.calls[0].url,'/api/auth.php?action=whoami');
 const req=t.calls[2];assert.equal(req.url,'/api/import/preview.php');assert.equal(req.options.headers['X-CSRF'],'fresh-token');assert(!req.options.headers['Content-Type']);assert.equal(req.options.body.get('project'),'1');
 assert.equal(t.d.getElementById('status').textContent,'OK');
 await t.d.getElementById('importBtn').onclick();assert.equal(t.calls[3].url,'/api/auth.php?action=whoami');assert.equal(t.calls[4].options.headers['X-CSRF'],'fresh-token');assert.equal(JSON.parse(t.calls[4].options.body).tasks[0].name,'Test');
 t.setFail(true);await t.d.getElementById('previewBtn').onclick();assert(t.d.getElementById('status').textContent.includes('HTTP 419'));const n=t.calls.length;
 await t.d.getElementById('importBtn').onclick();assert.equal(t.calls.length,n);assert.equal(t.d.getElementById('importStatus').textContent,'No preview');
 for(const role of ['expired','viewer']){const x=setup();x.setAuth(role);await x.d.getElementById('previewBtn').onclick();assert.equal(x.calls.length,1);assert(x.d.getElementById('status').textContent.includes(role==='viewer'?'read-only':'Sign in'));x.w.close()}
 const pdf=setup();Object.defineProperty(pdf.d.getElementById('file'),'files',{value:[new pdf.w.File(['PDF'],'schedule.pdf')],configurable:true});
 pdf.d.getElementById('autoFS').checked=true;await pdf.d.getElementById('previewBtn').onclick();
 assert.equal(pdf.calls[2].options.body.get('file').name,'programme-extracted.csv');assert.equal(pdf.calls[2].options.body.get('auto_fs'),'0');
 assert(!pdf.d.getElementById('pdf-review-label').hidden);let count=pdf.calls.length;
 await pdf.d.getElementById('importBtn').onclick();assert.equal(pdf.calls.length,count);assert(pdf.d.getElementById('importStatus').textContent.includes('review box'));
 pdf.d.getElementById('pdf-review').checked=true;await pdf.d.getElementById('importBtn').onclick();assert.equal(pdf.calls.length,count+2);pdf.w.close();
 t.w.close();console.log('PASS: preview and commit use fresh verified CSRF, debug stays client-side, multipart boundary preserved, expired/viewer uploads stopped, stale preview cannot commit after failure');
})().catch(e=>{console.error(e);process.exit(1)});

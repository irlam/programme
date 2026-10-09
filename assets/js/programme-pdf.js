// Asta printed programme table; no server-side PDF tools required.
export function parseProgrammePages(pages) {
 const rows=[],seen=new Set();
 for (const page of pages) {
  const items=page.items.filter(i=>typeof i.str==='string'&&i.str.trim()).map(i=>({...i,x:i.transform[4],y:page.height-i.transform[5]}));
  const headers=['Line','Name','Start','Finish','Duration'].map(s=>items.filter(i=>i.str.trim()===s).sort((a,b)=>a.y-b.y)[0]);
  if(headers.some(h=>!h))throw Error('PDF layout not recognised. Use a programme with Line, Name, Start, Finish and Duration columns.');
  const [line,name,start,finish,duration]=headers;
  if(!headers.every(h=>Math.abs(h.y-line.y)<Math.max(2,line.height))||!(line.x<name.x&&name.x<start.x&&start.x<finish.x&&finish.x<duration.x))throw Error('PDF table headers are not aligned.');
  const dates=items.filter(i=>/^\d{2}\/\d{2}\/\d{4}$/.test(i.str.trim())&&i.y>line.y+line.height&&i.x>name.x&&i.x<duration.x);
  if(dates.length<2)throw Error('No readable activity dates found. Scanned PDFs are not supported.');
  const nameEnd=Math.min(...dates.map(i=>i.x))-.5;
  const lineEnd=line.x+line.width+Math.max(1,line.height*.2);
  const anchors=items.filter(i=>/^\d+$/.test(i.str.trim())&&i.x>=line.x-line.width&&i.x<lineEnd&&i.y>line.y+line.height).sort((a,b)=>a.y-b.y);
  if(!anchors.length||anchors.length>2000)throw Error('No readable activity rows found, or the programme is too large.');
  for(let n=0;n<anchors.length;n++){
   const a=anchors[n],id=Number(a.str),top=n?(a.y+anchors[n-1].y)/2:a.y-a.height*1.5,bottom=n+1<anchors.length?(a.y+anchors[n+1].y)/2:a.y+a.height*1.5;
   const cells=items.filter(i=>i.y>=top&&i.y<bottom);
   const names=cells.filter(i=>i.x>lineEnd&&i.x<nameEnd).sort((a,b)=>Math.abs(a.y-b.y)<.4?a.x-b.x:a.y-b.y);
   const ds=cells.filter(i=>/^\d{2}\/\d{2}\/\d{4}$/.test(i.str.trim())&&i.x>=nameEnd&&i.x<duration.x).sort((a,b)=>a.x-b.x);
   if(!names.length||ds.length!==2)throw Error('Activity line '+id+' has an incomplete name or date. Nothing has been imported.');
   let title='';for(let k=0;k<names.length;k++){const prev=names[k-1],i=names[k];const gap=prev?i.x-(prev.x+prev.width):0;title+=(prev&&(Math.abs(prev.y-i.y)>.4||gap>i.height*.15)?' ':'')+i.str;}
   title=title.replace(/\s+/g,' ').trim();
   const iso=s=>{const [d,m,y]=s.split('/').map(Number),dt=new Date(Date.UTC(y,m-1,d));if(dt.getUTCFullYear()!==y||dt.getUTCMonth()!==m-1||dt.getUTCDate()!==d)throw Error('Invalid date on line '+id);return `${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`};
   const startDate=iso(ds[0].str.trim()),finishDate=iso(ds[1].str.trim());if(finishDate<startDate)throw Error('Finish precedes start on line '+id);
   const dur=cells.filter(i=>i.x>=duration.x-duration.height*.5&&i.x<duration.x+duration.width+.5).sort((a,b)=>a.x-b.x).map(i=>i.str).join(' ').trim();
   let days=null;if(dur){if(!/^(?:\d+\s*w)?\s*(?:\d+\s*d)?$/.test(dur))throw Error('Unrecognised duration on line '+id);days=Number((dur.match(/(\d+)\s*w/)||[])[1]||0)*5+Number((dur.match(/(\d+)\s*d/)||[])[1]||0);}
   if(seen.has(id))throw Error('Duplicate activity line '+id+'. Use a non-overlapping programme PDF.');seen.add(id);
   rows.push({source_line:id,name:title,start:startDate,finish:finishDate,duration_days:days,indent:Math.min(...names.map(i=>i.x)),height:a.height});
  }
 }
 const tasks=[],stack=[];let summaries=0,milestones=0;
 for(let n=0;n<rows.length;n++){
  const r=rows[n],next=rows[n+1];while(stack.length&&r.indent<=stack.at(-1).indent+.5)stack.pop();
  if(next&&next.indent>r.indent+Math.max(.5,r.height*.1)){stack.push(r);summaries++;continue;}
  if(r.duration_days===null){if(r.start!==r.finish)throw Error('Missing duration on activity line '+r.source_line);milestones++;}
  tasks.push({...r,section:stack.map(s=>s.name).join(' / ')||'Imported programme',contractor:'',duration_days:r.duration_days??1});
 }
 if(!tasks.length)throw Error('No individual activities found.');
 return {tasks,source_rows:rows.length,summary_rows:summaries,milestones,warnings:[`${summaries} summary headings excluded. ${milestones} milestones imported as same-day activities.`, 'Contractors and dependency arrows are not extracted; review the dates before importing.']};
}
export function programmeCsv(parsed){
 const q=s=>'"'+String(s??'').replace(/"/g,'""')+'"';
 return ['Task,Contractor,Start,Finish,Duration,Section',...parsed.tasks.map(t=>[t.name,t.contractor,t.start,t.finish,t.duration_days,t.section].map(q).join(','))].join('\r\n');
}
export async function extractProgrammePdf(file){
 if(file.size>20*1024*1024)throw Error('PDF must be 20 MB or smaller.');
 const pdfjs=await import('/assets/vendor/pdfjs/pdf.min.js');
 pdfjs.GlobalWorkerOptions.workerSrc='/assets/vendor/pdfjs/pdf.worker.min.js';
 let doc;try{
  doc=await pdfjs.getDocument({data:new Uint8Array(await file.arrayBuffer()),isEvalSupported:false,useSystemFonts:true}).promise;
  if(doc.numPages>20)throw Error('Use a programme PDF with no more than 20 pages.');
  const pages=[];for(let n=1;n<=doc.numPages;n++){const p=await doc.getPage(n);const viewport=p.getViewport({scale:1});if(viewport.rotation!==0)throw Error('Rotate the programme to its normal orientation before importing.');pages.push({height:viewport.height,items:(await p.getTextContent()).items});}
  return parseProgrammePages(pages);
 }catch(e){if(e.name==='PasswordException')throw Error('Password-protected PDFs are not supported.');throw e;}finally{if(doc)await doc.destroy();}
}

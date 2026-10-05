(() => {
  const $=id=>document.getElementById(id);
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let request=0;
  async function get(url){const r=await fetch(url,{headers:{Accept:'application/json'}});let j;try{j=await r.json();}catch{throw new Error('The report server returned an unexpected response.');}if(!r.ok||j.ok===false)throw new Error(j.error||'Report unavailable');return j;}
  function table(headers,rows){return `<table><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.map(row=>`<tr>${row.map(v=>`<td>${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;}
  function chart(id,labels,series){
    if(!labels.length){$(id).innerHTML='<div class="report-empty">No scheduled data available yet.</div>';return;}
    const width=800,height=250,left=48,right=15,top=20,bottom=37,plot=width-left-right,ph=height-top-bottom;
    const max=Math.max(1,...series.flatMap(s=>s.values).map(Number));
    // Aggregate consecutive buckets by peak value for legibility on long programmes.
    const size=Math.max(1,Math.ceil(labels.length/80));
    const buckets=[];
    for(let i=0;i<labels.length;i+=size)buckets.push({label:labels[i],end:labels[Math.min(labels.length-1,i+size-1)],values:series.map(s=>Math.max(...s.values.slice(i,i+size).map(Number)))});
    const bw=plot/buckets.length;
    let svg=`<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${esc(id.replace('-chart',''))} chart. Exact values are available in the data table below.">`;
    for(let i=0;i<=4;i++){const y=top+ph-i*ph/4;svg+=`<line x1="${left}" x2="${width-right}" y1="${y}" y2="${y}" stroke="#e4e9f0"/><text x="${left-9}" y="${y+4}" text-anchor="end" font-family="system-ui" font-size="10" fill="#6a788c">${Math.round(max*i/4)}</text>`;}
    buckets.forEach((bucket,i)=>{
      series.forEach((s,n)=>{const value=bucket.values[n],h=value/max*ph;svg+=`<rect x="${left+i*bw+n*bw/series.length+2}" y="${top+ph-h}" width="${Math.max(1,bw/series.length-4)}" height="${h}" rx="2" fill="${s.colour}"><title>${esc(bucket.label)}${bucket.end!==bucket.label?' – '+esc(bucket.end):''}: ${esc(s.name)} ${value}${size>1?' (peak in period)':''}</title></rect>`;});
      if(i%Math.max(1,Math.ceil(buckets.length/6))===0)svg+=`<text x="${left+i*bw+bw/2}" y="${height-13}" text-anchor="middle" font-family="system-ui" font-size="10" fill="#6a788c">${esc(bucket.label)}</text>`;
    });
    svg+='</svg>';$(id).innerHTML=svg+(size>1?'<small>Long programmes show the peak per date bucket. Expand the data table for every value.</small>':'');
  }
  async function load(){
    const seq=++request,p=$('report-project').value;$('report-refresh').disabled=true;$('report-status').textContent='Loading reports…';
    const types=['workforce','tight','throughput','variance'];
    const results=await Promise.allSettled(types.map(type=>get(`/api/analytics.php?project=${encodeURIComponent(p)}&type=${type}`)));
    if(seq!==request)return;
    const failed=[];
    results.forEach((result,i)=>{
      const type=types[i];
      if(result.status==='rejected'){failed.push(type);if(type==='variance')$('variance-table').textContent=result.reason.message;else{$(type+'-chart').innerHTML=`<div class="report-empty">${esc(result.reason.message)}</div>`;$(type+'-table').innerHTML='';}return;}
      const d=result.value;
      if(type==='workforce'){chart('workforce-chart',d.labels,[{name:'Operatives',values:d.total,colour:'#267760'}]);$('workforce-table').innerHTML=table(['Date','Planned operatives'],d.labels.map((label,j)=>[label,d.total[j]]));}
      if(type==='tight'){const values=d.weeks.map(w=>Object.values(d.data[w]||{}).reduce((sum,n)=>sum+Number(n),0));chart('tight-chart',d.weeks,[{name:'Tight handovers',values,colour:'#c69244'}]);$('tight-table').innerHTML=table(['Week','Contractor','Handovers'],d.weeks.flatMap(w=>Object.entries(d.data[w]||{}).map(([c,n])=>[w,c,n])));}
      if(type==='throughput'){chart('throughput-chart',d.weeks,[{name:'Starts',values:d.started,colour:'#267760'},{name:'Finishes',values:d.finished,colour:'#aac6b5'}]);$('throughput-table').innerHTML=table(['Week','Starts','Finishes'],d.weeks.map((w,j)=>[w,d.started[j],d.finished[j]]));}
      if(type==='variance')$('variance-table').innerHTML=d.rows.length?table(['Activity','Contractor','Baseline finish','Scheduled finish','Start movement (days)','Finish movement (days)'],d.rows.map(t=>[t.task,t.contractor||'Unassigned',t.baseline_finish||'—',t.finish||'—',t.start_slip_days??'—',t.finish_slip_days??'—'])):'<div class="report-empty">No baseline dates available. Capture a baseline to track programme movement.</div>';
    });
    $('report-status').textContent=failed.length?`Could not load: ${failed.join(', ')}. Use Refresh to try again.`:'';$('report-refresh').disabled=false;
  }
  $('report-project').onchange=load;$('report-refresh').onclick=load;
  (async()=>{try{const d=await get('/api/workspace.php');$('report-project').innerHTML=d.projects.map(p=>`<option value="${Number(p.id)}">${esc(p.name)}</option>`).join('');$('report-project').value=String(d.project.id);await load();}catch(err){$('report-status').textContent=err.message;}})();
})();

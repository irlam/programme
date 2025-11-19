/* Autocomplete list for contractor names (safe API) */
async function setupContractorDatalist(inputId){
  const inp = document.getElementById(inputId);
  if (!inp) return;
  let dl = document.getElementById('contractor-list');
  if (!dl){ dl = document.createElement('datalist'); dl.id='contractor-list'; document.body.appendChild(dl); }
  inp.setAttribute('list','contractor-list');
  try {
    const res = await fetch('/api/contractors.php?action=list');
    const j = await res.json();
    dl.innerHTML = (j.items||[]).map(r=>`<option value="${r.name}"></option>`).join('');
  } catch(e){
    console.warn('contractor list load failed', e);
  }
}
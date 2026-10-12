(()=>{'use strict';const E=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),api=(p,o)=>window.CVTaraAPI.request(p,o),money=p=>'₹'+(p/100).toLocaleString('en-IN',{minimumFractionDigits:2}),when=d=>new Date(d).toLocaleString(),table=(heads,rows)=>'<div class="launch-table"><table><thead><tr>'+heads.map(h=>'<th>'+E(h)+'</th>').join('')+'</tr></thead><tbody>'+ (rows.length?rows.map(r=>'<tr>'+r.map(c=>'<td>'+c+'</td>').join('')+'</tr>').join(''):'<tr><td colspan="'+heads.length+'">No records yet.</td></tr>')+'</tbody></table></div>';
function status(el,e){el.textContent=e.message||e;}
window.CVTaraExport=(type,id)=>{let dialog=document.getElementById('exportDialog');if(!dialog){dialog=document.createElement('dialog');dialog.id='exportDialog';dialog.className='export-dialog';document.body.append(dialog);}dialog.innerHTML='<h2>Download your '+E(type)+'</h2><p>Save your latest edits to your profile before downloading. Selected layout opens your chosen design for printing or saving as PDF. Compact PDF uses a simplified print layout; Word is editable.</p><p>Download a watermarked PDF first to check page breaks and spelling. Each clean file uses one export from your purchased allowance.</p><div class="export-options">'+['print','pdf','docx'].map(f=>'<button class="button secondary" data-format="'+f+'">Preview '+(f==='print'?'selected layout':f.toUpperCase())+'</button><button class="button primary" data-format="'+f+'" data-clean="1">Clean '+(f==='print'?'selected layout':f.toUpperCase())+'</button>').join('')+'</div><p role="status"></p><button class="button secondary" data-close>Close</button>';dialog.querySelector('[data-close]').onclick=()=>dialog.close();dialog.querySelectorAll('[data-format]').forEach(b=>b.onclick=async()=>{if(!id){dialog.querySelector('[role=status]').textContent='Save this draft to your profile first, then reopen Download options.';return;}const printWindow=b.dataset.format==='print'?window.open('','_blank'):null;if(printWindow)printWindow.opener=null;b.disabled=true;const s=dialog.querySelector('[role=status]');s.textContent='Preparing your document…';try{const r=await fetch('/export/'+type+'/'+id+'/'+b.dataset.format,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({clean:b.dataset.clean==='1'})});if(!r.ok){let d;try{d=await r.json();}catch{}if(r.status===402)throw Error('What Next? Let\'s Make You Job-Ready instantly. Choose a pass to unlock a clean download.');throw Error(d?.error||d?.message||'Download unavailable. Check your plan and saved draft.');}const url=URL.createObjectURL(await r.blob()),a=document.createElement('a');if(printWindow){printWindow.location.href=url;}else{const named=((r.headers.get('Content-Disposition')||'').match(/filename="([^"]+)"/)||[])[1];a.href=url;a.download=named||((type==='letter'?'coverletter':'resume')+'.'+(b.dataset.format==='print'?'html':b.dataset.format));a.click();}setTimeout(()=>URL.revokeObjectURL(url),30000);s.textContent='Document downloaded. Review the complete file before applying.';}catch(e){status(s,e);}finally{b.disabled=false;}});dialog.showModal();};
// The table of contents reflects rendered headings and never rewrites authored content.
function toc(){const article=document.querySelector('#articleContent');if(!article)return;let t=document.getElementById('articleTOC');if(!t){t=document.createElement('nav');t.id='articleTOC';t.className='blog-toc';t.setAttribute('aria-label','On this page');article.before(t);}const headings=[...article.querySelectorAll('h2,h3')];t.innerHTML='<b>On this page</b>'+headings.map((h,i)=>{h.id||='article-heading-'+i;return '<a href="#'+E(h.id)+'">'+E(h.textContent)+'</a>';}).join('');t.hidden=headings.length===0;}
toc();document.querySelectorAll('.toc').forEach(t=>{if(document.querySelector('#articleTOC'))t.hidden=true;});const article=document.querySelector('#articleContent');if(article)new MutationObserver(toc).observe(article,{childList:true,subtree:true});
if(document.querySelector('.side-nav')&&!document.querySelector('[data-workspace-view=earn]')){const accountNav=document.querySelector('[data-workspace-view=account]');if(accountNav){const a=document.createElement('a');a.href='/workspace?view=earn';a.textContent='Refer & earn';a.className='nav-item';accountNav.after(a);}}
function writingHelp(){
  if(document.body.dataset.page!=='workspace'||document.getElementById('writingHelpButton'))return;
  const button=document.createElement('button');
  button.id='writingHelpButton';
  button.type='button';
  button.className='button secondary small';
  button.textContent='1-Click Optimize';
  document.querySelector('.top-actions')?.prepend(button);
  const jobText=()=>{try{const j=JSON.parse(localStorage.getItem('cvtara-target-job')||'{}');if((j.jd||'').trim())return j.jd.trim();}catch{}return (document.getElementById('tpJD')?.value||document.getElementById('matchJob')?.value||'').trim();};
  const resumePlain=d=>{const p=d.personal||{},t=[];t.push([p.name,p.role].filter(Boolean).join('\n'));if(d.summary)t.push(d.summary);(d.experience||[]).forEach(x=>t.push([x.role,x.company,x.bullets].filter(Boolean).join('\n')));if(d.skills)t.push(d.skills);return t.filter(Boolean).join('\n');};
  const letterPlain=d=>[d.opening,d.evidence,d.motivation,d.closing].filter(Boolean).join('\n\n');
  const isLetter=()=>document.getElementById('view-letter')?.classList.contains('active');
  button.onclick=async()=>{
    if(button.disabled)return;
    const letter=isLetter();
    const studio=letter?window.CVTaraLetterStudio:window.CVTaraStudio;
    if(!studio?.getDraft){alert('Open the resume builder or cover letter first.');return;}
    const draft=studio.getDraft();
    const text=(letter?letterPlain(draft):resumePlain(draft)).trim();
    if(text.length<30){if(!letter)document.querySelector('[data-workspace-view="builder"]')?.click();alert('Add your experience in the draft first, then click 1-Click Optimize.');return;}
    let dialog=document.getElementById('optimizeDialog');
    if(!dialog){dialog=document.createElement('dialog');dialog.id='optimizeDialog';dialog.className='export-dialog fb-opt';document.body.append(dialog);}
    dialog.innerHTML='<h2>1-Click Optimize</h2><p role="status">Optimizing your current draft…</p><div class="fb-opt-actions"><button type="button" class="button secondary" data-close>Close</button></div>';
    dialog.querySelector('[data-close]').onclick=()=>dialog.close();
    dialog.showModal();
    button.disabled=true;
    const say=msg=>{const s=dialog.querySelector('[role=status]');if(s)s.textContent=msg;};
    const payload={task:'rewrite',language:'English',text:text.slice(0,12000),context:jobText().slice(0,8000),consent:true,format:letter?'letter':'resume',draft:letter?{opening:draft.opening||'',evidence:draft.evidence||'',motivation:draft.motivation||'',closing:draft.closing||''}:{summary:draft.summary||'',skills:draft.skills||'',experience:(draft.experience||[]).map(x=>({id:x.id||'',bullets:x.bullets||''}))}};
    const previous=structuredClone(draft);
    try{
      let r={};
      try{r=await api('/api/ai/suggest',{method:'POST',body:payload});}catch(err){
        if(err&&err.status===402)throw err;
        r=window.CVTaraOptimizeLocal?window.CVTaraOptimizeLocal(payload):{};
      }
      const patch=r.patch&&Object.keys(r.patch).length?r.patch:null;
      if(!patch&&!(r.suggestion||'').trim())throw Error(r.error||'No suggestion was returned.');
      if(!letter)document.querySelector('[data-workspace-view="builder"]')?.click();
      else document.querySelector('[data-workspace-view="letter"]')?.click();
      if(patch&&studio.applyOptimize)studio.applyOptimize(patch);
      dialog.innerHTML='<h2>1-Click Optimize</h2><p role="status">Applied to this draft. Review every line. Nothing new was invented — wording and punctuation only.</p>'+(r.suggestion&&!patch?'<textarea readonly rows="8">'+E(r.suggestion)+'</textarea>':'')+'<div class="fb-opt-actions"><button type="button" class="button primary" data-close>Keep changes</button><button type="button" class="button secondary" data-undo>Undo</button></div>';
      dialog.querySelector('[data-close]').onclick=()=>dialog.close();
      dialog.querySelector('[data-undo]').onclick=()=>{if(studio.applyOptimize)studio.applyOptimize(letter?{opening:previous.opening,evidence:previous.evidence,motivation:previous.motivation,closing:previous.closing}:{summary:previous.summary,skills:previous.skills,experience:(previous.experience||[]).map(x=>({id:x.id,bullets:x.bullets||''}))});say('Restored your previous draft.');dialog.close();};
    }catch(error){say(error.message||'Optimize could not run. Add more of your own experience and retry.');dialog.insertAdjacentHTML('beforeend','<div class="fb-opt-actions"><button type="button" class="button secondary" data-close>Close</button></div>');dialog.querySelector('[data-close]').onclick=()=>dialog.close();}
    finally{button.disabled=false;}
  };
}
window.CVTaraOptimizeLocal=function(payload){
  const polish=s=>String(s||'').split(/\n+/).map(line=>{let t=line.replace(/^[\s•*-]+/,'').replace(/\s+/g,' ').trim();if(!t)return '';t=t.replace(/^(responsible for|tasked with)\s+/i,'Owned ').replace(/^(helped(?: to)?|assisted(?: with)?)\s+/i,'Supported ').replace(/^(worked on|worked with)\s+/i,'Delivered ').replace(/^(did|made)\s+/i,'Completed ');if(t.length>20&&!/[.!?]$/.test(t))t+='.';return t.charAt(0).toUpperCase()+t.slice(1);}).filter(Boolean).join('\n');
  const d=payload.draft||{};
  if(payload.format==='letter')return {patch:{opening:polish(d.opening),evidence:polish(d.evidence),motivation:polish(d.motivation),closing:polish(d.closing)},suggestion:''};
  return {patch:{summary:polish(d.summary),skills:d.skills||'',experience:(d.experience||[]).map(x=>({id:x.id||'',bullets:polish(x.bullets)}))},suggestion:''};
};
window.addEventListener('cvtara-owner-content',writingHelp);writingHelp();
for(const [key,count] of [['templates',window.CVTARA_DATA?.templates.length],['letters',window.CVTARA_DATA?.coverLetters.length]]){const n=document.querySelector('[data-admin-view='+key+'] .nav-count');if(n)n.textContent=count;}
const nav=document.querySelector('.sidebar,.side-nav,.admin-sidebar');if(nav){const f=document.createElement('form');f.method='post';f.action='/logout';f.innerHTML='<input type="hidden" name="_token" value="'+E(document.querySelector('meta[name=csrf-token]')?.content)+'"><button class="button secondary">Sign out</button>';nav.append(f);}
})();

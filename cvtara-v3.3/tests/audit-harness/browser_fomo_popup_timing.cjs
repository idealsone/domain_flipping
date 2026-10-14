const { chromium } = require(process.argv[2]); const S=process.argv[3]; const B='http://127.0.0.1:8000';
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox']}); const c=await b.newContext({viewport:{width:1280,height:800}}); const p=await c.newPage();
 await p.goto(B+'/'); const t0=Date.now(); const seen=[];
 const vis=()=>p.evaluate(()=>{const e=document.querySelector('.fomo');return e&&e.classList.contains('show')?e.innerText.replace(/\n+/g,' | ').slice(0,110):null});
 console.log('at 5s visible?',await (async()=>{await p.waitForTimeout(5000);return vis()})());
 while(Date.now()-t0<75000){const v=await vis(); if(v&&!seen.includes(v)){seen.push(v);console.log(`[${Math.round((Date.now()-t0)/1000)}s]`,v); if(seen.length===1) await p.screenshot({path:S+'/n_fomo.png'});} await p.waitForTimeout(700);}
 await b.close();
})();

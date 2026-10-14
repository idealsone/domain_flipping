const { chromium } = require(process.argv[2]); const fs=require('fs');
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox']}); const ctx=await b.newContext(); const paths=fs.readFileSync(process.argv[3],'utf8').trim().split('\n'); let bad=0;
 for(const u of paths){const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,90)));p.on('response',r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().slice(24))});
  await p.goto('http://127.0.0.1:8000'+u,{waitUntil:'load'}).catch(e=>errs.push('nav '+e.message.slice(0,50)));await p.waitForTimeout(250);
  const i=await p.evaluate(()=>({tool:!!document.querySelector('#tool')&&document.querySelector('#tool').children.length,content:document.querySelector('#articleContent')?.innerText.length,desc:!!document.querySelector('#articleDescription')}));
  if(errs.length||!i.content){bad++;console.log(u,JSON.stringify(i),errs.join(' | '));} await p.close();}
 console.log('pages',paths.length,'with issues',bad); await b.close();})();

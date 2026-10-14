const {chromium}=require(require('child_process').execSync('npm root -g').toString().trim()+'/playwright');
const B=process.argv[2]||(process.env.BASE||'http://127.0.0.1:8000');
const scan=p=>p.evaluate(()=>{
  const parse=c=>{const m=c.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/);return m?[+m[1],+m[2],+m[3],m[4]===undefined?1:+m[4]]:null};
  const L=c=>{const f=v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)};return .2126*f(c[0])+.7152*f(c[1])+.0722*f(c[2])};
  const bgOf=e=>{let x=e;const stack=[];while(x&&x.nodeType===1){const cs=getComputedStyle(x);if(cs.backgroundImage&&cs.backgroundImage!=='none')return null;const c=parse(cs.backgroundColor);if(c&&c[3]>=.95)return c;if(c&&c[3]>0)stack.push(c);x=x.parentElement}return [255,255,255,1]};
  const bad={};
  document.querySelectorAll('body *').forEach(e=>{if(!e.offsetParent&&getComputedStyle(e).position!=='fixed')return;const own=[...e.childNodes].filter(n=>n.nodeType===3&&n.textContent.trim().length>1);if(!own.length)return;const r=e.getBoundingClientRect();if(r.width<4||r.height<4||r.bottom<0||r.top>innerHeight*2)return;const cs=getComputedStyle(e);if(cs.visibility==='hidden'||+cs.opacity<.3)return;const fg=parse(cs.color),bg=bgOf(e);if(!fg||!bg)return;const l1=L(fg),l2=L(bg),ratio=(Math.max(l1,l2)+.05)/(Math.min(l1,l2)+.05);if(ratio<3){const key=e.tagName.toLowerCase()+(e.id?'#'+e.id:'')+(typeof e.className==='string'&&e.className?'.'+e.className.trim().split(/\s+/).slice(0,2).join('.'):'');(bad[key]=bad[key]||{n:0,ratio:ratio.toFixed(2),fg:cs.color,bg:`rgb(${bg.slice(0,3)})`,t:own[0].textContent.trim().slice(0,30)}).n++}});
  return bad});
(async()=>{const b=await chromium.launch();
for(const mode of ['light','dark']){
 for(const [name,go,kind] of [['home','/'],['customer','/preview/go/customer','ws'],['admin','/preview/go/admin','ad'],['templates','/templates.html'],['guide','/guides/resume/action-verbs-list/'],['checkout','/checkout.html'],['login','/login']]){
  const ctx=await b.newContext({viewport:{width:1366,height:900},colorScheme:'light'});const p=await ctx.newPage();
  await p.addInitScript(m=>{try{localStorage.setItem('cvtara-theme',m)}catch(e){}},mode);
  await p.goto(B+go);await p.waitForTimeout(3500);
  const views=kind==='ws'?['resumes','billing','match','letter','tracker','account']:kind==='ad'?['people','orders','resumelib','errors','bloghub','mailer','health','referral','pricing','settings']:[null];
  for(const v of views){ if(v){await p.evaluate(([v,kind])=>{const s=kind==='ws'?`[data-workspace-view="${v}"]`:`.side-nav [data-admin-view="${v}"]`;const e=document.querySelector(s);e&&e.click()},[v,kind]);await p.waitForTimeout(1100)}
   const bad=await scan(p);const items=Object.entries(bad).slice(0,6);
   if(items.length)console.log(`[${mode}] ${name}${v?'/'+v:''}:`,items.map(([k,x])=>`${k} ${x.ratio}:1 "${x.t}" ${x.fg} on ${x.bg} ×${x.n}`).join(' || '));}
  await ctx.close()}}
console.log('scan done');await b.close()})();

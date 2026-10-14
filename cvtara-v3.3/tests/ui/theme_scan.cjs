const {chromium}=require(require('child_process').execSync('npm root -g').toString().trim()+'/playwright');
const B=process.argv[2]||(process.env.BASE||'http://127.0.0.1:8000');
const lum=c=>{const m=c.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/);if(!m)return null;const a=m[4]===undefined?1:+m[4];if(a<.9)return null;return (0.2126*m[1]+0.7152*m[2]+0.0722*m[3])/255};
async function scan(p,label){
  const r=await p.evaluate(()=>{const out={};const f=e=>getComputedStyle(e).backgroundColor;out.html=f(document.documentElement);out.body=f(document.body);
    const bad={};document.querySelectorAll('body *').forEach(e=>{const r=e.getBoundingClientRect();if(r.width*r.height<9000||r.bottom<0||r.top>innerHeight*1.5)return;const cs=getComputedStyle(e);if(cs.visibility==='hidden'||cs.display==='none'||cs.opacity==='0')return;const key=e.tagName.toLowerCase()+(e.id?'#'+e.id:'')+(e.className&&typeof e.className==='string'?'.'+e.className.trim().split(/\s+/).slice(0,2).join('.'):'');(bad[key]=bad[key]||{bg:cs.backgroundColor,n:0}).n++});
    out.els=bad;out.dark=document.body.classList.contains('dark');out.htmlDark=document.documentElement.classList.contains('dark');out.attr=document.documentElement.dataset.theme||'';return out});
  const light=Object.entries(r.els).filter(([k,v])=>{const l=lum(v.bg);return l!==null&&l>.8}).slice(0,12);
  console.log(label,'| bodyDark',r.dark,'htmlDark',r.htmlDark,'attr',r.attr,'| html',r.html,'body',r.body,'| light patches:',light.map(([k,v])=>k+'×'+v.n).join(', ')||'none');
}
(async()=>{const b=await chromium.launch();
for(const [name,go,view] of [['home','/',null],['customer','/preview/go/customer','workspace'],['admin','/preview/go/admin','admin'],['templates','/templates.html',null],['blog','/blog.html',null],['guide','/guides/resume/action-verbs-list/',null],['checkout','/checkout.html',null]]){
  const ctx=await b.newContext({viewport:{width:1366,height:900}});const p=await ctx.newPage();await p.goto(B+go);await p.waitForTimeout(3500);
  await scan(p,name+' LIGHT');
  const t=p.locator('button[data-theme]:visible').first();const n=await t.count();if(!n){console.log(name,'NO toggle button');await ctx.close();continue}
  await t.click();await p.waitForTimeout(600);await scan(p,name+' DARK ');
  if(name==='admin'){for(const v of ['people','orders','resumelib','errors','bloghub','mailer','health']){await p.evaluate(v=>{const b=document.querySelector(`.side-nav [data-admin-view="${v}"]`)||document.querySelector(`[data-admin-view="${v}"]`);b&&b.click()},v);await p.waitForTimeout(1200);await scan(p,'  admin/'+v+' DARK')}}
  if(name==='customer'){for(const v of ['resumes','billing','builder','match']){await p.evaluate(v=>{const b=document.querySelector(`[data-workspace-view="${v}"]`);b&&b.click()},v);await p.waitForTimeout(900);await scan(p,'  ws/'+v+' DARK')}}
  await p.reload();await p.waitForTimeout(2500);await scan(p,name+' RELOAD');
  await ctx.close()}
await b.close()})();

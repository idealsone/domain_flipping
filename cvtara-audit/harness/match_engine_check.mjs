import fs from 'fs';
globalThis.window=globalThis;
eval(fs.readFileSync('run/public_html/match-engine.js','utf8'));
const resume=`Asha Rao\nasha@x.com +91 98765 43210\nSUMMARY\nRecruiter with 6 years of experience.\nEXPERIENCE\n• Screened 300 candidates per month and coordinated interviews with hiring managers, reducing time-to-hire by 20%.\n• Built dashboards in Power BI and Excel for weekly reports.\n• No experience with Python.\nSKILLS\nJavaScript, SQL, React.js, Node.js, Docker\nEDUCATION\nMBA 2016-2018`;
const jd=`We are a company founded 25 years ago.\nResponsibilities:\n- Candidate screening and interview coordination is required.\n- Preferred: Python and Docker.\n- Must have 5+ years of experience in recruitment.\n- Bachelor degree required.\n- Strong stakeholder communication.`;
const r=CVTaraMatch.analyse(resume,jd);
console.log(r.counts, r.requirements.map(x=>[x.name,x.priority,x.status]));
const t=Date.now(); CVTaraMatch.analyse(('Managed a team and built python apps. '.repeat(30)+'\n').repeat(3000).slice(0,119000),('Python required experience. '.repeat(20)+'\n').repeat(1400).slice(0,39000)); console.log('stress ms',Date.now()-t);
console.log('years logic:', r.requirements.filter(x=>/Experience duration/.test(x.name)).map(x=>[x.name,x.evidence.slice(0,60)]));
console.log('JS false-positive for JD "Node.js":', CVTaraMatch.analyse('Skills\nNode.js','We need Node.js developer who built APIs.\nMust know JavaScript').requirements.map(x=>[x.name,x.status]));
try{CVTaraMatch.analyse('a'.repeat(130000),'x')}catch(e){console.log('limit err:',e.message)}

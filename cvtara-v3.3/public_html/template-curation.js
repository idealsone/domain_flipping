(()=>{'use strict';const D=window.CVTARA_DATA;if(!D)return;
// Public library: the ten original highlights plus one design for each role that a recruiter in India commonly hires for.
// Narrow side-column layouts are switched to a single-column layout so every public design stays easy for applicant tracking systems to read.
const base=D.templates.filter(t=>t.id<=50);
const highlights=[0,11,22,33,44,45,46,17,28,39];
const roles=[1,2,4,5,6,8,9,10,12,13,14,15,16,20,23,24,25,27,29,30,32,34,35,36,37,38,40,41,42,43,47,48,49];
const singleColumn={1:'modern',6:'executive',16:'clarity',36:'compact',41:'timeline'};
const selected=new Set([...highlights,...roles].map(i=>base[i]?.slug));
for(const [i,layout] of Object.entries(singleColumn)){if(base[i])base[i].layout=layout}
const sidebar=D.templates.find(t=>t.layout==='career-sidebar-blue');
for(const t of D.templates){if(t.id<=50&&!selected.has(t.slug)||t.layout?.startsWith('career-sidebar-')&&t!==sidebar){t.curatedHidden=true;t.publicPublished=false;}}
window.CVTaraCuratedTemplates={count:()=>D.templates.filter(t=>!t.curatedHidden&&t.publicPublished!==false).length};})();

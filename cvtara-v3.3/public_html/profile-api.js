(() => {
 'use strict';
 async function request(path,options={}){const headers={'Accept':'application/json',...(options.method&&options.method!=='GET'?{'Content-Type':'application/json','X-CVTara-Request':'1','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''}:{}),...options.headers};const res=await fetch(path,{credentials:'same-origin',...options,headers,body:options.body&&typeof options.body!=='string'?JSON.stringify(options.body):options.body});let data;try{data=await res.json();}catch{throw new Error('The service is unavailable. Keep your draft and retry.');}if(!res.ok||!data.ok){const error=new Error(data.error||'Unable to complete this action.');error.status=res.status;error.signIn=data.signIn;throw error;}return data;}
 const shared={};
 // Several scripts ask for the same public content on one page; fetch it once.
 const cached=(path,options)=>{if(!/^\/api\/(public-content|public-config|business-public)$/.test(path)||(options&&options.method&&options.method!=='GET'))return request(path,options);const hit=shared[path];if(hit&&Date.now()-hit.at<15000)return hit.p.then(v=>JSON.parse(JSON.stringify(v)));const p=request(path,options);shared[path]={at:Date.now(),p};p.catch(()=>{delete shared[path]});return p.then(v=>JSON.parse(JSON.stringify(v)));};
 window.CVTaraAPI={request:cached};
})();

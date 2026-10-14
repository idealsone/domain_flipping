importScripts('/match-engine.js');
self.onmessage=e=>{try{self.postMessage({ok:true,result:self.CVTaraMatch.analyse(e.data.resume,e.data.job)});}catch(error){self.postMessage({ok:false,error:error.message});}};

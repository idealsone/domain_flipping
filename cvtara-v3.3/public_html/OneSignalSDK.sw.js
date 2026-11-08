// Push notifications were retired. Browsers that subscribed earlier still run the old worker,
// so this replacement removes the subscription and unregisters itself on the next update check.
self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',e=>e.waitUntil((async()=>{try{const s=await self.registration.pushManager.getSubscription();if(s)await s.unsubscribe();}catch(_){}await self.registration.unregister();})()));

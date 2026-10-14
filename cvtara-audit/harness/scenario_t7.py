import sys,json,sqlite3; sys.path.insert(0,sys.argv[1]); from client import *
bo=C(); bo.login('bob@test.local','TestPassw0rd!!x'); bo.refresh('/workspace.html')
print('reward send (not configured)',bo.api('POST','referral-rewards/send',{'phone':'9876543210'}))
print('reward claim',bo.api('POST','referral-rewards/claim',{'milestone':5}))
o=C(); o.login('owner@test.local','OwnerPassw0rd!!'); o.refresh('/admin.html')
print('admin payouts msg',o.api('POST','admin/providers',{'service':'payouts','values':{'enabled':'true','mode':'sandbox','maxTransferRupees':'5000','acceptanceConfirmed':'false'}}))
for p in ['overview','dashboard','users','letter-summary','reports?days=30','reviews','email/jobs','seo','page-audit','settings','plans','purchases','referrals','setup/status','content/blog','content/features','content/emails','content/library','content/legal','content/seo']:
    s,j=o.api('GET','admin/'+p); print(p,s,sorted(j.keys())[:14] if isinstance(j,dict) else j)
s,j=o.api('GET','admin/dashboard?days=30'); print('dashboard keys',{k:(type(v).__name__, (len(v) if hasattr(v,'__len__') else v)) for k,v in j.items()})
print('setup status',json.dumps(o.api('GET','admin/setup/status')[1])[:900])

import sys,json,sqlite3,subprocess,re,time; sys.path.insert(0,sys.argv[1]); from client import *
DB=sys.argv[1]+'/run/database/database.sqlite'
def q(sql,*a):
    c=sqlite3.connect(DB); r=c.execute(sql,a).fetchall(); c.commit(); c.close(); return r
def cron(): return subprocess.run(['php','artisan','cvtara:cron'],cwd=sys.argv[1]+'/run',capture_output=True,text=True).stdout.strip()
def secure(cl,pw):
    cl.api('POST','security/send-code',{}); body=q("select body from mail_outbox where trigger='security_verification' order by created_at desc limit 1")[0][0]; code=re.search(r'code is (\d{6})',body).group(1); cron(); return cl.api('POST','security/verify-code',{'code':code,'password':pw})[0]
q("delete from cache")
o=C(); o.login('owner@test.local','OwnerPassw0rd!!'); o.refresh('/admin.html'); print('owner secure',secure(o,'OwnerPassw0rd!!'))
print('enable verification',o.api('POST','admin/providers',{'service':'verification','values':{'enabled':'true','twilioAccountSid':'AC'+'a'*32,'twilioAuthToken':'tok','twilioVerifyServiceSid':'VA'+'b'*32}}))
s,j=o.api('GET','admin/business-controls'); d=j['data']; d['growth'].update(downloadThreshold=1,premiumThreshold=2); print('thresholds',o.api('POST','admin/business-controls',d)[0])
al=C(); al.login('alice@test.local','TestPassw0rd!!x'); al.refresh('/workspace.html')
bo=C(); bo.login('bob@test.local','NewPassw0rd!!zz'); bo.refresh('/workspace.html')
print('bob secure',secure(bo,'NewPassw0rd!!zz'))
print('bob send otp',bo.api('POST','referral-rewards/send',{'phone':'9811111111'}),'wrong code',bo.api('POST','referral-rewards/check',{'code':'000000'})[0],'right',bo.api('POST','referral-rewards/check',{'code':'123456'}))
print('bob status',bo.api('GET','referral-rewards')[1])
print('same phone other acct',al.api('POST','referral-rewards/send',{'phone':'9811111111'})[0])
print('alice secure',secure(al,'TestPassw0rd!!x'))
print('alice otp',al.api('POST','referral-rewards/send',{'phone':'9822222222'})[0],al.api('POST','referral-rewards/check',{'code':'123456'}))
print('alice status',al.api('GET','referral-rewards')[1])
print('claim 5',al.api('POST','referral-rewards/claim',{'milestone':5}),'again',al.api('POST','referral-rewards/claim',{'milestone':5})[0])
print('alice referrals view',[r['status'] for r in al.api('GET','referrals')[1]['referredUsers']])
# clean export via reward on free account
rid=al.api('GET','resumes')[1]['resumes'][0]['id']
for i in range(2):
    s,h,b=al.raw('POST',f'/export/resume/{rid}/pdf',json_body={'clean':True},headers={'X-CSRF-TOKEN':al.csrf,'Accept':'application/json'}); print('clean export',i,s,b[:5])
print('claim 10 (needs 2 qualified)',al.api('POST','referral-rewards/claim',{'milestone':10})[0])
print('device held logic rows',q("select owner_id,status,device_hash is not null from referral_identities"))

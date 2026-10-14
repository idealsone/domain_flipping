import sys,json,sqlite3,subprocess,re; sys.path.insert(0,sys.argv[1]); from client import *
DB=None
def q(sql,*a):
    c=sqlite3.connect(DB); r=c.execute(sql,a).fetchall(); c.commit(); c.close(); return r
def cron(): return subprocess.run(['php','artisan','cvtara:cron'],cwd=sys.argv[1]+'/run',capture_output=True,text=True).stdout.strip()
def secure(cl,pw):
    print('  send-code',cl.api('POST','security/send-code',{})); 
    body=q("select body from mail_outbox where trigger='security_verification' order by created_at desc limit 1")[0][0]; code=re.search(r'code is (\d{6})',body).group(1)
    print('  cron',cron()); print('  verify',cl.api('POST','security/verify-code',{'code':code,'password':pw})); print('  status',cl.api('GET','security/status')[1])
al=C(); al.login('alice@test.local','TestPassw0rd!!x'); al.refresh('/workspace.html')
o=C(); o.login('owner@test.local','OwnerPassw0rd!!'); o.refresh('/admin.html')
print('payout w/o recent',al.api('POST','referrals/payout-profile',{'name':'Alice A','phone':'9876543210','address':'12 Some Street, City','country':'IN','method':'upi','upi':'alice@okbank','consent':True}))
print('secure alice'); secure(al,'TestPassw0rd!!x')
print('payout profile',al.api('POST','referrals/payout-profile',{'name':'Alice A','phone':'9876543210','address':'12 Some Street, City','country':'IN','method':'upi','upi':'alice@okbank','consent':True}))
print('secure owner'); secure(o,'OwnerPassw0rd!!')
aid=q("select id from users where email='alice@test.local'")[0][0]
print('approve profile (missing checks)',o.api('POST',f'admin/referrals/profiles/{aid}',{'status':'approved','note':'looks fine ok'})[0])
print('approve profile',o.api('POST',f'admin/referrals/profiles/{aid}',{'status':'approved','note':'looks fine ok','identityChecked':True,'destinationChecked':True,'riskChecked':True,'taxChecked':True}))
# fabricate matured commissions (₹1500)
bid=q("select id from users where email='bob@test.local'")[0][0]
q("insert into referral_commissions(referrer_id,buyer_id,purchase_id,amount_paise,reversed_paise,rate_bps,available_at,created_at,updated_at) values(?,?,?,?,?,?,datetime('now','-1 day'),datetime('now'),datetime('now'))",aid,bid,'fake-purchase-1',150000,0,2000)
print('balance',al.api('GET','referrals')[1]['balance'])
print('withdraw before 48h',al.api('POST','referrals/withdraw',{'amountPaise':100000}))
q("update payout_profiles set eligible_at=datetime('now','-1 hour')")
print('withdraw (email verified now)',al.api('POST','referrals/withdraw',{'amountPaise':100000}))
print('withdraw 2nd',al.api('POST','referrals/withdraw',{'amountPaise':100000})[1].get('error'))
wid=q("select id from withdrawals")[0][0]
print('admin list',o.api('GET','admin/referrals')[0])
print('mark paid w/o review',o.api('POST',f'admin/referrals/withdrawals/{wid}',{'status':'paid','reference':'UTR123456'})[1].get('error'))
print('processing w/o review',o.api('POST',f'admin/referrals/withdrawals/{wid}',{'status':'processing'})[1].get('error'))
print('review',o.api('POST',f'admin/referrals/withdrawals/{wid}/review',{'identityChecked':True,'destinationChecked':True,'riskChecked':True,'taxChecked':True,'note':'all verified fine'}))
print('processing',o.api('POST',f'admin/referrals/withdrawals/{wid}',{'status':'processing'}))
print('paid',o.api('POST',f'admin/referrals/withdrawals/{wid}',{'status':'paid','reference':'UTR123456'}))
print('balance',al.api('GET','referrals')[1]['balance'])
print('dest view',o.api('GET',f'admin/referrals/withdrawals/{wid}')[1])
print('audit events',q("select event,count(*) from activity_events group by event order by 2 desc limit 30"))

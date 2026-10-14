import sys,json,re,subprocess,hmac,hashlib,time; sys.path.insert(0,sys.argv[1]); from client import *; from dbq import q
S=sys.argv[1]; ST=S+'/fake_state.json'; KS='secret_key_123'; WH='whsec_1'; n=[0]
def cron(): return subprocess.run(['php','artisan','cvtara:cron'],cwd=S+'/v33/app',capture_output=True,text=True).stdout.strip()
def secure(cl,pw):
    cl.api('POST','security/send-code',{}); b=q("select body from mail_outbox where trigger='security_verification' order by created_at desc limit 1")[0][0]; code=re.search(r'code is (\d{6})',b).group(1); cron(); return cl.api('POST','security/verify-code',{'code':code,'password':pw})[0]
def check(label,cond,extra=''): print(('PASS ' if cond else 'FAIL ')+label,extra if not cond else ''); return cond
def buy(cl,plan,coupon='',pid=None):
    s,j=cl.api('POST','checkout/start',{'plan':plan,'coupon':coupon})
    if s!=200: return s,j
    n[0]+=1; pid=pid or f'pay_N{n[0]}_{int(time.time()*1000)%100000}'; oid=j['orderId']
    st=json.load(open(ST)) if os.path.exists(ST) else {}; st.setdefault('razorpay_payments',{})[pid]={'id':pid,'order_id':oid,'amount':j['amount'],'currency':'INR','status':'captured'}; open(ST,'w').write(json.dumps(st))
    sig=hmac.new(KS.encode(),f'{oid}|{pid}'.encode(),hashlib.sha256).hexdigest(); s,v=cl.api('POST','checkout/verify',{'razorpay_order_id':oid,'razorpay_payment_id':pid,'razorpay_signature':sig}); return s,{'pid':pid,'oid':oid,**v}
import os
if os.path.exists(ST): os.remove(ST)
def hook(ev,eid):
    raw=json.dumps(ev).encode(); sig=hmac.new(WH.encode(),raw,hashlib.sha256).hexdigest(); return C().raw('POST','/webhooks/razorpay',data=raw,headers={'X-Razorpay-Signature':sig,'X-Razorpay-Event-Id':eid})[0]
o=C(); o.login('owner@test.local','OwnerPassw0rd!!'); o.refresh('/admin.html')
s,j=o.api('GET','admin/content/integrations'); d=j['data']; d.update(payments=True,email=True); o.api('POST','admin/content/integrations',{'data':d,'revision':j['revision']})
o.api('POST','admin/providers',{'service':'payments','values':{'provider':'razorpay','mode':'live','razorpayKeyId':'rzp_live_abc','razorpayKeySecret':KS,'razorpayWebhookSecret':WH}})
o.api('POST','admin/providers',{'service':'email','values':{'provider':'resend','resendApiKey':'re_x','fromEmail':'noreply@test.local','fromName':'CVTara'}})
check('cashfree checkout rejected',o.api('POST','admin/providers',{'service':'payments','values':{'provider':'cashfree'}})[0]==422)
al=C(); al.register('alice@test.local'); al.refresh('/workspace.html'); code=al.api('GET','referrals')[1]['code']
bo=C(); bo.register('bob@test.local',ref=code); bo.refresh('/workspace.html')
s,j=bo.api('POST','checkout/quote',{'plan':'addon-10'}); check('addon needs plan (msg shown)',s==422 and 'active plan' in j['error'],j)
s,j=bo.api('POST','checkout/quote',{'plan':'single'}); check('legacy plan key rejected',s==422)
s,r=buy(bo,'fresher-basic'); check('buy fresher-basic',s==200,r)
a=bo.api('GET','allowance')[1]; check('caps basic',a['plan']=='fresher-basic' and a['caps']['resumes']==11 and a['caps']['letters']==10 and a['caps']['atsScans']==10 and 58<=a['daysLeft']<=60,a)
check('no commission yet (referrer lacks Pro)',q("select count(*) from referral_commissions")[0][0]==0)
# ATS scans
res=[bo.api('POST','features/use',{'action':'match'}) for _ in range(11)]
check('10 scans ok, 11th blocked',[x[0] for x in res]==[200]*10+[422] and 'ATS scan' in res[-1][1]['error'],res[-1])
check('scan usage',bo.api('GET','allowance')[1]['used']['atsScans']==10)
def resume(t): return {'version':'1','title':t,'template':'suite-prism','accent':'#1557d2','font':'Arial','personal':{'name':'Bob'},'summary':'x','order':['summary','experience'],'experience':[],'education':[],'projects':[],'certifications':[]}
s,j=bo.api('POST','resumes',{'data':resume('Master')}); pid1=j['id']
l=bo.api('GET','resumes')[1]; check('first resume is primary',l['resumes'][0]['is_primary'] in (1,True) and l['limit']==11,l)
ids=[]
for i in range(10):
    s,j=bo.api('POST',f'resumes/{pid1}/variant',{'company':f'Co{i}','role':'Recruiter','jobDescription':'Need recruiter with sourcing'}); ids.append(j.get('id')); 
check('10 variants created',all(ids),ids)
s,j=bo.api('POST',f'resumes/{pid1}/variant',{'company':'Over','role':'R'}); check('11th variant blocked',s==422 and 'allowance' in j['error'],j)
lst=bo.api('GET','resumes')[1]['resumes']; check('variants link to primary',sum(1 for x in lst if x['parent_id']==pid1)==10 and sum(1 for x in lst if x['is_primary'])==1)
v=bo.api('GET','resumes/'+ids[0])[1]['resume']; check('variant keeps JD',v['jobDescription'].startswith('Need recruiter') and v['data']['company']=='Co0',v)
check('set primary',bo.api('POST',f'resumes/{ids[1]}/primary',{})[0]==200 and sum(1 for x in bo.api('GET','resumes')[1]['resumes'] if x['is_primary'])==1)
s,r=buy(bo,'addon-10'); check('buy addon-10',s==200,r)
a=bo.api('GET','allowance')[1]; check('addon raises caps',a['caps']['resumes']==21 and a['caps']['letters']==20,a['caps'])
s,j=bo.api('POST',f'resumes/{pid1}/variant',{'company':'Now OK','role':'R'}); check('variant after addon',s==200,j)
# stacking + best plan
until1=bo.api('GET','allowance')[1]['accessUntil']
s,r=buy(bo,'fresher-basic'); a=bo.api('GET','allowance')[1]
d1=time.mktime(time.strptime(until1,'%Y-%m-%d %H:%M:%S')); d2=time.mktime(time.strptime(a['accessUntil'],'%Y-%m-%d %H:%M:%S'))
check('same-plan renewal stacks time (+60d)',abs((d2-d1)/86400-60)<1,a['accessUntil'])
s,r=buy(bo,'fresher-pro'); a=bo.api('GET','allowance')[1]; check('upgrade to pro: unlimited scans',a['plan']=='fresher-pro' and a['caps']['atsScans'] is None and a['caps']['resumes']==31+10,a['caps'])
s,r2=buy(bo,'fresher-basic'); check('cheaper plan does not downgrade',bo.api('GET','allowance')[1]['plan']=='fresher-pro')
for _ in range(15): bo.api('POST','features/use',{'action':'match'})
check('unlimited scans keep working',bo.api('POST','features/use',{'action':'match'})[0]==200)
# partial refund keeps access
pp=r['pid']
check('refund hook',hook({'event':'refund.processed','payload':{'refund':{'entity':{'id':'rf1','payment_id':pp,'amount':5000}}}},'e1')==200)
check('partial refund keeps access',bo.api('GET','allowance')[1]['plan']=='fresher-pro')
check('dispute hook',hook({'event':'payment.dispute.created','payload':{'dispute':{'entity':{'payment_id':pp,'status':'created'}}}},'e2')==200)
check('dispute revokes that purchase (falls back to next plan)',bo.api('GET','allowance')[1]['plan']!='fresher-pro',bo.api('GET','allowance')[1]['plan'])
check('dispute won restores',hook({'event':'payment.dispute.won','payload':{'dispute':{'entity':{'payment_id':pp,'status':'won'}}}},'e3')==200 and bo.api('GET','allowance')[1]['plan']=='fresher-pro')
# referral commission needs referrer on Pro plan
s,r=buy(al,'fresher-pro'); check('alice buys pro',s==200)
s,r=buy(bo,'fresher-standard'); com=q("select count(*),sum(amount_paise) from referral_commissions"); check('commission after referrer is Pro',com[0][0]==1 and com[0][1]==7980,com)
ref=al.api('GET','referrals')[1]; check('commissionEligible flag',ref['commissionEligible'] is True)
# coupons
s,j=o.api('GET','admin/business-controls'); bc=j['data']; bc['coupons']=[{'code':'ONE','type':'percent','value':10,'enabled':True,'plans':['fresher-basic','fresher-standard'],'expiresAt':'','maxUses':1,'perUser':1}]
check('save coupon w/ limits',o.api('POST','admin/business-controls',bc)[0]==200)
cc=C(); cc.register('carol@test.local'); cc.refresh('/workspace.html'); dd=C(); dd.register('dave@test.local'); dd.refresh('/workspace.html')
s,r=buy(cc,'fresher-basic','ONE'); check('coupon first use ok',s==200 and q("select amount_paise from purchases where coupon_code='ONE'")[0][0]==26910,r)
s,j=dd.api('POST','checkout/quote',{'plan':'fresher-basic','coupon':'ONE'}); check('coupon total limit msg',s==422 and 'redemption limit' in j['error'],j)
s,j=cc.api('POST','checkout/quote',{'plan':'fresher-standard','coupon':'ONE'}); check('per-user/total limit msg',s==422 and ('limit' in j['error'] or 'already' in j['error']),j)
# min qualifying amount
q("update purchases set amount_paise=100 where coupon_code='ONE'")
# block user
check('owner secure',secure(o,'OwnerPassw0rd!!')==200)
did=q("select id from users where email='dave@test.local'")[0][0]
check('block needs note',o.api('POST','admin/users/block',{'userId':did,'blocked':True,'note':''})[0]==422)
check('block ok',o.api('POST','admin/users/block',{'userId':did,'blocked':True,'note':'fraud test'})[0]==200)
check('blocked user session killed',dd.api('GET','allowance')[0] in (401,403))
check('blocked user cannot log in',C().login('dave@test.local','TestPassw0rd!!x')[1].get('Location','').endswith('/login'))
check('cannot block owner',o.api('POST','admin/users/block',{'userId':1,'blocked':True,'note':'nope nope'})[0]==422)
s,j=o.api('GET','admin/users?q=dave'); check('admin user search+blocked flag',s==200 and j['usersTotal']==1 and j['users'][0]['blocked'] is True,j.get('usersTotal'))
check('unblock ok',o.api('POST','admin/users/block',{'userId':did,'blocked':False,'note':'cleared'})[0]==200)
s,j=o.api('GET','admin/users?page=1'); check('admin users pagination fields',j['perPage']==100 and j['usersTotal']>=4)
# account export / delete
ex=cc.api('GET','account/export')[1]['export']; check('account export has data',ex['account']['email']=='carol@test.local' and len(ex['purchases'])==1)
check('delete needs password+confirm',cc.api('POST','account/delete',{'password':'wrong','confirm':'DELETE'})[0]==422)
check('delete account',cc.api('POST','account/delete',{'password':'TestPassw0rd!!x','confirm':'DELETE'})[0]==200)
row=q("select name,email,blocked from users where email like 'deleted-%'"); check('user anonymised, purchase kept',len(row)==1 and q("select count(*) from purchases where coupon_code='ONE'")[0][0]==1,row)
check('deleted user cannot log in',C().login('carol@test.local','TestPassw0rd!!x')[1].get('Location','').endswith('/login'))
# English only
s,j=bo.api('POST','profile',{'name':'Bob','language':'hi','experienceLevel':'experienced','profession':'HR','category':'Other','experienceYears':3,'marketingConsent':False}); check('non-English language rejected',s==422)
s,j=bo.api('POST','profile',{'name':'Bob','experienceLevel':'experienced','profession':'HR','category':'Other','experienceYears':3,'marketingConsent':False}); check('profile ok without language',s==200,j)
# admin plan editor
s,j=o.api('GET','admin/plans'); p=j['plans']['fresher-basic']; p.update(price=319,atsScans=0,perks=['A one','B two'],popular=True)
s,j=o.api('POST','admin/plans',{'plans':{'fresher-basic':p}}); check('admin edits plan',s==200 and j['plans']['fresher-basic']['price']==319 and j['plans']['fresher-basic']['atsScans'] is None and j['plans']['fresher-basic']['perks']==['A one','B two'],j)
s,j=o.api('POST','admin/plans',{'plans':{'bogus':p}}); check('unknown plan rejected',s==422)
s,j=C().api('GET','business-public'); check('public plans reflect edit',j['plans']['fresher-basic']['price']==319)

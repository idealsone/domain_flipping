import sys,hmac,hashlib,json,time; sys.path.insert(0,sys.argv[1]); from client import *
ST=sys.argv[1]+'/fake_state.json'
def setstate(d): open(ST,'w').write(json.dumps(d))
o=C('owner'); s,h,_=o.login('owner@test.local','OwnerPassw0rd!!'); print('owner login',s,h.get('Location')); o.refresh('/admin.html')
s,j=o.api('GET','admin/content/integrations'); print('integrations',s,j['data'],j['revision'])
d=j['data']; d.update(payments=True,email=True,analytics=True); s,j2=o.api('POST','admin/content/integrations',{'data':d,'revision':j['revision']}); print('save integrations',s,j2.get('ok'),j2.get('error'))
KS='secret_key_123'; WH='whsec_1'
print('prov pay',o.api('POST','admin/providers',{'service':'payments','values':{'provider':'razorpay','mode':'live','razorpayKeyId':'rzp_live_abc','razorpayKeySecret':KS,'razorpayWebhookSecret':WH}}))
print('prov email',o.api('POST','admin/providers',{'service':'email','values':{'provider':'resend','resendApiKey':'re_x','fromEmail':'noreply@test.local','fromName':'CVTara'}}))
print('GET providers',json.dumps(o.api('GET','admin/providers')[1])[:500])
# users
al=C('alice'); al.login('alice@test.local','TestPassw0rd!!x'); al.refresh('/workspace.html')
bo=C('bob'); bo.login('bob@test.local','TestPassw0rd!!x'); bo.refresh('/workspace.html')
print('quote',bo.api('POST','checkout/quote',{'plan':'monthly','coupon':''}))
s,j=bo.api('POST','checkout/start',{'plan':'monthly'}); print('start',s,j); oid=j['orderId']; pid='pay_TEST1'
print('purchases',bo.api('GET','purchases')[1]['purchases'][0]['status'])
# bad signature
print('bad sig verify',bo.api('POST','checkout/verify',{'razorpay_order_id':oid,'razorpay_payment_id':pid,'razorpay_signature':'0'*64})[0])
sig=hmac.new(KS.encode(),f'{oid}|{pid}'.encode(),hashlib.sha256).hexdigest()
# payment not captured -> should fail
setstate({'razorpay_payments':{pid:{'id':pid,'order_id':oid,'amount':19900,'currency':'INR','status':'authorized'}}})
print('verify authorized',bo.api('POST','checkout/verify',{'razorpay_order_id':oid,'razorpay_payment_id':pid,'razorpay_signature':sig}))
setstate({'razorpay_payments':{pid:{'id':pid,'order_id':oid,'amount':19900,'currency':'INR','status':'captured'}}})
print('verify captured',bo.api('POST','checkout/verify',{'razorpay_order_id':oid,'razorpay_payment_id':pid,'razorpay_signature':sig}))
print('allowance',bo.api('GET','allowance')[1])
print('verify again (idempotent)',bo.api('POST','checkout/verify',{'razorpay_order_id':oid,'razorpay_payment_id':pid,'razorpay_signature':sig}))
s,j=al.api('GET','referrals'); print('alice balance',j['balance'],j['commissions'],j['referredUsers'])
json.dump({'oid':oid,'pid':pid},open(sys.argv[1]+'/ctx.json','w'))

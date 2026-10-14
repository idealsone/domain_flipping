import sys,json,sqlite3,subprocess,re,time,hmac,hashlib,base64; sys.path.insert(0,sys.argv[1]); from client import *
DB=sys.argv[1]+'/run/database/database.sqlite'; ST=sys.argv[1]+'/fake_state.json'
def q(sql,*a):
    c=sqlite3.connect(DB); r=c.execute(sql,a).fetchall(); c.commit(); c.close(); return r
q("delete from cache")
o=C(); o.login('owner@test.local','OwnerPassw0rd!!'); o.refresh('/admin.html')
print('switch to cashfree',o.api('POST','admin/providers',{'service':'payments','values':{'provider':'cashfree','mode':'live','cashfreeClientId':'cfid','cashfreeClientSecret':'cfsecret'}}))
dv=C(); dv.login('dave@test.local','TestPassw0rd!!x'); dv.refresh('/workspace.html')
print('start no phone',dv.api('POST','checkout/start',{'plan':'single'}))
print('start (UI never sends phone anyway)',dv.api('POST','checkout/start',{'plan':'single','phone':'9876500000'}))
oid=q("select provider_order_id,amount_paise from purchases where provider='cashfree'")[0]; print(oid)
st={'cf_orders':{oid[0]:{'order_id':oid[0],'order_currency':'INR','order_amount':oid[1]/100,'order_status':'PAID'}},'cf_payments':{oid[0]:[{'payment_status':'SUCCESS','payment_currency':'INR','payment_amount':oid[1]/100,'cf_payment_id':777}]},'cf_refunds':{oid[0]:[]}}
open(ST,'w').write(json.dumps(st))
raw=json.dumps({'type':'PAYMENT_SUCCESS_WEBHOOK','data':{'order':{'order_id':oid[0]}}}).encode(); ts=str(int(time.time()*1000))
sig=base64.b64encode(hmac.new(b'cfsecret',ts.encode()+raw,hashlib.sha256).digest()).decode()
print('cf webhook bad sig',C().raw('POST','/webhooks/cashfree',data=raw,headers={'x-webhook-timestamp':ts,'x-webhook-signature':'zz'})[0],'good',C().raw('POST','/webhooks/cashfree',data=raw,headers={'x-webhook-timestamp':ts,'x-webhook-signature':sig})[0])
print('dave allowance',dv.api('GET','allowance')[1]['plan'])
# partial refund via cashfree
st['cf_refunds'][oid[0]]=[{'cf_refund_id':'r1','refund_status':'SUCCESS','refund_amount':oid[1]/100/2}]; open(ST,'w').write(json.dumps(st))
print('verify w/ refund',dv.api('POST','checkout/verify',{'provider':'cashfree','orderId':oid[0]})); print(q("select status,refunded_paise from purchases where provider='cashfree'"))
# revert provider to razorpay
print('back to razorpay',o.api('POST','admin/providers',{'service':'payments','values':{'provider':'razorpay'}}))

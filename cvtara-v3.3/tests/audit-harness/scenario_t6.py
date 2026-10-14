import sys,json,sqlite3,hmac,hashlib; sys.path.insert(0,sys.argv[1]); from client import *
ST=sys.argv[1]+'/fake_state.json'; KS='secret_key_123'
DB=sys.argv[1]+'/run/database/database.sqlite'
def q(sql,*a):
    c=sqlite3.connect(DB); r=c.execute(sql,a).fetchall(); c.commit(); c.close(); return r
o=C(); o.login('owner@test.local','OwnerPassw0rd!!'); o.refresh('/admin.html')
# --- coupon
s,j=o.api('GET','admin/business-controls'); d=j['data']; print('bc get',s,list(d.keys()))
d['coupons']=[{'code':'SAVE50','type':'percent','value':50,'enabled':True,'plans':['single','monthly','annual'],'expiresAt':''},{'code':'FREEISH','type':'fixed','value':5000,'enabled':True,'plans':['single'],'expiresAt':''}]
print('bc save',o.api('POST','admin/business-controls',d))
bo=C(); bo.login('bob@test.local','TestPassw0rd!!x'); bo.refresh('/workspace.html')
print('quote SAVE50 annual',bo.api('POST','checkout/quote',{'plan':'annual','coupon':'save50'})[1])
print('quote FREEISH single',bo.api('POST','checkout/quote',{'plan':'single','coupon':'FREEISH'})[1])
print('bad coupon',bo.api('POST','checkout/quote',{'plan':'annual','coupon':'NOPE'})[1].get('error'))
def buy(cl,plan,pid,coupon=''):
    s,j=cl.api('POST','checkout/start',{'plan':plan,'coupon':coupon}); oid=j['orderId']; amt=j['amount']
    open(ST,'w').write(json.dumps({'razorpay_payments':{pid:{'id':pid,'order_id':oid,'amount':amt,'currency':'INR','status':'captured'}}}))
    sig=hmac.new(KS.encode(),f'{oid}|{pid}'.encode(),hashlib.sha256).hexdigest()
    return cl.api('POST','checkout/verify',{'razorpay_order_id':oid,'razorpay_payment_id':pid,'razorpay_signature':sig})
print('buy annual (coupon)',buy(bo,'annual','pay_A1','SAVE50')); import time; time.sleep(1.1)
a=bo.api('GET','allowance')[1]; print('after annual:',a['plan'],a['limits']['savedResumes'],a['limits']['exports'],a['accessUntil'])
print('buy single w/ FREEISH',buy(bo,'single','pay_S1','FREEISH')); 
a=bo.api('GET','allowance')[1]; print('after single (overlap):',a['plan'],a['limits']['savedResumes'],a['limits']['exports'],a['accessUntil'])
print('same coupon reuse by same user allowed?',bo.api('POST','checkout/quote',{'plan':'annual','coupon':'SAVE50'})[0])
print('purchase rows',q("select plan,status,amount_paise,coupon_code,discount_paise,access_until from purchases order by created_at"))

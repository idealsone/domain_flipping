import sys,hmac,hashlib,json; sys.path.insert(0,sys.argv[1]); from client import *
ctx=json.load(open(sys.argv[1]+'/ctx.json')); WH='whsec_1'
def hook(ev,eid):
    raw=json.dumps(ev).encode(); sig=hmac.new(WH.encode(),raw,hashlib.sha256).hexdigest()
    c=C(); return c.raw('POST','/webhooks/razorpay',data=raw,headers={'X-Razorpay-Signature':sig,'X-Razorpay-Event-Id':eid,'Content-Type':'application/json'})[0]
print('bad sig webhook', C().raw('POST','/webhooks/razorpay',data=b'{}',headers={'X-Razorpay-Signature':'x'})[0])
cap={'event':'payment.captured','payload':{'payment':{'entity':{'id':ctx['pid'],'order_id':ctx['oid'],'amount':19900,'currency':'INR','status':'captured'}}}}
print('captured replay',hook(cap,'evt1'),hook(cap,'evt1'),hook(cap,'evt2'))
al=C(); al.login('alice@test.local','TestPassw0rd!!x'); al.refresh('/workspace.html')
bo=C(); bo.login('bob@test.local','TestPassw0rd!!x'); bo.refresh('/workspace.html')
ref={'event':'refund.processed','payload':{'refund':{'entity':{'id':'rfnd_1','payment_id':ctx['pid'],'amount':5000}}}}
print('partial refund',hook(ref,'evt3'),'dup',hook(ref,'evt3'))
j=al.api('GET','referrals')[1]; print('commission after partial',j['commissions'][0]['amount_paise'],j['commissions'][0]['reversed_paise'])
print('bob allowance after PARTIAL refund',bo.api('GET','allowance')[1]['plan'],bo.api('GET','purchases')[1]['purchases'][0]['status'])
ref2={'event':'refund.processed','payload':{'refund':{'entity':{'id':'rfnd_2','payment_id':ctx['pid'],'amount':14900}}}}
print('full refund',hook(ref2,'evt4'))
j=al.api('GET','referrals')[1]; print('commission after full',j['commissions'][0]['amount_paise'],j['commissions'][0]['reversed_paise'],j['balance'])
print('bob allowance after full refund',bo.api('GET','allowance')[1]['plan'])
# replay captured after refund must not re-grant
print('captured after refund',hook({**cap},'evt9'),bo.api('GET','allowance')[1]['plan'])

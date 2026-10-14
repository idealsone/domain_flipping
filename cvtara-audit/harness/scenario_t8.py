import sys,json,sqlite3,subprocess,re,hmac,hashlib,base64,time; sys.path.insert(0,sys.argv[1]); from client import *
DB=sys.argv[1]+'/run/database/database.sqlite'; ST=sys.argv[1]+'/fake_state.json'
def q(sql,*a):
    c=sqlite3.connect(DB); r=c.execute(sql,a).fetchall(); c.commit(); c.close(); return r
def cron(): return subprocess.run(['php','artisan','cvtara:cron'],cwd=sys.argv[1]+'/run',capture_output=True,text=True).stdout.strip()
o=C(); o.login('owner@test.local','OwnerPassw0rd!!'); o.refresh('/admin.html')
# ---- password reset
c=C(); t=c.page_token('/forgot-password'); print('forgot',c.raw('POST','/forgot-password',form={'_token':t,'email':'bob@test.local'})[0], 'unknown',c.raw('POST','/forgot-password',form={'_token':c.page_token('/forgot-password'),'email':'nobody@x.com'})[0])
print('outbox reset:',q("select trigger,status,subject,substr(body,1,120) from mail_outbox where trigger='password_reset'"))
print('cron',cron())
body=q("select body from mail_outbox where trigger='password_reset' order by created_at desc limit 1")[0][0]; m=re.search(r'/reset-password/([A-Za-z0-9]+)\?email=([^\s]+)',body); print('reset url found',bool(m))
if m:
    c2=C(); tk=c2.page_token('/reset-password/'+m.group(1)); s,h,b=c2.raw('POST','/reset-password',form={'_token':tk,'token':m.group(1),'email':'bob@test.local','password':'NewPassw0rd!!zz','password_confirmation':'NewPassw0rd!!zz'}); print('reset',s,h.get('Location'))
    print('old pw login',C().login('bob@test.local','TestPassw0rd!!x')[1].get('Location'),' new pw login',C().login('bob@test.local','NewPassw0rd!!zz')[1].get('Location'))
    print('token reuse',c2.raw('POST','/reset-password',form={'_token':c2.page_token('/reset-password/'+m.group(1)),'token':m.group(1),'email':'bob@test.local','password':'Another0ne!!zzz','password_confirmation':'Another0ne!!zzz'})[1].get('Location'))
# ---- login throttle / lockout
x=C(); res=[x.login('alice@test.local','wrong')[0] for _ in range(7)]; print('login brute',res)
print('right pw while locked',C().login('alice@test.local','TestPassw0rd!!x')[0])
# ---- reviews
al=C(); 

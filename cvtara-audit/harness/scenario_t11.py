import sys,json,sqlite3,subprocess,re,time; sys.path.insert(0,sys.argv[1]); from client import *
DB=sys.argv[1]+'/run/database/database.sqlite'
def q(sql,*a):
    c=sqlite3.connect(DB); r=c.execute(sql,a).fetchall(); c.commit(); c.close(); return r
def cron(): return subprocess.run(['php','artisan','cvtara:cron'],cwd=sys.argv[1]+'/run',capture_output=True,text=True).stdout.strip()
o=C(); o.login('owner@test.local','OwnerPassw0rd!!'); o.refresh('/admin.html')
bo=C(); bo.login('bob@test.local','NewPassw0rd!!zz'); bo.refresh('/workspace.html')
s,j=o.api('GET','admin/content/emails'); data=j['data']; rev=j['revision']
items=data if isinstance(data,list) else list(data.values())
for t in items: t['enabled']=True; t['delayHours']=0
print('type',type(data).__name__, 'save',o.api('POST','admin/content/emails',{'data':data,'revision':rev}))
print([ (t['trigger'],t['subject'][:40]) for t in items])
# abandoned purchase: create a 'created' purchase 2h old
bo.api('POST','checkout/start',{'plan':'monthly'}); q("update purchases set created_at=datetime('now','-2 hours') where status='created'")
# republish blog post newly => emails
s,j=o.api('GET','admin/content/blog'); blog=j['data']; blog['new-test-post']['published']=False; r=o.api('POST','admin/content/blog',{'data':blog,'revision':j['revision']}); 
s,j=o.api('GET','admin/content/blog'); blog=j['data']; blog['new-test-post']['published']=True; print('republish',o.api('POST','admin/content/blog',{'data':blog,'revision':j['revision']})[0])
print('cron',cron()); print('cron2',cron())
for r in q("select trigger,recipient,status,subject,substr(body,1,90) from mail_outbox where trigger!='security_verification' and trigger!='password_reset'"): print(r)
# registration welcome email check
c=C(); c.register('dave@test.local'); print('welcome mail', q("select trigger,subject from mail_outbox where recipient='dave@test.local'"))
print('mail statuses',q("select status,count(*) from mail_outbox group by 1"))

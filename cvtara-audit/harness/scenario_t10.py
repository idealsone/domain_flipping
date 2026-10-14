import sys,json,sqlite3,subprocess,re,hmac,hashlib,base64,time; sys.path.insert(0,sys.argv[1]); from client import *
DB=sys.argv[1]+'/run/database/database.sqlite'; ST=sys.argv[1]+'/fake_state.json'
def q(sql,*a):
    c=sqlite3.connect(DB); r=c.execute(sql,a).fetchall(); c.commit(); c.close(); return r
def cron(): return subprocess.run(['php','artisan','cvtara:cron'],cwd=sys.argv[1]+'/run',capture_output=True,text=True).stdout.strip()
q("delete from cache"); time.sleep(1)
o=C(); print(o.login('owner@test.local','OwnerPassw0rd!!')[0]); o.refresh('/admin.html')
al=C(); al.login('alice@test.local','TestPassw0rd!!x'); al.refresh('/workspace.html')
bo=C(); bo.login('bob@test.local','NewPassw0rd!!zz'); bo.refresh('/workspace.html')
# ---- marketing consent + blog publish emails
print('profile consent',bo.api('POST','profile',{'name':'Bob','phone':'','language':'en','experienceLevel':'experienced','profession':'HR','category':'Other','experienceYears':3,'city':'','marketingConsent':True}))
s,j=o.api('GET','admin/content/emails'); em=j['data']; print('emails templates',[ (t['trigger'],t['enabled'],t['delayHours']) for t in (em if isinstance(em,list) else em.values())])
s,j=o.api('GET','admin/content/blog'); blog=j['data']; rev=j['revision']
slug='new-test-post'; blog[slug]={'title':'New Test Post','description':'d','body':'Hello **world** <script>alert(1)</script>\n\n[x](javascript:alert(1))','category':'Test','published':True,'imageUrl':'','imageAlt':'','faq':'Q?|A.','keywords':'test'}
s,j=o.api('POST','admin/content/blog',{'data':blog,'revision':rev}); print('publish post',s,j.get('error'))
print('blog emails queued',q("select trigger,recipient,status from mail_outbox where trigger='blog_published'"))
c=C(); s,h,b=c.raw('GET','/guides/'+slug+'/'); print('post page',s,b'<script>alert' in b,b'javascript:alert' in b, b'<strong>world</strong>' in b)
# ---- reminders: expiring + abandoned + followup
q("update purchases set access_until=datetime('now','+3 days') where plan='single'")
bo.api('POST','applications',{'company':'Z','role':'R','stage':'applied','followUp':time.strftime('%Y-%m-%d',time.gmtime())})
print('cron',cron()); print('reminder mails',q("select trigger,count(*) from mail_outbox group by trigger"))
# ---- maintenance mode
s,j=o.api('GET','admin/business-controls'); d=j['data']; d['maintenance']['enabled']=True; print('maint on',o.api('POST','admin/business-controls',d)[0])
print('public during maint',C().raw('GET','/')[0],'api',C().api('GET','public-config')[0],'user api',bo.api('GET','allowance')[0],'login page',C().raw('GET','/login')[0],'webhook ok?',C().raw('POST','/webhooks/razorpay',data=b'{}',headers={'X-Razorpay-Signature':'x'})[0],'admin api',o.api('GET','admin/overview')[0],'checkout',bo.api('POST','checkout/start',{'plan':'monthly'})[0])
d['maintenance']['enabled']=False; print('maint off',o.api('POST','admin/business-controls',d)[0])
# ---- traffic / analytics consent
print('traffic no consent',C().api('POST','traffic',{'path':'/x','source':'direct'}) )
t=C(); print('traffic consent',t.api('POST','traffic',{'path':'/templates.html','source':'google','consent':True}),'bad path',t.api('POST','traffic',{'path':'/../x','source':'google','consent':True})[0])
print('page_views',q("select path,source,count(*) from page_views group by 1,2"))
# ---- reviews
print('feedback',bo.api('POST','feedback',{'displayName':'Bob','rating':5,'content':'Great product, loved it','consent':True}))
rid=q("select id from feedback")[0][0]; print('public reviews before publish',len(C().api('GET','public-content')[1]['reviews']))
print('publish',o.api('POST','admin/reviews',{'id':rid,'status':'published'})); print('public after',len(C().api('GET','public-content')[1]['reviews']))
print('withdraw consent',bo.api('POST',f'feedback/{rid}/withdraw',{})); print('public after withdraw',len(C().api('GET','public-content')[1]['reviews']))

"""Capture API data and public/dashboard pages from a running CVTara preview server (CVTARA_PREVIEW=true, php artisan cvtara:demo)."""
import sys,json,os
sys.path.insert(0,os.path.dirname(__file__))
import client
client.BASE=sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:8000'
OUT=sys.argv[2] if len(sys.argv)>2 else 'snap'
from client import C
os.makedirs(OUT,exist_ok=True)
PW='DemoPass#2026!x'
cust=C(); cust.login('demo@cvtara.test',PW); cust.refresh('/workspace.html')
own=C(); own.login('owner@cvtara.test',PW); own.refresh('/admin.html')
pub=C()
snap={'pub':{},'cust':{},'own':{}}
def g(c,key,path):
    s,j=c.api('GET',path); snap[key][path]=j
    if s!=200: print('!!',key,path,s)
    return j
for p in ['business-public','public-config','public-content','public-activity','health']: g(pub,'pub',p)
for p in ['profile','allowance','resumes','letters','applications','purchases','referrals','referral-rewards','feedback','security/status']: g(cust,'cust',p)
for r in snap['cust']['resumes']['resumes']: g(cust,'cust','resumes/'+r['id'])
for r in snap['cust']['letters']['letters']: g(cust,'cust','letters/'+r['id'])
own_paths=['admin/dashboard?days=30','admin/overview?days=30','admin/users','admin/plans','admin/business-controls','admin/settings','admin/providers','admin/purchases','admin/reports?days=30','admin/reviews','admin/email/jobs','admin/seo','admin/page-audit','admin/letter-summary','admin/referrals','admin/setup/status','admin/errors?days=30','admin/site-urls','admin/health/history','admin/legal/generate']+['admin/content/'+k for k in ['blog','features','library','legal','emails','seo','integrations','popup','site']]+['profile','allowance']
for p in own_paths: g(own,'own',p)
for pu in snap['own']['admin/purchases']['purchases']: g(own,'own','admin/orders/'+pu['id'])
json.dump(snap,open(OUT+'/api.json','w'))
print({k:len(v) for k,v in snap.items()})
pages={}
def page(c,path,name):
    s,h,b=c.raw('GET',path); pages[name]=b.decode('utf8')
    if s!=200: print('page',name,s)
for path,name in [('/','home.html'),('/templates.html','templates.html'),('/cover-letters.html','cover-letters.html'),('/blog.html','blog.html'),('/guides.html','guides.html'),('/about.html','about.html'),('/contact.html','contact.html'),('/privacy.html','privacy.html'),('/terms.html','terms.html'),('/refund.html','refund.html'),('/customer-guide.html','customer-guide.html'),('/checkout.html','checkout.html'),('/login','login.html'),('/register','register.html'),('/forgot-password','forgot-password.html')]: page(pub,path,name)
page(cust,'/workspace.html','workspace.html'); page(own,'/admin.html','admin.html'); page(own,'/admin-guide.html','admin-guide.html')
# every article page (unique content per page)
cat=json.load(open(os.path.join(os.path.dirname(__file__),'catalog.json')))['blog']
for it in cat:
    pre='calculators' if it['type']=='Calculator' else 'guides'
    page(pub,f"/{pre}/{it['slug']}/",f"{pre}/{it['slug']}")
pages['article.html']=pages[f"guides/{[i for i in cat if i['type']=='Guide'][0]['slug']}"]
json.dump(pages,open(OUT+'/pages.json','w'))
print(len(pages),'pages',sum(len(v) for v in pages.values())//1024,'KB')

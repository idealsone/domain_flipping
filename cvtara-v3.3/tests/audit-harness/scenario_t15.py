import sys,sqlite3,time,json; sys.path.insert(0,sys.argv[1]); from client import *
DB=sys.argv[1]+'/run/database/database.sqlite'
q=lambda sql,*a:(lambda c:(c.execute(sql,a).fetchall(),c.commit(),c.close()))(sqlite3.connect(DB))
q("delete from cache")
P='"><img src=x onerror=window.__xss=(window.__xss||0)+1>'
x=C(); s,h,_=x.register('xss@test.local',name='Eve '+P); print('register xss name',s)
x.refresh('/workspace.html')
print(x.api('POST','profile',{'name':'Eve '+P,'phone':P[:30],'language':'en','experienceLevel':'experienced','profession':P,'category':'Other','experienceYears':3,'city':P,'marketingConsent':True})[0])
print('app',x.api('POST','applications',{'company':P,'role':P,'stage':'applied','notes':P,'jobUrl':'https://x.com/"onmouseover="window.__xss=9'})[0])
print('fb',x.api('POST','feedback',{'displayName':P,'rating':5,'content':'content '+P+' long enough','consent':True})[0])
rid=q("select id from feedback")[0][0] if False else None

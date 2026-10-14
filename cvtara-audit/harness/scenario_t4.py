import sys,json; sys.path.insert(0,sys.argv[1]); from client import *
al=C(); al.login('alice@test.local','TestPassw0rd!!x'); al.refresh('/workspace.html')
def resume(title='My CV',tpl='suite-prism',name='Alice & Bob <Co>'):
    return {'version':'1','title':title,'template':tpl,'accent':'#1557d2','font':'Arial','personal':{'name':name,'email':'a@x.com','role':'Recruiter & "HR" <lead>'},'summary':'Led teams & <b>grew</b> output by 40%.','order':['summary','experience','education','projects','skills','certifications','languages','awards'],'experience':[{'id':'1','role':'Manager','company':'A&B Corp','start':'2020','end':'2024','bullets':'• Did R&D\n• Hired <10 people'}],'education':[],'projects':[],'certifications':[],'skills':'Excel, SQL'}
s,j=al.api('POST','resumes',{'data':resume()}); print('save',s,j); rid=j.get('id'); rev=j.get('revision')
print('update ok',al.api('POST','resumes',{'id':rid,'revision':rev,'data':resume('v2')}))
print('stale update',al.api('POST','resumes',{'id':rid,'revision':rev,'data':resume('v3')})[0])
print('bad template',al.api('POST','resumes',{'data':resume(tpl='nope')})[0])
print('2nd',al.api('POST','resumes',{'data':resume('b')})[0],'3rd (free limit 2)',al.api('POST','resumes',{'data':resume('c')}))
print('list',[ (r['title'],r['revision']) for r in al.api('GET','resumes')[1]['resumes']])
print('get',al.api('GET','resumes/'+rid)[1]['resume']['revision'])
# export free
for fmt in ['print','pdf','docx']:
    s,h,b=al.raw('POST',f'/export/resume/{rid}/{fmt}',json_body={'clean':False},headers={'X-CSRF-TOKEN':al.csrf,'Accept':'application/json'})
    print('export',fmt,s,h.get('Content-Type'),len(b), b[:12] if fmt!='print' else (b'Free preview' in b, b'&amp;' in b))
    if fmt=='docx' and s==200: open(sys.argv[1]+'/out.docx','wb').write(b)
    if fmt=='pdf' and s==200: open(sys.argv[1]+'/out.pdf','wb').write(b)
s,h,b=al.raw('POST',f'/export/resume/{rid}/pdf',json_body={'clean':True},headers={'X-CSRF-TOKEN':al.csrf,'Accept':'application/json'}); print('clean free',s,b[:100])
# letters
L={'version':'1','title':'Letter','template':'letter-prism','accent':'#1557d2','font':'Arial','personal':{'name':'Alice'},'company':'Acme','role':'HR','opening':'Hello there','evidence':'I did things.','date':'2026-10-14'}
s,j=al.api('POST','letters',{'data':L}); print('letter',s,j)
s,h,b=al.raw('POST',f'/export/letter/{j["id"]}/pdf',json_body={'clean':False},headers={'X-CSRF-TOKEN':al.csrf,'Accept':'application/json'}); print('letter pdf',s,b[:8])
# applications
A={'company':'Acme','role':'HR','stage':'applied','jobUrl':'https://x.com/j','appliedAt':'2026-10-01','followUp':'2026-10-14','notes':'n'}
s,j=al.api('POST','applications',A); print('app',s,j); aid=j['id']
print('app edit',al.api('POST','applications',{**A,'id':aid,'revision':1,'stage':'interview'}))
print('app stale',al.api('POST','applications',{**A,'id':aid,'revision':1})[0])
print('app del stale',al.api('DELETE','applications/'+aid,{'revision':1})[0],'ok',al.api('DELETE','applications/'+aid,{'revision':2})[0])
# match usage
for i in range(4): s,j=al.api('POST','features/use',{'action':'match'}); print('match',i,s,j.get('error'))
print('IDOR other user doc', C().api('GET','resumes/'+rid)[0])
b=C(); b.login('bob@test.local','TestPassw0rd!!x'); b.refresh('/workspace.html'); print('IDOR bob get alice doc',b.api('GET','resumes/'+rid)[0], 'export',b.raw('POST',f'/export/resume/{rid}/pdf',json_body={},headers={'X-CSRF-TOKEN':b.csrf,'Accept':'application/json'})[0])
print('ai not configured',al.api('POST','ai/suggest',{'task':'rewrite','language':'English','text':'x'*40,'consent':True}))

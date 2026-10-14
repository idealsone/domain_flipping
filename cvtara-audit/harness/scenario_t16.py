import sys; sys.path.insert(0,sys.argv[1]); from client import *
P='"><img src=x onerror=window.__xss=(window.__xss||0)+1>'
x=C(); x.login('xss@test.local','TestPassw0rd!!x'); x.refresh('/workspace.html')
print(x.api('POST','applications',{'company':P,'role':P,'stage':'applied','notes':P,'jobUrl':'https://x.com/a'})[0])
d={'version':'1','title':P,'template':'suite-prism','accent':'#1557d2','font':'Arial','personal':{'name':P,'role':P},'summary':P,'order':['summary','experience'],'experience':[{'id':'1','role':P,'company':P,'bullets':P}],'education':[],'projects':[],'certifications':[]}
print(x.api('POST','resumes',{'data':d}))

import sys; sys.path.insert(0,sys.argv[1]); from client import *
a=C('A'); s,h,b=a.register('alice@test.local'); print('register',s,h.get('Location'))
a.refresh('/workspace.html')
print('profile',a.api('GET','profile')[0])
code=a.api('GET','referrals')[1]['code']; print('refcode',code)
b_=C('B'); s,h,_=b_.register('bob@test.local',ref=code); print('bob register',s,h.get('Location')); b_.refresh('/workspace.html')
s,j=b_.api('GET','referrals'); print('bob refs',s,j.get('ok'))
s,j=a.api('GET','referrals'); print('alice sees', j['signups'], j['clicks'], j['referredUsers'])
# security: no csrf
x=C(); print('no-csrf POST', x.raw('POST','/api/profile',json_body={},headers={'Accept':'application/json'})[0])
print('anon allowance', C().api('GET','allowance')[0])
print('admin api as user', a.api('GET','admin/overview')[0])
print('admin page as user', a.raw('GET','/admin.html')[0])

import sys,sqlite3,time,json; sys.path.insert(0,sys.argv[1]); from client import *
DB=sys.argv[1]+'/run/database/database.sqlite'
c=sqlite3.connect(DB)
base=c.execute('select max(id) from users').fetchone()[0]
rows=[(f'Bulk {i}',f'bulk{i}@x.local','x',time.strftime('%Y-%m-%d %H:%M:%S'),time.strftime('%Y-%m-%d %H:%M:%S')) for i in range(2000)]
c.executemany("insert into users(name,email,password,created_at,updated_at) values(?,?,?,?,?)",rows)
c.execute("insert into profiles(owner_id,name,created_at,updated_at) select id,name,datetime('now'),datetime('now') from users where id>?",(base,))
c.commit(); print('users now',c.execute('select count(*) from users').fetchone()[0]); c.close()
import sqlite3 as s3
cl=C(); cl.login('owner@test.local','OwnerPassw0rd!!'); cl.refresh('/admin.html')
for p in ['admin/dashboard?days=30','admin/overview?days=30','admin/users','admin/referrals','admin/reports?days=30']:
    t=time.time(); s,j=cl.api('GET',p); print(p,s,round(time.time()-t,2),'s', 'users in payload:',len(j['users']) if isinstance(j.get('users'),list) else j.get('users'),'totalUsers',j.get('totalUsers'))

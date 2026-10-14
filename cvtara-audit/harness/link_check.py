import sys,re,collections,urllib.parse; sys.path.insert(0,sys.argv[1]); from client import *
c=C(); seen={}; queue=['/']; pages=0; broken=collections.defaultdict(set); refs=set()
skip=re.compile(r'^(mailto:|tel:|javascript:|#|https?://(?!127))')
def fetch(p):
    if p in seen: return seen[p]
    s,h,b=c.raw('GET',p); seen[p]=(s,h,b); return seen[p]
seeds=['/','/templates.html','/cover-letters.html','/blog.html','/about.html','/contact.html','/privacy.html','/terms.html','/refund.html','/checkout.html','/credits.html','/guides.html','/customer-guide.html','/login','/register']
queue=list(seeds)+[l.strip() for l in open(sys.argv[1]+'/paths.txt')]
done=set()
while queue:
    p=queue.pop(0)
    if p in done: continue
    done.add(p); s,h,b=fetch(p)
    if s in (301,302,303,307,308):
        loc=h.get('Location','').replace('http://127.0.0.1:8000',''); 
        if loc.startswith('/login') and not p.startswith('/login'): continue
        if loc and loc not in done and loc.startswith('/'): queue.append(loc)
        continue
    if s>=400: broken[p].add(f'PAGE {s}'); continue
    if 'text/html' not in h.get('Content-Type',''): continue
    html=b.decode('utf8','ignore'); pages+=1
    for m in re.finditer(r'(?:href|src)="([^"]+)"',html):
        u=m.group(1)
        if skip.match(u) or u.startswith('data:') or u.startswith('//'): continue
        bm=re.search(r'<base href="([^"]+)"',html); full=urllib.parse.urljoin('http://x'+(bm.group(1) if bm else p),u); pr=urllib.parse.urlparse(full); path=pr.path+('?'+pr.query if pr.query else '')
        if pr.path.startswith(('/api/','/logout','/export','/webhooks','/r/')): continue
        if re.search(r'\.(css|js|svg|png|webp|ico|mjs|jpg|zip|txt|xml)$',pr.path):
            if path in refs: continue
            refs.add(path); s2,_,_=fetch(path.split('?')[0]) 
            if s2>=400: broken[path].add(f'asset {s2} <- {p}')
        else:
            if pr.path not in done: queue.append(pr.path)
print('html pages crawled',pages,'unique assets',len(refs))
for k,v in sorted(broken.items()): print('BROKEN',k,sorted(v)[:3])
print('total broken',len(broken))

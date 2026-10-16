"""Build the browser-only static preview (three entry pages + shared files) from a snapshot.
usage: build_static.py <public_html dir> <snapshot dir> <out dir>"""
import sys,os,re,json,shutil,time
SRC,SNAP,OUT=sys.argv[1:4]
HERE=os.path.dirname(os.path.abspath(__file__))
BUILD=str(int(time.time()))
shutil.rmtree(OUT,ignore_errors=True); os.makedirs(OUT)
n=0
CON=os.path.join(os.path.dirname(SRC),'resources','console')
for BASE,PFX in ((SRC,''),(CON,'console')):
  if not os.path.isdir(BASE): continue
  for root,dirs,fs in os.walk(BASE):
      dirs[:]=[x for x in dirs if not x.startswith('_')]  # ready-made page cache (_pages) is not part of the preview
      for f in fs:
          if f.endswith(('.php','.zip','.map')) or f in ('.htaccess','.install-key-hash'): continue
          rel=os.path.join(PFX,os.path.relpath(os.path.join(root,f),BASE)); d=os.path.join(OUT,rel); os.makedirs(os.path.dirname(d),exist_ok=True)
          if f.endswith(('.js','.mjs')):
              t=open(os.path.join(root,f),encoding='utf8').read()
              t=re.sub(r"import\('/",r"import('./",t); t=re.sub(r"(src|workerSrc)=('|\")/assets",r"\1=\2assets",t); t=t.replace("workerSrc='/pdf","workerSrc='pdf")
              t=re.sub(r"(?<![.\w])location\.href\s*=\s*((?:`(?:[^`\\]|\\.)*`|'(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\"|[^;\n}`'\"])+)",lambda m:"__cvm.go("+m.group(1).strip()+")",t)
              t=t.replace('\x1b','\\x1b')
              open(d,'w',encoding='utf8').write(t)
          else:
              b=open(os.path.join(root,f),'rb').read()
              if f.endswith(('.css','.svg','.txt','.html')): b=b.replace(b'\x1b',b'\\x1b')
              open(d,'wb').write(b)
          n+=1
snap=json.load(open(SNAP+'/api.json')); pages=json.load(open(SNAP+'/pages.json'))
open(OUT+'/snap.js','w').write('window.__SNAP='+json.dumps(snap)+';')
open(OUT+'/pages.js','w').write('window.__PAGES='+json.dumps(pages)+';')
open(OUT+'/mock-api.js','w').write(open(HERE+'/mock-api.js').read().replace('__BUILD__',BUILD)+'\n'+open(HERE+'/match-engine.js').read()+'\n'+open(HERE+'/ats-mock.js').read())
tpl=open(HERE+'/entry.html').read().replace('__BUILD__',BUILD)
for name,title,role,page in [('entry-home','CVTara Home','null','home.html'),('entry-customer','CVTara Customer','"customer"','workspace.html'),('entry-admin','CVTara Admin','"owner"','admin.html')]:
    open(f'{OUT}/{name}.html','w').write(tpl.replace('__TITLE__',title).replace('__ROLE__',role).replace('__PAGE__',page))
tot=sum(os.path.getsize(os.path.join(r,f)) for r,_,fs in os.walk(OUT) for f in fs)
print('built',n,'files',tot//1024,'KB build',BUILD)

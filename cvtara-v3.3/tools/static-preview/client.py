import urllib.request,urllib.parse,http.cookiejar,json,re,sys
BASE='http://127.0.0.1:8000'
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
class C:
    def __init__(self,name=''):
        self.name=name; self.jar=http.cookiejar.CookieJar()
        self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),NoRedir)
        self.csrf=None
    def raw(self,method,path,data=None,headers=None,json_body=None,form=None):
        h=dict(headers or {}); body=None
        if json_body is not None: body=json.dumps(json_body).encode(); h['Content-Type']='application/json'
        elif form is not None: body=urllib.parse.urlencode(form).encode(); h['Content-Type']='application/x-www-form-urlencoded'
        elif data is not None: body=data if isinstance(data,bytes) else data.encode()
        req=urllib.request.Request(BASE+path,data=body,method=method,headers=h)
        try: r=self.op.open(req)
        except urllib.error.HTTPError as e: r=e
        return r.status,dict(r.headers),r.read()
    def page_token(self,path='/login'):
        s,h,b=self.raw('GET',path); m=re.search(rb'name="_token" value="([^"]+)"',b) or re.search(rb'name="csrf-token" content="([^"]+)"',b)
        self.csrf=m.group(1).decode() if m else None; return self.csrf
    def refresh(self,path='/'):
        s,h,b=self.raw('GET',path); m=re.search(rb'name="csrf-token" content="([^"]+)"',b); self.csrf=m.group(1).decode() if m else self.csrf; return self.csrf
    def register(self,email,pw='TestPassw0rd!!x',ref=None,name='Test User'):
        if ref: self.raw('GET','/r/'+ref)
        t=self.page_token('/register')
        return self.raw('POST','/register',form={'_token':t,'name':name,'email':email,'password':pw,'password_confirmation':pw,'experienceLevel':'experienced','profession':'Recruiter','category':'HR & Recruitment','terms':'1'})
    def login(self,email,pw):
        t=self.page_token('/login'); return self.raw('POST','/login',form={'_token':t,'email':email,'password':pw})
    def api(self,method,path,body=None):
        if not self.csrf: self.refresh()
        h={'Accept':'application/json','X-CSRF-TOKEN':self.csrf or ''}
        s,hh,b=self.raw(method,'/api/'+path,json_body=body if method!='GET' else None,headers=h)
        try: j=json.loads(b)
        except Exception: j={'_raw':b[:200].decode('utf8','ignore')}
        return s,j

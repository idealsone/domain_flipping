import subprocess,re
def _lit(v): return 'NULL' if v is None else (str(v) if isinstance(v,(int,float)) else "'"+str(v).replace("\\","\\\\").replace("'","\\'")+"'")
def q(sql,*a):
    sql=re.sub(r"datetime\('now','([+-]\d+) (day|days|hour|hours)'\)",lambda m:f"(NOW() {m.group(1)[0]} INTERVAL {m.group(1)[1:]} {m.group(2).upper().rstrip('S')})",sql)
    sql=sql.replace("datetime('now')","NOW()")
    sql=re.sub(r"\btrigger\b","`trigger`",sql)
    it=iter(a); sql=re.sub(r"\?",lambda m:_lit(next(it)),sql)
    out=subprocess.run(['mariadb','-N','-B','cvtara','-e',sql],capture_output=True,text=True)
    if out.returncode: raise Exception(out.stderr+sql)
    rows=[]
    for line in out.stdout.splitlines():
        rows.append(tuple((None if x=='NULL' else (int(x) if re.fullmatch(r'-?\d+',x) else x)) for x in line.split('\t')))
    return rows

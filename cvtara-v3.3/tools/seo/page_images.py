#!/usr/bin/env python3
"""One picture per SEO page (3.79.0): a 1200x675 WebP drawn from the page's own content, with the CVTara mark and a
copyright line, plus copyright metadata inside the file (XMP: creator, credit line, copyright notice, rights page).

  python3 tools/seo/page_images.py            # all pages
  python3 tools/seo/page_images.py --only resume-examples/sales/

Reads app/resources/data/seo-pages.json (run build_seo_data.py first), writes
public_html/assets/seo/pages/<page-path-with-dashes>.webp and app/resources/data/seo-images.json (path -> src, w, h, alt).
Drawing is done by page_images.cjs (Playwright). Every sample name and number on a picture is invented, and the picture
says "Sample" where it shows a person's resume. The page list must match SeoPages::paths() (test s86 checks it).
"""
import argparse, hashlib, html, json, os, subprocess, sys, tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
DATA = json.loads((ROOT / 'app/resources/data/seo-pages.json').read_text(encoding='utf8'))
OUT = ROOT / 'public_html/assets/seo/pages'
INDEX = ROOT / 'app/resources/data/seo-images.json'
W, H = 1200, 675
YEAR = 2026
SITE = 'https://cvtara.com'

C = dict(blue='#1f4fd1', deep='#173a9e', soft='#c6d4ff', mint='#a6efd5', ink='#1c1917', ink2='#57534e', paper='#f7f4ee', line='#e7dccb',
         sand='#efe6d6', accent='#b4531a', good='#15803d', bad='#b91c1c', warn='#b45309')
NAMES = ['Ananya Rao', 'Rohit Saxena', 'Priya Nair', 'Arjun Mehta', 'Kavya Iyer', 'Vikram Singh', 'Sneha Kulkarni', 'Rahul Verma', 'Divya Menon',
         'Karan Malhotra', 'Meera Pillai', 'Aditya Joshi', 'Pooja Reddy', 'Sanjay Gupta', 'Neha Kapoor', 'Imran Sheikh', 'Lakshmi Krishnan', 'Farhan Ali',
         'Shreya Das', 'Manoj Patil', 'Nisha Bhat', 'Gaurav Chawla', 'Swati Mishra', 'Deepak Yadav']


def e(s):
    return html.escape(str(s), quote=True)


def h(s, n=999):
    return int(hashlib.md5(s.encode()).hexdigest()[:8], 16) % n


def cut(s, n):
    s = ' '.join(str(s).split())
    return s if len(s) <= n else s[:n - 1].rsplit(' ', 1)[0].rstrip(',.;:') + '…'


def first_sentence(s):
    s = str(s).strip()
    i = s.find('. ')
    return s[:i + 1] if 0 < i < 170 else cut(s, 170)


# ---------------------------------------------------------------- pieces (HTML at 1200x675)
MARK = (f'<svg width="{{s}}" height="{{s}}" viewBox="0 0 64 64"><rect width="64" height="64" rx="18" fill="#214ed3"/><path d="M18 15h21l9 9v27H18z" fill="#fff"/>'
        f'<path d="M39 15v10h9" fill="{C["soft"]}"/><path d="M24 32h12M24 38h9M24 44h15" stroke="#214ed3" stroke-width="3" stroke-linecap="round"/>'
        f'<path d="m47 6 3.5 8.5L59 18l-8.5 3.5L47 30l-3.5-8.5L35 18l8.5-3.5Z" fill="{C["mint"]}" stroke="#214ed3" stroke-width="2"/></svg>')


def chips(items, size=15, fill='#fff', ink=None, border=None):
    ink = ink or C['ink']
    border = border or C['line']
    return '<div class="chips">' + ''.join(f'<span style="font-size:{size}px;background:{fill};color:{ink};border-color:{border}">{e(x)}</span>' for x in items) + '</div>'


def resume_card(name, role_line, summary, bullets, skills, extra=''):
    return f'''<div class="paper resume"><div class="tag">Sample</div>
<div class="rname">{e(name)}</div><div class="rrole">{e(role_line)}</div>
<div class="rh">PROFILE</div><p class="rt">{e(cut(summary, 150))}</p>
<div class="rh">EXPERIENCE</div><ul class="rb">{''.join(f'<li>{e(cut(b, 92))}</li>' for b in bullets[:2])}</ul>
<div class="rh">SKILLS</div>{chips(skills[:6], 13, C['paper'])}{extra}</div>'''


def letter_card(opening, sign='Your name'):
    return f'''<div class="paper letter"><div class="tag">Sample</div><div class="lmeta">To the Hiring Manager</div>
<p class="lt"><b>Dear Hiring Manager,</b></p><p class="lt">{e(cut(opening, 230))}</p>
<div class="bar" style="width:96%"></div><div class="bar" style="width:88%"></div><div class="bar" style="width:92%"></div><div class="bar" style="width:60%"></div>
<p class="lt" style="margin-top:18px">Regards,<br><b>{e(sign)}</b></p></div>'''


def question_cards(qs, label='Interview question'):
    out = ''
    for i, (q, tip) in enumerate(qs[:3]):
        out += (f'<div class="paper qcard" style="margin-left:{i * 22}px"><div class="qn" style="background:{[C["blue"], C["accent"], C["good"]][i]}">Q{i + 1}</div>'
                f'<div><div class="qq">{e(cut(q, 70))}</div><div class="qt">{e(cut(tip, 80))}</div></div></div>')
    return f'<div class="qstack">{out}</div>'


def skyline(seed):
    x, out = 0, ''
    while x < 470:
        w = 28 + h(f'{seed}w{x}', 40)
        ht = 60 + h(f'{seed}h{x}', 150)
        col = [C['deep'], C['blue'], '#2b5be0'][h(f'{seed}c{x}', 3)]
        win = ''.join(f'<rect x="{x + 7 + j * 10}" y="{230 - ht + 12 + k * 16}" width="5" height="7" fill="{C["soft"]}" opacity=".55"/>'
                      for j in range(max(1, (w - 10) // 10)) for k in range(max(1, ht // 16 - 1)) if h(f'{seed}{x}{j}{k}', 5) == 0)
        out += f'<rect x="{x}" y="{230 - ht}" width="{w}" height="{ht}" rx="3" fill="{col}"/>{win}'
        x += w + 4
    return f'<svg class="sky" width="470" height="230" viewBox="0 0 470 230">{out}</svg>'


def city_card(n, tips, ind, seed):
    return f'''<div class="cityviz">{skyline(seed)}<div class="paper tipcard"><div class="rh" style="margin-top:0">LOCAL TIP · {e(n.upper())}</div>
<p class="rt" style="font-size:18px;line-height:1.45">{e(cut(tips[0], 130))}</p><p class="rt" style="font-size:15px;color:{C['ink2']}">{e(cut(tips[1], 110)) if len(tips) > 1 else ''}</p>{chips(ind[:3], 13, C['paper'])}</div></div>'''


def places_card(names, label, ind=()):
    pins = ''
    n = min(len(names), 8)
    for i, nm in enumerate(names[:8]):
        col, row = i % 2, i // 2
        x = 34 + col * 205 + h(nm + 'x', 40)
        y = 62 + row * (250 // max(1, (n + 1) // 2)) + h(nm + 'y', 18)
        pins += f'<div class="pin" style="left:{x}px;top:{y}px"><i></i>{e(nm)}</div>'
    extra = f'<div style="position:absolute;left:24px;right:24px;bottom:22px"><div class="rh" style="margin-top:0">MAIN EMPLOYERS</div>{chips(ind[:3], 13, C["paper"])}</div>' if ind else ''
    return f'<div class="paper places"><div class="rh" style="margin-top:0">{e(label)}</div>{pins}{extra}</div>'


def words_card(weak, strong, ex):
    return f'''<div class="paper words"><div class="rh" style="margin-top:0">INSTEAD OF</div><div class="weak">{e(weak)}</div>
<div class="rh">TRY</div>{chips(strong[:6], 17, '#eef7f1', C['good'], '#bfe3cc')}
<div class="rh">BEFORE</div><p class="rt" style="text-decoration:line-through;color:{C['ink2']}">{e(cut(ex[0], 70))}</p>
<div class="rh">AFTER</div><p class="rt">{e(cut(ex[1], 110))}</p></div>'''


def ats_card():
    rows = [('Python', 1), ('SQL', 1), ('Stakeholder reporting', 0), ('Power BI', -1)]
    li = ''.join(f'<li><i style="background:{C["good"] if s == 1 else (C["warn"] if s == 0 else C["bad"])}"></i>{e(k)}<span>{["Missing", "Add an example", "Covered"][s + 1]}</span></li>' for k, s in rows)
    return f'''<div class="paper ats"><div class="tag">Sample report</div><div class="ring"><svg width="150" height="150" viewBox="0 0 150 150"><circle cx="75" cy="75" r="58" fill="none" stroke="{C['line']}" stroke-width="14"/>
<circle cx="75" cy="75" r="58" fill="none" stroke="{C['good']}" stroke-width="14" stroke-linecap="round" stroke-dasharray="{364 * .72:.0f} 400" transform="rotate(-90 75 75)"/></svg><b>72%</b><span>match with the job</span></div>
<ul class="kw">{li}</ul></div>'''


def template_card(style, points):
    f = style.get('f', 'simple')
    two = f in ('two-column', 'modern', 'creative')
    photo = f in ('photo',)
    band = f in ('creative', 'modern', 'corporate')
    side = f'<div class="tside">{"<div class=photo></div>" if photo else ""}<div class="bar" style="width:80%;background:#ffffff99"></div><div class="bar" style="width:60%;background:#ffffff99"></div><div class="bar" style="width:70%;background:#ffffff99"></div></div>' if two else ''
    head = f'<div class="thead" style="background:{C["blue"] if band else "transparent"};color:{"#fff" if band else C["ink"]}">{"<div class=photo></div>" if photo and not two else ""}<div><b>Your Name</b><span>Job title · City</span></div></div>'
    body = ''.join(f'<div class="rh">{x}</div><div class="bar" style="width:78%"></div><div class="bar" style="width:68%"></div><div class="bar" style="width:56%"></div>' for x in ['PROFILE', 'EXPERIENCE', 'SKILLS'])
    pts = ''.join(f'<li>{e(cut(p, 58))}</li>' for p in points[:3])
    return f'''<div class="tplwrap"><div class="paper tpl {"two" if two else ""}">{side}<div class="tmain">{head}{body}</div></div><div class="paper tnote"><div class="rh" style="margin-top:0">WHAT MAKES IT {e(style['n'].upper())}</div><ul class="tick">{pts}</ul></div></div>'''


def plans_card():
    rows = [('Free', 'Build, preview, a few ATS checks'), ('Quick Download', 'One clean resume download'), ('Job Hunt', 'Tailored resumes, cover letters, mock interviews'),
            ('Pro', 'For a longer, senior search'), ('Complete Job Kit', 'Pro plus Expert Review and Profile Boost')]
    li = ''.join(f'<div class="plan {"hot" if n == "Job Hunt" else ""}"><b>{e(n)}</b><span>{e(t)}</span>{"<em>Most popular</em>" if n == "Job Hunt" else ""}</div>' for n, t in rows)
    return f'<div class="plans">{li}</div>'


def list_card(label, items):
    return f'<div class="paper listc"><div class="rh" style="margin-top:0">{e(label)}</div><ol>{"".join(f"<li>{e(cut(x, 52))}</li>" for x in items[:7])}</ol></div>'


CSS = f'''*{{box-sizing:border-box;margin:0;padding:0}}html,body{{width:{W}px;height:{H}px;overflow:hidden}}
body{{font-family:Inter,system-ui,sans-serif;color:{C['ink']};background:{C['paper']};-webkit-font-smoothing:antialiased;position:relative}}
body:before{{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(28,25,23,.035) 1px,transparent 1px);background-size:6px 6px}}
.left{{position:absolute;left:64px;top:58px;width:540px;height:560px;display:flex;flex-direction:column}}
.eyebrow{{color:{C['accent']};font:700 17px/1.2 Inter;letter-spacing:.12em;text-transform:uppercase}}
h1{{font-family:Fraunces,Georgia,serif;font-weight:600;letter-spacing:-.015em;line-height:1.06;margin-top:18px;color:{C['ink']}}}
.sub{{margin-top:18px;font:500 22px/1.4 Inter;color:{C['ink2']}}}
.foot{{margin-top:auto;display:flex;align-items:center;gap:14px}}.foot b{{font:700 30px/1 Inter;letter-spacing:-.035em}}
.copy{{margin-top:12px;font:500 15px/1 Inter;color:{C['ink2']}}}
.right{{position:absolute;left:640px;top:0;width:560px;height:{H}px;background:{C['blue']};}}
.right:after{{content:"";position:absolute;left:0;right:0;bottom:0;height:120px;background:linear-gradient(transparent,rgba(23,58,158,.45))}}
.spark{{position:absolute;right:34px;top:30px;width:46px;height:46px}}
.vis{{position:absolute;left:600px;top:52px;width:560px;height:570px;display:flex;align-items:center;justify-content:center;z-index:2}}
.paper{{background:#fff;border:1px solid {C['line']};border-radius:16px;box-shadow:0 1px 2px rgba(28,25,23,.06),0 24px 50px rgba(15,23,60,.22);position:relative}}
.tag{{position:absolute;right:16px;top:14px;font:700 11px/1 Inter;letter-spacing:.1em;text-transform:uppercase;color:{C['accent']};background:{C['sand']};padding:6px 9px;border-radius:20px}}
.resume{{width:440px;padding:30px 30px 26px}}.rname{{font:700 27px/1.1 Fraunces,serif;letter-spacing:.01em}}.rrole{{font:500 15px/1.4 Inter;color:{C['ink2']};margin-top:6px}}
.rh{{margin-top:16px;font:700 12px/1 Inter;letter-spacing:.12em;color:{C['blue']};padding-bottom:6px;border-bottom:1.5px solid {C['line']}}}
.rt{{margin-top:8px;font:400 14px/1.45 Inter;color:{C['ink']}}}.rb{{margin-top:8px;padding-left:18px;font:400 13.5px/1.45 Inter}}
.chips{{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}}.chips span{{border:1px solid;border-radius:20px;padding:5px 11px;font-weight:600;line-height:1}}
.letter{{width:430px;padding:34px 34px 30px}}.lmeta{{font:600 13px/1 Inter;color:{C['ink2']};padding-bottom:14px;border-bottom:1.5px solid {C['line']}}}
.lt{{margin-top:14px;font:400 15px/1.55 Inter}}.bar{{height:9px;border-radius:5px;background:#e3dfd8;margin-top:11px}}
.qstack{{display:flex;flex-direction:column;gap:18px;width:470px}}.qcard{{display:flex;gap:16px;padding:20px 22px;width:440px}}
.qn{{flex:none;width:42px;height:42px;border-radius:12px;color:#fff;font:700 16px/42px Inter;text-align:center}}.qq{{font:700 18px/1.3 Inter}}.qt{{margin-top:6px;font:400 14px/1.4 Inter;color:{C['ink2']}}}
.cityviz{{position:relative;width:470px;height:470px}}.sky{{position:absolute;left:0;bottom:0;opacity:.95;filter:brightness(1.25)}}.tipcard{{position:absolute;left:10px;top:10px;width:450px;padding:24px}}
.places{{width:460px;height:430px;padding:24px;background:#fff}}.pin{{position:absolute;display:flex;align-items:center;gap:10px;font:700 18px/1 Inter}}
.pin i{{width:16px;height:16px;border-radius:50%;background:{C['blue']};box-shadow:0 0 0 6px {C['soft']}}}
.words{{width:440px;padding:28px}}.weak{{display:inline-block;margin-top:10px;font:700 26px/1 Inter;color:{C['bad']};text-decoration:line-through;background:#fdecec;padding:8px 14px;border-radius:12px}}
.ats{{width:440px;padding:28px;display:flex;flex-direction:column;align-items:center}}.ring{{position:relative;width:150px;height:150px}}.ring b{{position:absolute;left:0;right:0;top:46px;text-align:center;font:700 36px/1 Inter}}
.ring span{{position:absolute;left:0;right:0;top:88px;text-align:center;font:500 11px/1.2 Inter;color:{C['ink2']};padding:0 28px}}
.kw{{list-style:none;width:100%;margin-top:18px}}.kw li{{display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid {C['line']};font:600 16px/1 Inter}}.kw i{{width:12px;height:12px;border-radius:50%}}.kw span{{margin-left:auto;font:500 13px/1 Inter;color:{C['ink2']}}}
.tplwrap{{position:relative;width:480px;height:500px}}.tpl{{position:absolute;left:0;top:0;width:330px;height:440px;overflow:hidden;display:flex}}.tside{{width:110px;background:{C['deep']};padding:20px 12px}}
.tmain{{flex:1;padding:0 0 20px}}.thead{{display:flex;gap:12px;align-items:center;padding:22px 22px 16px}}.thead b{{display:block;font:700 20px/1.1 Fraunces,serif}}.thead span{{font:500 12px/1.4 Inter;opacity:.8}}
.tmain .rh,.tmain .bar{{margin-left:22px;margin-right:22px}}.photo{{width:52px;height:52px;border-radius:50%;background:{C['soft']};border:3px solid #fff;margin-bottom:12px}}
.tnote{{position:absolute;right:0;bottom:0;width:290px;padding:20px}}.tick{{list-style:none;margin-top:6px}}.tick li{{font:500 14px/1.4 Inter;padding:7px 0 7px 22px;position:relative}}.tick li:before{{content:"✓";position:absolute;left:0;color:{C['good']};font-weight:700}}
.plans{{display:flex;flex-direction:column;gap:12px;width:440px}}.plan{{background:#fff;border:1px solid {C['line']};border-radius:14px;padding:16px 20px;position:relative;box-shadow:0 14px 30px rgba(15,23,60,.18)}}
.plan b{{display:block;font:700 19px/1.2 Inter}}.plan span{{font:400 14px/1.4 Inter;color:{C['ink2']}}}.plan.hot{{border:2px solid {C['mint']};transform:scale(1.04)}}.plan em{{position:absolute;right:16px;top:16px;font:700 11px/1 Inter;font-style:normal;background:{C['mint']};padding:6px 9px;border-radius:20px;letter-spacing:.06em;text-transform:uppercase}}
.listc{{width:430px;padding:28px}}.listc ol{{margin-top:10px;padding-left:24px}}.listc li{{font:600 17px/1.35 Inter;padding:7px 0;border-bottom:1px solid {C['line']}}}
'''


def page(eyebrow, title, sub, vis, fonts):
    size = 66 if len(title) <= 22 else (56 if len(title) <= 36 else (48 if len(title) <= 52 else 40))
    return f'''<!doctype html><html><head><meta charset="utf-8">{fonts}<style>{CSS}</style></head><body>
<div class="right"><svg class="spark" viewBox="34 5 26 26"><path d="m47 6 3.5 8.5L59 18l-8.5 3.5L47 30l-3.5-8.5L35 18l8.5-3.5Z" fill="{C['mint']}"/></svg></div>
<div class="left"><div class="eyebrow">{e(eyebrow)}</div><h1 style="font-size:{size}px">{e(title)}</h1><div class="sub">{e(sub)}</div>
<div style="margin-top:auto"><div class="foot">{MARK.format(s=46)}<b>CVTara</b></div><div class="copy">© {YEAR} CVTara · cvtara.com</div></div></div>
<div class="vis">{vis}</div></body></html>'''


# ---------------------------------------------------------------- one entry per page (mirrors SeoPages::paths())
def roles_in(c):
    return {k: r for k, r in DATA['roles'].items() if r['c'] == c}


def city_roles(city):
    c = DATA['cities'][city]
    if c['tier'] == 1:
        out = {s: v['role'] for s, v in DATA['cityRoles'].get(city, {}).items()}
    else:
        out = {r: r for r in DATA['tier2Roles']}
        out[c['sp'][0]] = c['sp'][0]
    out['freshers'] = 'freshers'
    return out


def entries():
    d = DATA
    R, CAT, ST, CI = d['roles'], d['categories'], d['states'], d['cities']
    name = lambda k: NAMES[h(k, len(NAMES))]
    out = []
    add = lambda path, eyebrow, title, sub, vis, alt: out.append(dict(path=path, eyebrow=eyebrow, title=title, sub=sub, vis=vis, alt=alt))
    some = lambda keys, n=7: [R[k]['t'] for k in keys if k in R][:n]

    add('/resume-examples/', 'Resume examples', 'Resume examples for every job', f'{len(R)} sample resumes across {len(CAT)} fields, written for Indian hiring',
        list_card('FIELDS', [c['n'] for c in CAT.values()]), 'Resume examples by field: ' + ', '.join(c['n'] for c in list(CAT.values())[:5]) + ' and more, by CVTara')
    add('/resume-templates/', 'Resume templates', 'Resume templates that pass ATS', f'{len(d["templates"])} styles, from ATS-friendly to creative',
        list_card('STYLES', [t['n'] for t in d['templates'].values()]), 'List of CVTara resume template styles: ' + ', '.join(t['n'] for t in list(d['templates'].values())[:6]))
    add('/cover-letter-examples/', 'Cover letter examples', 'Cover letter examples by field', 'Openings written for each field, ready to edit',
        letter_card(CAT['information-technology']['cl']), 'Sample cover letter opening shown on a letter page, by CVTara')
    add('/cover-letter-examples/how-to-write/', 'Guide', 'How to write a cover letter', 'Greeting, three short paragraphs and a sign-off',
        letter_card('I am applying for the role you advertised. In one line, here is why I fit: the skill you need most, with a result that proves it.'), 'A three-paragraph cover letter layout with greeting and sign-off')
    add('/cover-letter-generator/', 'Cover letter generator', 'Cover letter from your resume and the job', 'Paste the job, pick your resume, edit the draft',
        letter_card(CAT['sales']['cl']), 'Cover letter drafted from a resume and a job post, shown on a letter page')
    add('/interview-questions/', 'Interview questions', 'Interview questions with sample answers', f'Questions and answer tips for {len(R)} roles',
        question_cards([R['software-engineer']['q'][0], R['sales-manager']['q'][0], R['hr-manager']['q'][0]]), 'Interview question cards for software engineer, sales manager and HR manager roles')
    add('/resume-builder/', 'Resume builder', 'Free resume builder for India', 'Pick a template, fill each section, check it against the job',
        resume_card(name('builder'), 'Data Analyst · Pune', R['data-analyst']['s'], R['data-analyst']['b'], R['data-analyst']['k']), 'Resume being built in CVTara with profile, experience and skills sections (sample)')
    add('/resume-builder/india/', 'Resume builder India', 'Resume guides for Indian cities and states', f'{len(CI)} cities and {len(ST)} states with local hiring notes',
        places_card([c['n'] for c in CI.values()], f'{len(CI)} CITIES COVERED, INCLUDING'), 'Indian cities covered by CVTara resume guides: ' + ', '.join(c['n'] for c in list(CI.values())[:6]))
    add('/resume-words/', 'Resume words', 'Stronger words for your resume', f'{len(d["words"])} weak words with better choices and examples',
        list_card('WEAK WORDS', [f'{w["w"]} → {w["s"][0]}' for w in d['words'].values()]), 'Weak resume words and stronger replacements, such as ' + ', '.join(f'{w["w"]} to {w["s"][0]}' for w in list(d['words'].values())[:3]))
    add('/ats-checker/', 'ATS checker', 'Free ATS resume checker', 'See matched, partly matched and missing keywords for a job',
        ats_card(), 'Sample ATS report with a 72% match and keywords marked covered, add an example or missing')
    add('/pricing/', 'Plans and pricing', 'CVTara plans: pay once, no renewals', 'Free to build. Job Hunt is the most popular pack.',
        plans_card(), 'CVTara plans: Free, Quick Download, Job Hunt, Pro and Complete Job Kit')
    for c, cat in CAT.items():
        rs = roles_in(c)
        add(f'/resume-examples/{c}/', f'Resume examples · {cat["n"]}', f'{cat["n"]} resume examples', cut(cat['i'], 110),
            list_card('ROLES IN THIS FIELD', [r['t'] for r in rs.values()] or cat['e']), f'{cat["n"]} resume examples for roles such as ' + ', '.join(r['t'] for r in list(rs.values())[:3]))
        if rs:
            add(f'/cover-letter-examples/{c}/', f'Cover letter · {cat["n"]}', f'{cat["n"]} cover letter examples', 'What recruiters in this field want to read first',
                letter_card(cat['cl'], name(c)), f'Sample {cat["n"].lower()} cover letter opening on a letter page, by CVTara')
    for k, r in R.items():
        cat = CAT[r['c']]['n']
        add(f'/resume-examples/{r["c"]}/{k}/', f'Resume example · {cat}', f'{r["t"]} resume example', ' · '.join(r['k'][:3]),
            resume_card(name(k), f'{r["t"]} · India', r['s'], r['b'], r['k']), f'Sample {r["t"]} resume with profile, experience and skills such as ' + ', '.join(r['k'][:3]))
        add(f'/interview-questions/{k}/', f'Interview questions · {cat}', f'{r["t"]} interview questions', 'With sample answers and what the interviewer checks',
            question_cards(r['q']), f'{r["t"]} interview questions: ' + '; '.join(q[0] for q in r['q'][:2]))
    for k, t in d['templates'].items():
        add(f'/resume-templates/{k}/', 'Resume template', f'{t["n"]} resume template', cut(t['w'], 100),
            template_card(t, t['u']), f'{t["n"]} resume template layout with ' + cut(t['u'][0], 80).lower())
    for s, st in ST.items():
        cities = [CI[c]['n'] for c in st['cities'] if c in CI]
        add(f'/resume-builder/{s}/', f'Resume builder · {st["n"]}', f'Resume builder for {st["n"]} jobs', 'Hiring hubs: ' + ', '.join(cities[:3]),
            places_card(cities, f'{st["n"].upper()} · HIRING HUBS', st['ind']), f'Resume guide for {st["n"]}: ' + ', '.join(cities[:3]) + ' and ' + st['ind'][0])
        g = R['government-jobs']
        add(f'/resume/{s}/government-jobs/', f'Government jobs · {st["n"]}', f'Government job resume for {st["n"]}', cut(st['psc'], 90),
            resume_card(name(s + 'gov'), f'Government jobs · {st["n"]}', g['s'], g['b'], g['k']), f'Sample government job resume for {st["n"]} ({st["psc"]})')
    for c, ci in CI.items():
        add(f'/resume-builder/{c}/', f'Resume builder · {ci["n"]}', f'Resume builder for jobs in {ci["n"]}', 'Top employers: ' + ', '.join(ci['ind'][:2]),
            city_card(ci['n'], ci['tips'], ci['ind'], c), f'Resume tips for jobs in {ci["n"]}: ' + cut(ci['tips'][0], 90))
        for slug, rk in city_roles(c).items():
            r = R[rk]
            t = 'Freshers' if slug == 'freshers' else r['t']
            add(f'/resume/{c}/{slug}/', f'{ci["n"]} · {CAT[r["c"]]["n"]}', f'{t} resume for {ci["n"]} jobs', ' · '.join(r['k'][:3]),
                resume_card(name(c + slug), f'{r["t"] if slug != "freshers" else "Graduate Trainee"} · {ci["n"]}', r['s'], r['b'], r['k']),
                f'Sample {t.lower() if t == "Freshers" else t} resume for jobs in {ci["n"]}, with skills such as ' + ', '.join(r['k'][:3]))
    for k, w in d['words'].items():
        ex = w['ex'] if len(w['ex']) >= 2 else [w['ex'][0], w['ex'][0]]
        add(f'/resume-words/{k}/', 'Resume words', f'Other words for "{w["w"]}" on a resume', cut(w['m'], 100),
            words_card(w['w'], w['s'], ex), f'Stronger resume words than {w["w"].lower()}: ' + ', '.join(w['s'][:4]))
    seen = set()
    for x in out:
        assert x['path'] not in seen, x['path']
        seen.add(x['path'])
        x['file'] = x['path'].strip('/').replace('/', '-') + '.webp'
    return out


def xmp(alt):
    a = e(alt)
    return f'''<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/" xmlns:plus="http://ns.useplus.org/ldf/xmp/1.0/">
<dc:creator><rdf:Seq><rdf:li>CVTara</rdf:li></rdf:Seq></dc:creator><dc:rights><rdf:Alt><rdf:li xml:lang="x-default">© {YEAR} CVTara. All rights reserved.</rdf:li></rdf:Alt></dc:rights>
<dc:description><rdf:Alt><rdf:li xml:lang="x-default">{a}</rdf:li></rdf:Alt></dc:description><photoshop:Credit>CVTara (cvtara.com)</photoshop:Credit>
<xmpRights:Marked>True</xmpRights:Marked><xmpRights:WebStatement>{SITE}/terms.html</xmpRights:WebStatement>
<plus:Licensor><rdf:Seq><rdf:li rdf:parseType="Resource"><plus:LicensorURL>{SITE}/contact.html</plus:LicensorURL></rdf:li></rdf:Seq></plus:Licensor>
</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>'''.encode('utf8')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--only')
    a = ap.parse_args()
    from PIL import Image
    items = entries()
    if a.only:
        items = [x for x in items if a.only.strip('/') in x['path']]
    fonts = '<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=block" rel="stylesheet">'
    OUT.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        jobs = []
        for x in items:
            hp = Path(tmp) / (x['file'] + '.html')
            hp.write_text(page(x['eyebrow'], x['title'], x['sub'], x['vis'], fonts), encoding='utf8')
            jobs.append({'html': str(hp), 'png': str(Path(tmp) / (x['file'] + '.png'))})
        (Path(tmp) / 'jobs.json').write_text(json.dumps(jobs))
        subprocess.run(['node', str(HERE / 'page_images.cjs'), str(Path(tmp) / 'jobs.json'), str(W), str(H)], check=True)
        index = json.loads(INDEX.read_text()) if INDEX.exists() and a.only else {}
        for x, j in zip(items, jobs):
            im = Image.open(j['png']).convert('RGB')
            im.save(OUT / x['file'], 'WEBP', quality=78, method=6, xmp=xmp(x['alt']))
            index[x['path']] = ['/assets/seo/pages/' + x['file'], W, H, x['alt']]
    INDEX.write_text(json.dumps(dict(sorted(index.items())), ensure_ascii=False, indent=0) + '\n', encoding='utf8')
    total = sum((OUT / x['file']).stat().st_size for x in items)
    print(f'wrote {len(items)} pictures, {total / 1024 / 1024:.1f} MB, average {total / max(1, len(items)) / 1024:.0f} KB')


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Draws the illustration cards used by the SEO pages (public_html/assets/seo/*.svg). Run after changing colours."""
from pathlib import Path
OUT = Path(__file__).resolve().parents[2] / 'public_html' / 'assets' / 'seo'
NAVY, NAVY2, TINT, AMBER, TEAL, INK, MUTED, LINE = '#2B3A8C', '#1E2A66', '#EEF0FA', '#F59E0B', '#0F9D8A', '#0F172A', '#94A3B8', '#E2E8F0'

def frame(body, title):
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 520 390" width="520" height="390" role="img"><title>{title}</title>
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{TINT}"/><stop offset="1" stop-color="#DDE3F7"/></linearGradient>
<filter id="s" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="10" stdDeviation="12" flood-color="{NAVY}" flood-opacity=".18"/></filter></defs>
<rect width="520" height="390" rx="22" fill="url(#g)"/>{body}</svg>'''

def lines(x, y, widths, gap=14, h=7, color=LINE):
    return ''.join(f'<rect x="{x}" y="{y+i*gap}" width="{w}" height="{h}" rx="3.5" fill="{color}"/>' for i, w in enumerate(widths))

def doc(x, y, w=220, h=290, heading=NAVY):
    return (f'<g filter="url(#s)"><rect x="{x}" y="{y}" width="{w}" height="{h}" rx="12" fill="#fff"/></g>'
            f'<rect x="{x+20}" y="{y+22}" width="{w*0.55}" height="12" rx="6" fill="{heading}"/>'
            + lines(x+20, y+42, [w*0.4], h=6, color=MUTED)
            + f'<rect x="{x+20}" y="{y+62}" width="{w-40}" height="2" fill="{heading}" opacity=".5"/>'
            + f'<rect x="{x+20}" y="{y+78}" width="60" height="6" rx="3" fill="{heading}" opacity=".7"/>'
            + lines(x+20, y+92, [w-40, w-60, w-50])
            + f'<rect x="{x+20}" y="{y+142}" width="70" height="6" rx="3" fill="{heading}" opacity=".7"/>'
            + lines(x+30, y+156, [w-60, w-80, w-70, w-90])
            + f'<rect x="{x+20}" y="{y+222}" width="40" height="6" rx="3" fill="{heading}" opacity=".7"/>'
            + ''.join(f'<rect x="{x+20+i*52}" y="{y+236}" width="44" height="14" rx="7" fill="{TINT}"/>' for i in range(3)))

def badge(x, y, text, fill=AMBER, ink=INK, w=None):
    w = w or 14 + len(text) * 8
    return f'<rect x="{x}" y="{y}" width="{w}" height="30" rx="15" fill="{fill}"/><text x="{x+w/2}" y="{y+20}" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="13" font-weight="700" fill="{ink}">{text}</text>'

def ring(cx, cy, pct, label):
    import math
    r = 34; c = 2 * math.pi * r
    return (f'<g filter="url(#s)"><circle cx="{cx}" cy="{cy}" r="52" fill="#fff"/></g><circle cx="{cx}" cy="{cy}" r="{r}" fill="none" stroke="{LINE}" stroke-width="9"/>'
            f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="none" stroke="{TEAL}" stroke-width="9" stroke-linecap="round" stroke-dasharray="{c*pct/100:.1f} {c:.1f}" transform="rotate(-90 {cx} {cy})"/>'
            f'<text x="{cx}" y="{cy+6}" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="18" font-weight="700" fill="{INK}">{label}</text>')

def checklist(x, y, items):
    out = f'<g filter="url(#s)"><rect x="{x}" y="{y}" width="190" height="{30+len(items)*30}" rx="12" fill="#fff"/></g>'
    for i, (ok, w) in enumerate(items):
        cy = y + 28 + i * 30
        col = TEAL if ok == 1 else ('#B45309' if ok == 0 else '#DC2626')
        out += f'<circle cx="{x+22}" cy="{cy}" r="8" fill="{col}"/>' + f'<rect x="{x+38}" y="{cy-4}" width="{w}" height="8" rx="4" fill="{LINE}"/>'
    return out

cards = {
    'resume-example': (doc(60, 50) + badge(300, 70, 'SAMPLE', TEAL, '#fff') + checklist(290, 120, [(1, 110), (1, 90), (1, 120), (0, 80)]), 'Sample resume with summary, experience and skills'),
    'resume-examples': (doc(40, 70, 190, 260) + doc(160, 50, 190, 260, NAVY2) + doc(290, 30, 190, 260), 'Stack of resume examples'),
    'resume-template': (doc(150, 45) + badge(380, 70, 'ATS', TEAL, '#fff', 60) + badge(30, 290, 'Live preview', AMBER, INK), 'Resume template preview'),
    'cover-letter': ((f'<g filter="url(#s)"><rect x="120" y="40" width="280" height="310" rx="12" fill="#fff"/></g>' + lines(150, 72, [120], h=8, color=NAVY)
                      + lines(150, 104, [220, 210, 225, 180], gap=16) + lines(150, 182, [225, 215, 220, 200, 160], gap=16) + lines(150, 276, [220, 140], gap=16)
                      + lines(150, 318, [90], h=8, color=NAVY)), 'Cover letter with three short paragraphs'),
    'interview': (''.join(f'<g filter="url(#s)"><rect x="{70+i*18}" y="{60+i*70}" width="300" height="56" rx="14" fill="#fff"/></g><circle cx="{100+i*18}" cy="{88+i*70}" r="12" fill="{[NAVY,TEAL,AMBER][i]}"/>' + lines(124+i*18, 80+i*70, [200, 150], gap=14) for i in range(3)) + badge(330, 300, 'STAR', NAVY, '#fff', 70), 'Interview questions with answer tips'),
    'india': (''.join(f'<g filter="url(#s)"><circle cx="{x}" cy="{y}" r="{r}" fill="#fff"/></g><circle cx="{x}" cy="{y}" r="{r*0.45}" fill="{c}"/>' for x, y, r, c in [(150, 120, 26, NAVY), (250, 90, 20, TEAL), (330, 160, 24, AMBER), (210, 220, 22, NAVY), (300, 280, 18, TEAL), (140, 300, 16, AMBER), (390, 250, 16, NAVY)])
              + f'<path d="M150 120 L250 90 L330 160 L210 220 L300 280 M210 220 L140 300 M330 160 L390 250" stroke="{NAVY}" stroke-width="2" stroke-dasharray="4 6" fill="none" opacity=".5"/>', 'Cities across India linked by resume guides'),
    'city': (f'<g opacity=".9">' + ''.join(f'<rect x="{x}" y="{390-60-h}" width="{w}" height="{h}" rx="4" fill="{c}"/>' for x, w, h, c in [(40, 50, 120, NAVY2), (95, 40, 170, NAVY), (140, 60, 90, '#3F51B5'), (205, 45, 200, NAVY2), (255, 55, 130, NAVY), (315, 40, 160, '#3F51B5'), (360, 60, 110, NAVY2), (425, 45, 150, NAVY)]) + '</g>'
             + f'<rect x="0" y="330" width="520" height="60" fill="{NAVY2}" opacity=".15"/>' + doc(300, 40, 170, 220) + badge(40, 40, 'Local tips', AMBER, INK), 'City skyline with a local resume guide'),
    'words': (badge(60, 90, 'helped', '#FEE2E2', '#DC2626', 110) + f'<path d="M185 105 h60" stroke="{NAVY}" stroke-width="3" marker-end="url(#a)"/>' + badge(260, 90, 'delivered', TEAL, '#fff', 130)
              + badge(60, 170, 'responsible for', '#FEE2E2', '#DC2626', 150) + f'<path d="M225 185 h50" stroke="{NAVY}" stroke-width="3"/>' + badge(290, 170, 'owned', TEAL, '#fff', 90)
              + badge(60, 250, 'worked on', '#FEE2E2', '#DC2626', 120) + f'<path d="M195 265 h55" stroke="{NAVY}" stroke-width="3"/>' + badge(265, 250, 'built', TEAL, '#fff', 80), 'Weak resume words swapped for stronger ones'),
    'ats': (doc(50, 50) + ring(390, 120, 78, '78%') + checklist(300, 200, [(1, 100), (0, 80), (-1, 110)]), 'ATS report with match score and missing keywords'),
}
OUT.mkdir(parents=True, exist_ok=True)
for name, (body, title) in cards.items():
    (OUT / f'{name}.svg').write_text(frame(body, title), encoding='utf8')
print('wrote', len(cards), 'images to', OUT)

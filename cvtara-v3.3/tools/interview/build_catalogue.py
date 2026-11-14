"""Builds app/resources/data/interview-catalogue.json from roles.py and patterns.py.

Run from cvtara-v3.3:  python3 tools/interview/build_catalogue.py
The app generates each question from this file only when it is needed (Services/InterviewBank.php), so the 5 lakh+
questions never sit in the database until a customer practises one.
"""
import json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from roles import FAMILIES  # noqa: E402
from patterns import CATEGORIES, TIERS, TIER_TIP, SKILL_PATTERNS, ROLE_PATTERNS  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(HERE))
OUT = os.path.join(ROOT, 'app', 'resources', 'data', 'interview-catalogue.json')
BEHAV = re.compile(r'^(Tell me about|Describe|Give an example|Share|Have you|What is the achievement|What is your biggest achievement)')
SITU = re.compile(r'^(If |Suppose|Imagine|You |While |Your manager|How would you)')
TIER_NAMES = list(TIERS)


def slugify(s):
    return re.sub(r'-+', '-', re.sub(r'[^a-z0-9]+', '-', s.lower())).strip('-')[:80]


def category(default, text, tier):
    if default in ('hr', 'integrity', 'customer', 'motivation'):
        return default
    if default == 'skills' and tier == 'senior' and re.search(r'\b(your team|team members|lead|led)\b', text):
        return 'leadership'
    if BEHAV.match(text):
        return 'behavioural'
    if SITU.match(text):
        return 'situational'
    return 'leadership' if tier == 'senior' and re.search(r'\byour team\b', text) else default


def patterns(rows, kind):
    out = []
    for code, comp, cat, e, m, s, guide in rows:
        texts = {'entry': e, 'mid': m, 'senior': s}
        for t in texts.values():
            assert ('{skill}' in t or '{Skill}' in t) == (kind == 'skill'), (code, t)
        out.append({'code': code, 'kind': kind, 'competency': comp, 'text': texts, 'guidance': guide,
                    'category': {t: category(cat, texts[t], t) for t in TIER_NAMES}})
    return out


def main():
    seen, roles = set(), []
    for fi, f in enumerate(FAMILIES):
        assert len(f['skills']) == 8, f['name']
        for j, (name, slug, syn, own) in enumerate(f['roles']):
            slug = slug or slugify(name)
            assert slug not in seen, slug
            seen.add(slug)
            assert len(own) == 4 and len(set(own)) == 4, name
            skills = own + [f['skills'][(j + k) % 8] for k in range(6)]
            for sk in skills:
                assert sk == sk.strip() and sk[0].islower() and not sk.endswith('.'), (name, sk)
            roles.append({'slug': slug, 'name': name, 'family': fi, 'synonyms': syn, 'skills': skills})
    sp, rp = patterns(SKILL_PATTERNS, 'skill'), patterns(ROLE_PATTERNS, 'role')
    total = len(roles) * 10 * len(TIERS) * len(sp) + len(roles) * len(TIERS) * len(rp)
    data = {'version': '2026-11-14', 'source': 'CVTara original question patterns', 'rights': 'original',
            'note': 'Generated questions: role x skill x experience tier x pattern. Wording written for CVTara; no question copied from other sites.',
            'categories': CATEGORIES, 'tiers': TIERS, 'tierTip': TIER_TIP,
            'families': [{'name': f['name'], 'industry': f['industry'], 'domain': f['domain']} for f in FAMILIES],
            'roles': roles, 'patterns': sp + rp, 'total': total}
    with open(OUT, 'w') as fh:
        json.dump(data, fh, ensure_ascii=False, separators=(',', ':'))
        fh.write('\n')
    print(f'{len(FAMILIES)} families, {len(roles)} roles, {len(sp)} skill + {len(rp)} role patterns, {total:,} questions -> {os.path.relpath(OUT, ROOT)}')


if __name__ == '__main__':
    main()

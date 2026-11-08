#!/usr/bin/env python3
"""Builds public_html/role-library.json for the guided template picker from app/resources/data/seo-pages.json
and the search words in role_library.py.

  python3 tools/seo/build_role_library.py      (build_seo_data.py also runs it)

Every role and category the site publishes is included, so the picker can match any job title a visitor types."""
import json, sys
from pathlib import Path
HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
sys.path.insert(0, str(HERE))
from role_library import TITLES, ROLE_SYNONYMS, PROFILE_ROLES, INDUSTRY_META, CATALOG_CATEGORIES, DEGREES


def library():
    """The picker's role library as a dict (also used by the tests to check the shipped file is current)."""
    seo = json.loads((ROOT / 'app' / 'resources' / 'data' / 'seo-pages.json').read_text(encoding='utf8'))
    errors = []
    industries, roles = {}, {}
    for key, cat in seo['categories'].items():
        meta = INDUSTRY_META.get(key)
        if not meta: errors.append(f'category {key}: add it to INDUSTRY_META'); continue
        syn = list(meta['syn']) + [n.lower() for n, k in CATALOG_CATEGORIES.items() if k == key]
        industries[key] = dict(n=cat['n'], syn=syn, kind=meta['kind'], degree=meta['degree'], prev=meta['prev'], roles=[])
    for key, r in seo['roles'].items():
        if key not in ROLE_SYNONYMS: errors.append(f'role {key}: add search words to ROLE_SYNONYMS')
        roles[key] = dict(t=TITLES.get(key, r['t']), c=r['c'], s=r['s'], b=r['b'][:4], k=r['k'][:8], syn=ROLE_SYNONYMS.get(key, []), **({'d': DEGREES[key]} if key in DEGREES else {}))
        industries[r['c']]['roles'].append(key)
    for key, r in PROFILE_ROLES.items():
        if key in roles: errors.append(f'profile role {key} clashes with a page role')
        if r['c'] not in industries: errors.append(f'profile role {key}: unknown category {r["c"]}')
        roles[key] = dict(t=r['t'], c=r['c'], p=r['p'], syn=r['syn'])
        industries[r['c']]['roles'].append(key)
    for key in list(ROLE_SYNONYMS) + list(DEGREES):
        if key not in seo['roles']: errors.append(f'ROLE_SYNONYMS has unknown role {key}')
    styles = {k: dict(n=t['n']) for k, t in seo['templates'].items()}
    text = json.dumps([industries, roles], ensure_ascii=False)
    if '!' in text: errors.append('copy rule: no exclamation marks')
    if errors:
        print('\n'.join(errors)); sys.exit(1)
    return dict(version=1, industries=industries, roles=roles, styles=styles, categories=CATALOG_CATEGORIES)


def build():
    out = library()
    roles, industries, styles = out['roles'], out['industries'], out['styles']
    dest = ROOT / 'public_html' / 'role-library.json'
    dest.write_text(json.dumps(out, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf8')
    print(f'wrote {dest.relative_to(ROOT)}: {len(roles)} roles, {len(industries)} industries, {len(styles)} styles')


if __name__ == '__main__':
    build()

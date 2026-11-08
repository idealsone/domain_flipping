#!/usr/bin/env python3
"""Builds app/resources/data/seo-pages.json from the content modules in this folder.

  python3 tools/seo/build_seo_data.py

Edit the .py files here, run this script, commit both. The app reads only the JSON."""
import json, sys
from pathlib import Path
HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
from roles_a import ROLES as A
from roles_b import ROLES as B
from roles_c import ROLES as C
from categories import CATEGORIES
from templates_places import TEMPLATES, STATES, CITIES, CITY_ROLES, TIER2_ROLES, FRESHER_NOTES, TIER2_NOTES, STATE_LANG
from words import WORDS

roles = {**A, **B, **C}
assert len(roles) == len(A) + len(B) + len(C), 'duplicate role slug'
errors = []
for slug, r in roles.items():
    if r['c'] not in CATEGORIES: errors.append(f'{slug}: unknown category {r["c"]}')
    if len(r['q']) < 5: errors.append(f'{slug}: needs 5 interview questions')
for city, rs in CITY_ROLES.items():
    if city not in CITIES: errors.append(f'city roles: unknown city {city}')
    for slug, (key, note) in rs.items():
        if key not in roles: errors.append(f'{city}/{slug}: unknown role {key}')
for slug, c in CITIES.items():
    if c['tier'] == 2 and c['sp'][0] not in roles: errors.append(f'{slug}: unknown specialty role')
for slug, s in STATES.items():
    for c in s['cities']:
        if c not in CITIES: errors.append(f'state {slug}: unknown city {c}')
for c in CITIES:
    if c not in FRESHER_NOTES: errors.append(f'{c}: needs a freshers note')
for c, rs in TIER2_NOTES.items():
    for r in rs:
        if c not in CITIES or r not in TIER2_ROLES: errors.append(f'tier 2 note {c}/{r} unknown')
for s_ in STATES:
    if s_ not in STATE_LANG: errors.append(f'{s_}: needs a language')
for r in TIER2_ROLES:
    if r not in roles: errors.append(f'tier 2 role {r} unknown')
text = json.dumps([roles, CATEGORIES, TEMPLATES, STATES, CITIES, CITY_ROLES, WORDS, FRESHER_NOTES, TIER2_NOTES], ensure_ascii=False)
if '!' in text: errors.append('copy rule: no exclamation marks')
if errors:
    print('\n'.join(errors)); sys.exit(1)
out = dict(version=1, roles=roles, categories=CATEGORIES, templates=TEMPLATES, states={k: {**v, 'lang': STATE_LANG[k]} for k, v in STATES.items()}, cities={k: {**v, 'fn': FRESHER_NOTES[k]} for k, v in CITIES.items()},
           cityRoles={c: {s: {'role': k, 'note': n} for s, (k, n) in rs.items()} for c, rs in CITY_ROLES.items()},
           tier2Roles=TIER2_ROLES, tier2Notes=TIER2_NOTES, words=WORDS)
dest = HERE.parent.parent / 'app' / 'resources' / 'data' / 'seo-pages.json'
dest.write_text(json.dumps(out, ensure_ascii=False, indent=1) + '\n', encoding='utf8')
print(f'wrote {dest.relative_to(HERE.parent.parent)}: {len(roles)} roles, {len(CATEGORIES)} categories, {len(TEMPLATES)} templates, {len(STATES)} states, {len(CITIES)} cities, {len(WORDS)} words')
from build_role_library import build as build_role_library
build_role_library()

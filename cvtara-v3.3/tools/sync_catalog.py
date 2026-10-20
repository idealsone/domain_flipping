#!/usr/bin/env python3
"""Copies the candidate-type designs, cover letters and sample profiles from public_html/data.js into
app/resources/data/catalog.json, which the server uses to validate template slugs and pick defaults.

  python3 tools/sync_catalog.py          (needs node)

Existing catalogue entries are left exactly as they are; only entries with an `audience`, new sample profiles
and the `audiences` list are added or refreshed."""
import json, subprocess, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parent.parent
DUMP = r"""
const fs=require('fs'),vm=require('vm'),path=require('path');const pub=process.argv[1];
const ctx={console};ctx.window=ctx;vm.createContext(ctx);
for(const f of ['data.js','reference-templates.js','premium-collection.js'])vm.runInContext(fs.readFileSync(path.join(pub,f),'utf8'),ctx,{filename:f});
const D=ctx.CVTARA_DATA;process.stdout.write(JSON.stringify({templates:D.templates,coverLetters:D.coverLetters,resumeProfiles:D.resumeProfiles,audiences:D.audiences}));
"""


def runtime():
    out = subprocess.run(["node", "-e", DUMP, str(ROOT / "public_html")], capture_output=True, text=True, check=True).stdout
    return json.loads(out)


def merged():
    path = ROOT / "app" / "resources" / "data" / "catalog.json"
    cat = json.loads(path.read_text(encoding="utf8"))
    rt = runtime()
    for key in ("templates", "coverLetters"):
        fresh = [x for x in rt[key] if x.get("audience")]
        slugs = {x["slug"] for x in fresh}
        cat[key] = [x for x in cat[key] if x["slug"] not in slugs] + fresh
    shared = {t["profile"] for t in rt["templates"] if not t.get("audience")}
    for k, v in rt["resumeProfiles"].items():
        if k not in cat["resumeProfiles"] or k not in shared:  # profiles used only by the candidate designs follow data.js
            cat["resumeProfiles"][k] = v
    cat["audiences"] = rt["audiences"]
    return path, cat


if __name__ == "__main__":
    path, cat = merged()
    text = json.dumps(cat, ensure_ascii=False, separators=(",", ":"))
    path.write_text(text, encoding="utf8")
    print(f"catalog.json: {len(cat['templates'])} designs, {len(cat['coverLetters'])} letters, {len(cat['resumeProfiles'])} sample profiles")
    sys.exit(0)

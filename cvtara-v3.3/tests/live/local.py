"""Start a throw-away local CVTara (production mode, SQLite) with the QA accounts, for developing/trying the personas.
Usage: python3 tests/live/local.py  -> prints BASE and writes tests/live/.accounts.local.json; Ctrl+C stops it."""
import json, os, subprocess, sys, time
HERE = os.path.dirname(os.path.abspath(__file__)); ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(ROOT, 'tests'))
from lib.env import Instance
inst = Instance(os.path.join(ROOT, 'app'), os.path.join(ROOT, 'public_html'), preview=False).start()
r = subprocess.run(['php', os.path.join(HERE, 'server', 'qa_setup.php')], cwd=inst.app, env=dict(inst.env, QA_APP=inst.app), capture_output=True, text=True)
out = r.stdout + r.stderr
if 'QAJSON ' not in out: print(out[-3000:]); inst.stop(); sys.exit(1)
acc = json.loads(out.split('QAJSON ', 1)[1].split(' QAEND', 1)[0])
json.dump(acc, open(os.path.join(HERE, '.accounts.local.json'), 'w'), indent=1)
print('BASE', inst.base, 'APP', inst.app, flush=True)
try:
    while True: time.sleep(5)
except KeyboardInterrupt: pass
finally: inst.stop()

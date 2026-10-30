#!/bin/bash
# CVTara analytics consent banner fix (PR #29): replaces public_html/site-polish.css only; approved by the owner 2026-10-30 06:20 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/dga.log; mkdir -p $W
if [ -f $W/dga.done ] || [ -f $W/dga.lock ]; then cat $L; exit 0; fi
touch $W/dga.lock; exec >>$L 2>&1; echo "== start $(date -u)"
F=$H/public_html/site-polish.css
cur=$(sha256sum $F | cut -c1-64)
[ "$cur" = "bb51fe06e7c7343adaab7266725f92a905748b1f31a51c91d36c3afd06b9f94c" ] || { echo "ABORT live site-polish.css differs ($cur); nothing changed"; touch $W/dga.done; rm -f $W/dga.lock; exit 1; }
cd $W && curl -fsSL https://raw.githubusercontent.com/idealsone/domain_flipping/c495eff0efe927edf6874b243d34eeb11c2d9ab8/cvtara-v3.3/public_html/site-polish.css -o site-polish.ga.css && curl -fsSL https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-ga-consent/rebuild.php -o rebuildga.php || { echo "ABORT download"; touch $W/dga.done; rm -f $W/dga.lock; exit 1; }
echo "25c4c5ad7345ccad849134460ab2376a8bab6d1983422c7696e66861a996f770  site-polish.ga.css" | sha256sum -c - || { echo "ABORT checksum"; touch $W/dga.done; rm -f $W/dga.lock; exit 1; }
B=$W/backup_ga_$(date -u +%Y%m%d_%H%M); mkdir -p $B && cp -a $F $B/site-polish.css && echo "backup $B"
cp $W/site-polish.ga.css $F && sha256sum $F
cd $H && php $W/rebuildga.php
for u in / /templates.html /contact.html; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "== done $(date -u)"; touch $W/dga.done; rm -f $W/dga.lock

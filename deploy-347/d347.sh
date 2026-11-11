#!/bin/bash
# CVTara 3.47.0 overlay on 3.46.1 (PR #49, owner-reported fixes). Owner typed "deploy 3.47.0" on 2026-11-11. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d347.log; mkdir -p $W
if [ -f $W/d347.done ] || [ -f $W/d347.lock ]; then tail -c 3500 $L; exit 0; fi
touch $W/d347.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/1ba05497cd690c69e89ee0fe415aebb0b55e1593/deploy-347
cd $W && curl -fsSL "$SRC/cvt347.tgz" -o cvt347.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild347.php && curl -fsSL "$SRC/checks.txt" -o checks347.txt || { echo "ABORT download"; touch $W/d347.done; exit 1; }
echo "11a27d7c2e407113aea46b19f10eac68841a2ad82d27a59da08120297ea42d9e  cvt347.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d347.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then echo "MISMATCH $p $cur"; bad=1; fi; done < $W/checks347.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.46.1"' || { echo "MISMATCH live version"; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d347.done; rm -f $W/d347.lock; exit 1; }
B=$W/backup_347_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt347.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$') $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d347.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d347.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x347 && tar -xzf $W/cvt347.tgz -C $W/x347 || { echo "ABORT extract"; touch $W/d347.done; exit 1; }
bad=0; for f in $(cd $W/x347 && find cvtara_app -name '*.php'); do php -l $W/x347/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d347.done; exit 1; }
cp -a $W/x347/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x347/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x347/BUILD_INFO.json $b; done
echo "copied $(find $W/x347 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild347.php
for u in / /login /workspace.html /admin.html /guides/resume/no-experience-format/ /crash-guard.js /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 600 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x347; touch $W/d347.done; rm -f $W/d347.lock

#!/bin/bash
# CVTara 3.64.0 overlay on 3.61.0 (PR #53, AI cost cuts). Owner typed "deploy 3.64.0" on 2026-11-12. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d364.log; mkdir -p $W
if [ -f $W/d364.done ] || [ -f $W/d364.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d364.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/409d33a11b8918cb66f07a66eab36ed0ff0288b2/deploy-364
cd $W && curl -fsSL "$SRC/cvt364.tgz" -o cvt364.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild364.php && curl -fsSL "$SRC/checks.txt" -o checks364.txt || { echo "ABORT download"; touch $W/d364.done; exit 1; }
echo "c7943e30159604a5bd11d2e21d3cdfceff706e7ba0f2b22f3d59db7e8016991b  cvt364.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d364.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then echo "MISMATCH $p $cur"; bad=1; fi; done < $W/checks364.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.61.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d364 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d364.done; rm -f $W/d364.lock; exit 1; }
B=$W/backup_364_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt364.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d364.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d364.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x364 && tar -xzf $W/cvt364.tgz -C $W/x364 || { echo "ABORT extract"; touch $W/d364.done; exit 1; }
bad=0; for f in $(cd $W/x364 && find cvtara_app -name '*.php'); do php -l $W/x364/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d364.done; exit 1; }
cp -a $W/x364/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x364/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x364/BUILD_INFO.json $b; done
echo "copied $(find $W/x364 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; echo "migrate:"; php artisan migrate --force 2>&1 | tail -25; cd $H
php $W/rebuild364.php
for u in / /login /workspace.html /admin.html /tara-chat.js /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x364; touch $W/d364.done; rm -f $W/d364.lock

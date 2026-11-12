#!/bin/bash
# CVTara 3.61.0 overlay on 3.60.0 (PR #50, includes 3.60.1). Owner typed "deploy 3.61.0" on 2026-11-12. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d361.log; mkdir -p $W
if [ -f $W/d361.done ] || [ -f $W/d361.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d361.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/d6c900f143ffe40bafdee7e4756161b153908859/deploy-361
cd $W && curl -fsSL "$SRC/cvt361.tgz" -o cvt361.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild361.php && curl -fsSL "$SRC/checks.txt" -o checks361.txt || { echo "ABORT download"; touch $W/d361.done; exit 1; }
echo "ea926a577be45ffaa7c7b5a2ff2057d11778eab828a8cf1f2a4e559d8262f349  cvt361.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d361.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then echo "MISMATCH $p $cur"; bad=1; fi; done < $W/checks361.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.60.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d361 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d361.done; rm -f $W/d361.lock; exit 1; }
B=$W/backup_361_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt361.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d361.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d361.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x361 && tar -xzf $W/cvt361.tgz -C $W/x361 || { echo "ABORT extract"; touch $W/d361.done; exit 1; }
bad=0; for f in $(cd $W/x361 && find cvtara_app -name '*.php'); do php -l $W/x361/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d361.done; exit 1; }
cp -a $W/x361/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x361/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x361/BUILD_INFO.json $b; done
echo "copied $(find $W/x361 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; echo "migrate:"; php artisan migrate --force 2>&1 | tail -25; cd $H
php $W/rebuild361.php
for u in / /login /workspace.html /admin.html /customer-guide.html /interview-practice.js /primary-resume.js /workspace-ui.js /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x361; touch $W/d361.done; rm -f $W/d361.lock

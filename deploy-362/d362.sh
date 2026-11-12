#!/bin/bash
# CVTara 3.62.0 overlay on 3.61.0 (PR #52). Owner asked on 2026-11-12 in the thread to do it and complete it. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d362.log; mkdir -p $W
if [ -f $W/d362.done ] || [ -f $W/d362.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d362.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/12a4aea9b18259f395f79d7e6d787d082c81bcfd/deploy-362
cd $W && curl -fsSL "$SRC/cvt362.tgz" -o cvt362.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild362.php && curl -fsSL "$SRC/checks.txt" -o checks362.txt || { echo "ABORT download"; touch $W/d362.done; exit 1; }
echo "f297dc0f13cbbac106230b5bc0498aa31acc4a84f253d4047e19f82ef5740528  cvt362.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d362.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then echo "MISMATCH $p $cur"; bad=1; fi; done < $W/checks362.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.61.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d362 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d362.done; rm -f $W/d362.lock; exit 1; }
B=$W/backup_362_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt362.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d362.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d362.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x362 && tar -xzf $W/cvt362.tgz -C $W/x362 || { echo "ABORT extract"; touch $W/d362.done; exit 1; }
bad=0; for f in $(cd $W/x362 && find cvtara_app -name '*.php'); do php -l $W/x362/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d362.done; exit 1; }
cp -a $W/x362/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x362/BUILD_INFO.json $b; done
echo "copied $(find $W/x362 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild362.php
for u in / /about.html /privacy-policy /about-us /login /workspace.html /admin.html /sitemap.xml /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "home org: $(curl -s https://cvtara.com/ | grep -o '#organization' | head -1)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x362; touch $W/d362.done; rm -f $W/d362.lock

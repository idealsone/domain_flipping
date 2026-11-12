#!/bin/bash
# CVTara 3.60.0 overlay on 3.47.0 (PR #50: releases 3.48.0 to 3.60.0). Owner typed "deploy all the version" on 2026-11-12. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d360.log; mkdir -p $W
if [ -f $W/d360.done ] || [ -f $W/d360.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d360.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/5e334b8f45eac67f976e8150678a148f5bf7463c/deploy-360
cd $W && curl -fsSL "$SRC/cvt360.tgz" -o cvt360.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild360.php && curl -fsSL "$SRC/checks.txt" -o checks360.txt || { echo "ABORT download"; touch $W/d360.done; exit 1; }
echo "29c75024188994e8d71f3052553fe31165e77c83c0a9ffd9e6069e8487c8dd37  cvt360.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d360.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then echo "MISMATCH $p $cur"; bad=1; fi; done < $W/checks360.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.47.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d360 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d360.done; rm -f $W/d360.lock; exit 1; }
B=$W/backup_360_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt360.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d360.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d360.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x360 && tar -xzf $W/cvt360.tgz -C $W/x360 || { echo "ABORT extract"; touch $W/d360.done; exit 1; }
bad=0; for f in $(cd $W/x360 && find cvtara_app -name '*.php'); do php -l $W/x360/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d360.done; exit 1; }
cp -a $W/x360/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x360/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x360/BUILD_INFO.json $b; done
echo "copied $(find $W/x360 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; echo "migrate:"; php artisan migrate --force 2>&1 | tail -25; cd $H
php $W/rebuild360.php
for u in / /login /workspace.html /admin.html /partners.html /about.html /guides/resume/no-experience-format/ /interview-questions/ /crash-guard.js /interview-practice.js /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x360; touch $W/d360.done; rm -f $W/d360.lock

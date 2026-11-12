#!/bin/bash
# CVTara 3.65.0 overlay on 3.64.0 (PR #52, brand search; built as 3.62.0). Owner chose "Yes, deploy 3.62.0" on 2026-11-12 (now numbered 3.65.0). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d365b.log; mkdir -p $W
if [ -f $W/d365b.done ] || [ -f $W/d365b.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d365b.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/9a33229d68c467d346140862783ee43bccfedd0c/deploy-365
cd $W && curl -fsSL "$SRC/cvt365.tgz" -o cvt365.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild365.php && curl -fsSL "$SRC/checks.txt" -o checks365.txt || { echo "ABORT download"; touch $W/d365b.done; exit 1; }
echo "f45cc3bd0b3c7b9f3dd7a68d5dde9ff0bbdc36f5ef3c3ccc9ce4429ee61d9dc8  cvt365.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d365b.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then echo "MISMATCH $p $cur"; bad=1; fi; done < $W/checks365.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.64.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d365 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d365b.done; rm -f $W/d365b.lock; exit 1; }
B=$W/backup_365_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt365.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d365b.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d365b.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x365 && tar -xzf $W/cvt365.tgz -C $W/x365 || { echo "ABORT extract"; touch $W/d365b.done; exit 1; }
bad=0; for f in $(cd $W/x365 && find cvtara_app -name '*.php'); do php -l $W/x365/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d365b.done; exit 1; }
cp -a $W/x365/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x365/BUILD_INFO.json $b; done
echo "copied $(find $W/x365 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild365.php
for u in / /about.html /privacy-policy /about-us /login /workspace.html /admin.html /sitemap.xml /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "home org: $(curl -s https://cvtara.com/ | grep -o '#organization' | head -1)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x365; touch $W/d365b.done; rm -f $W/d365b.lock

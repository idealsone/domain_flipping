#!/bin/bash
# CVTara 3.71.0 overlay on 3.70.2 (personal mock interviews; PR #59). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3710.log; mkdir -p $W
if [ -f $W/d3710.done ] || [ -f $W/d3710.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d3710.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/8f1a99f91b884009dbd3eac2280b0d52b97a4852/deploy-3710
cd $W && curl -fsSL "$SRC/cvt3710.tgz" -o cvt3710.tgz && curl -fsSL "$SRC/checks.txt" -o checks3710.txt && curl -fsSL "$SRC/rebuild.php" -o rebuild3710.php || { echo "ABORT download"; touch $W/d3710.done; exit 1; }
echo "1153a32d217836cf521b60a2a19cf5ef993ee1bb5181dfe61c022b2e7115bf8f  cvt3710.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3710.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then if [ "$k" = W ]; then echo "NOTE minified differs $p $cur (backed up)"; else echo "MISMATCH $p $cur"; bad=1; fi; fi; done < $W/checks3710.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.70.2"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d3710 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3710.done; rm -f $W/d3710.lock; exit 1; }
B=$W/backup_3710_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt3710.tgz | grep -v '/$' | sed 's#^\./##' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3710.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3710.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3710 && tar -xzf $W/cvt3710.tgz -C $W/x3710 || { echo "ABORT extract"; touch $W/d3710.done; exit 1; }
bad=0; for f in $(cd $W/x3710 && find cvtara_app -name '*.php'); do php -l $W/x3710/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d3710.done; exit 1; }
cp -a $W/x3710/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x3710/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3710/BUILD_INFO.json $b; done
echo "copied $(find $W/x3710 -type f | wc -l) files"
cd cvtara_app && php artisan migrate --force 2>&1 | tail -5; php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild3710.php
for u in / /register /login /admin.html /workspace.html /interview-practice.js /api/health /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' https://cvtara.com$u)"; done
echo "switch choice on register: $(curl -s https://cvtara.com/register | grep -o 'switching to a new kind of job' | wc -l)"
echo "practice panel in js: $(curl -s https://cvtara.com/interview-practice.js | grep -o 'Practice panel' | wc -l)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3710; touch $W/d3710.done; rm -f $W/d3710.lock

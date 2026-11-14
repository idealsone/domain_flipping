#!/bin/bash
# CVTara 3.76.0 overlay on 3.75.1 (conversion release; PR #62). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3760.log; mkdir -p $W
if [ -f $W/d3760.done ] || [ -f $W/d3760.lock ]; then tail -c 6000 $L; exit 0; fi
touch $W/d3760.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/eb546b82b937174e31f139e4c4dee70ed743caf9/deploy-3760
cd $W && curl -fsSL "$SRC/cvt3760.tgz" -o cvt3760.tgz && curl -fsSL "$SRC/checks.txt" -o checks3760.txt && curl -fsSL "$SRC/rebuild.php" -o rebuild3760.php || { echo "ABORT download"; touch $W/d3760.done; exit 1; }
echo "d4387dff26b12915b283b974ea2d9f0898c3dfa118eddc6a819ddc2fe859d99d  cvt3760.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3760.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then if [ "$k" = W ]; then echo "NOTE minified differs $p $cur (backed up)"; else echo "MISMATCH $p $cur"; bad=1; fi; fi; done < $W/checks3760.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.75.1"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d3760 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3760.done; rm -f $W/d3760.lock; exit 1; }
B=$W/backup_3760_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt3760.tgz | grep -v '/$' | sed 's#^\./##' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3760.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3760.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3760 && tar -xzf $W/cvt3760.tgz -C $W/x3760 || { echo "ABORT extract"; touch $W/d3760.done; exit 1; }
bad=0; for f in $(cd $W/x3760 && find cvtara_app -name '*.php'); do php -l $W/x3760/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d3760.done; exit 1; }
cp -a $W/x3760/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3760/BUILD_INFO.json $b; done
echo "copied $(find $W/x3760 -type f | wc -l) files"
cd cvtara_app && php artisan migrate --force 2>&1 | tail -5; php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild3760.php
echo "pricing leads with Job Hunt: $(curl -s https://cvtara.com/pricing/ | grep -c 'Most popular')"
echo "home trust line: $(curl -s https://cvtara.com/ | grep -c 'stHunt')"
for u in / /contact.html /admin.html /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3760; touch $W/d3760.done; rm -f $W/d3760.lock

#!/bin/bash
# CVTara 3.69.0 overlay on 3.68.0 (sample interview answers, more resume-word and cover-letter content, 60-day sprint, one-hop /pricing/ redirect; PR #56 follow-up). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d369.log; mkdir -p $W
if [ -f $W/d369.done ] || [ -f $W/d369.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d369.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/2f6f565b2587f2154fcdf1ce13f804587215bdb3/deploy-369
cd $W && curl -fsSL "$SRC/cvt369.tgz" -o cvt369.tgz && curl -fsSL "$SRC/checks.txt" -o checks369.txt && curl -fsSL "$SRC/rebuild.php" -o rebuild369.php || { echo "ABORT download"; touch $W/d369.done; exit 1; }
echo "353c32d0e520ba4e662dc9a33dd6e7e3d601b5516df0ca3aaad5b038dbd7ab06  cvt369.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d369.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then if [ "$k" = W ]; then echo "NOTE minified differs $p $cur (backed up)"; else echo "MISMATCH $p $cur"; bad=1; fi; fi; done < $W/checks369.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.68.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d369 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d369.done; rm -f $W/d369.lock; exit 1; }
B=$W/backup_369_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt369.tgz | grep -v '/$' | sed 's#^\./##' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d369.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d369.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x369 && tar -xzf $W/cvt369.tgz -C $W/x369 || { echo "ABORT extract"; touch $W/d369.done; exit 1; }
bad=0; for f in $(cd $W/x369 && find cvtara_app -name '*.php'); do php -l $W/x369/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d369.done; exit 1; }
cp -a $W/x369/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x369/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x369/BUILD_INFO.json $b; done
echo "copied $(find $W/x369 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild369.php
for u in / /pricing/ /pricing.html /plans /interview-questions/hair-stylist/ /resume-words/leading/ /cover-letter-examples/transportation/ /resume-examples/ /login /admin.html /sitemap.xml /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' https://cvtara.com$u)"; done
echo "sample answers: $(curl -s https://cvtara.com/interview-questions/hair-stylist/ | grep -o sp-sample-answer | wc -l)"
echo "word examples: $(curl -s https://cvtara.com/resume-words/leading/ | grep -c more-examples)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x369; touch $W/d369.done; rm -f $W/d369.lock

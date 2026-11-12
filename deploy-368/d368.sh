#!/bin/bash
# CVTara 3.68.0 overlay on 3.67.2 (one pricing page, Updated dates, 30-day SEO sprint, approval detail, sitemap lastmod; PR #56). Owner typed "deploy 3.68.0". Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d368.log; mkdir -p $W
if [ -f $W/d368.done ] || [ -f $W/d368.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d368.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/b5f0269e2656ef72e4c1242aa498a43272bf2305/deploy-368
cd $W && curl -fsSL "$SRC/cvt368.tgz" -o cvt368.tgz && curl -fsSL "$SRC/checks.txt" -o checks368.txt && curl -fsSL "$SRC/rebuild.php" -o rebuild368.php || { echo "ABORT download"; touch $W/d368.done; exit 1; }
echo "34d9999fb3e82827f7d045cc8228c4e7b50cafd75d2976687906c24880fc9dd2  cvt368.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d368.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then if [ "$k" = W ]; then echo "NOTE minified differs $p $cur (backed up)"; else echo "MISMATCH $p $cur"; bad=1; fi; fi; done < $W/checks368.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.67.2"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d368 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d368.done; rm -f $W/d368.lock; exit 1; }
B=$W/backup_368_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt368.tgz | grep -v '/$' | sed 's#^\./##' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d368.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d368.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x368 && tar -xzf $W/cvt368.tgz -C $W/x368 || { echo "ABORT extract"; touch $W/d368.done; exit 1; }
bad=0; for f in $(cd $W/x368 && find cvtara_app -name '*.php'); do php -l $W/x368/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d368.done; exit 1; }
cp -a $W/x368/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x368/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x368/BUILD_INFO.json $b; done
echo "copied $(find $W/x368 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild368.php
for u in / /pricing/ /pricing /pricing.html /pricing/job-hunt/ /resume-examples/ /guides/resume/no-experience-format/ /login /workspace.html /admin.html /sitemap.xml /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' https://cvtara.com$u)"; done
echo "pricing products: $(curl -s https://cvtara.com/pricing/ | grep -o '"@type":"Product"' | wc -l)"
echo "updated line: $(curl -s https://cvtara.com/resume-examples/ | grep -o 'Updated <time[^<]*' | head -1)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x368; touch $W/d368.done; rm -f $W/d368.lock

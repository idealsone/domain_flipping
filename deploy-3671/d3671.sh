#!/bin/bash
# CVTara 3.67.1 overlay on 3.67.0 (review editor window, What's new release notes, Blog & SEO date fix; PR #55). Runs once only after the owner chose "Yes, deploy 55 now"; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3671.log; mkdir -p $W
if [ -f $W/d3671.done ] || [ -f $W/d3671.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d3671.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/ea2feeb0947b898e21eb7281865cd8cd357e3d66/deploy-3671
cd $W && curl -fsSL "$SRC/cvt3671.tgz" -o cvt3671.tgz && curl -fsSL "$SRC/checks.txt" -o checks3671.txt || { echo "ABORT download"; touch $W/d3671.done; exit 1; }
echo "324cf1098913199f937e456f30cdfe938669d133ee80c5c2aa98a0be2bd2f377  cvt3671.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3671.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then echo "MISMATCH $p $cur"; bad=1; fi; done < $W/checks3671.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.67.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d3671 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3671.done; rm -f $W/d3671.lock; exit 1; }
B=$W/backup_3671_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt3671.tgz | grep -v '/$' | sed 's#^\./##' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3671.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3671.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3671 && tar -xzf $W/cvt3671.tgz -C $W/x3671 || { echo "ABORT extract"; touch $W/d3671.done; exit 1; }
php -l $W/x3671/cvtara_app/app/Services/WhatsNew.php >/dev/null || { echo "ABORT syntax; nothing changed"; touch $W/d3671.done; exit 1; }
cp -a $W/x3671/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x3671/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3671/BUILD_INFO.json $b; done
echo "copied $(find $W/x3671 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; cd $H
for u in / /login /workspace.html /admin.html /api/health /whats-new.js /cvtara-ds.css; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3671; touch $W/d3671.done; rm -f $W/d3671.lock

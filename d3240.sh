#!/bin/bash
# CVTara 3.24.0 overlay deploy (PR #27: fast indexing, clearer Search Console errors); approved by the owner 2026-10-26 11:11 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3240.log; mkdir -p $W
if [ -f $W/d3240.done ] || [ -f $W/d3240.lock ]; then cat $L; exit 0; fi
touch $W/d3240.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-24-0
cd $W && curl -fsSL "$SRC/cvt3240.tgz" -o cvt3240.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3240.php && curl -fsSL "$SRC/errors.php" -o errors3240.php || { echo "ABORT download"; touch $W/d3240.done; exit 1; }
echo "ee128caea4f1d8b0bebc1ecd51db3780064961a31644becd297fbeadf3bebe9c  cvt3240.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3240.done; exit 1; }
echo "-- unresolved errors before"; php $W/errors3240.php
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php a1f2e27c9c92f9140218957577b468fc7a8bb66acb31554899ce3c27743728ee
cvtara_app/app/Http/Controllers/ContentController.php 79e7451d624dd719eb786442f902c66e05899f33d53d07ffefff9c5edf4fc13c
cvtara_app/app/Services/SearchConsole.php eb25a1d9d053f70005dd0e984673123fcb39b461b95c1333533cf1bfeadf4870
cvtara_app/app/Services/FastIndex.php ABSENT
cvtara_app/routes/console.php 45dbfc3c8d60fef5079e713f307690b6f1d3f813f036e37eba0ed1d50c58a963
cvtara_app/resources/console/advanced.js 25e625f86695fe4839927f79e22b3a0eff50ac1d08470530ec421bd789d08c8c
public_html/owner-console.css 8e3164ae1aaccdf0ee69b899f575e797d2164409de4b63021ceb398c87971a68
LIST
grep -q '"version": *"3.23.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.23.0"; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3240.done; rm -f $W/d3240.lock; exit 1; }
B=$W/backup_3240_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3240.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3240.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3240.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3240 && tar -xzf $W/cvt3240.tgz -C $W/x3240 && cp -a $W/x3240/cvtara_app/. cvtara_app/ && cp -a $W/x3240/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3240/BUILD_INFO.json $b; done
echo "copied $(find $W/x3240 -type f | wc -l) files"
for f in cvtara_app/app/Services/FastIndex.php cvtara_app/app/Services/SearchConsole.php cvtara_app/app/Http/Controllers/AdminController.php cvtara_app/app/Http/Controllers/ContentController.php cvtara_app/routes/console.php; do php -l $f | head -1; done
cd cvtara_app && php artisan optimize:clear; cd $H
php $W/rebuild3240.php
for u in / /login /contact.html /templates.html /sitemap.xml /robots.txt; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "not-a-key-file $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/00000000000000000000000000000000.txt)"
echo "admin-indexing-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/indexing)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "-- unresolved errors after"; php $W/errors3240.php | head -5
echo "== done $(date -u)"; rm -rf $W/x3240; touch $W/d3240.done; rm -f $W/d3240.lock

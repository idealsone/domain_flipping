#!/bin/bash
# CVTara 3.29.3 overlay deploy on live 3.29.2 (PR #39: count revenue and offers seen from a chosen moment, real-money paid users). No migration. Owner approved ("Deploy") 2026-10-31 23:51 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3293b.log; mkdir -p $W
if [ -f $W/d3293b.done ] || [ -f $W/d3293b.lock ]; then cat $L; exit 0; fi
touch $W/d3293b.lock; exec >>$L 2>&1; echo "== start $(date -u)"
fail(){ echo "ABORT $1"; touch $W/d3293b.done; rm -f $W/d3293b.lock; exit 1; }
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-29-3
cd $W && curl -fsSL "$SRC/cvt3293.tgz" -o cvt3293.tgz && curl -fsSL "$SRC/fresh3293.php" -o fresh3293.php || fail download
echo "b944f2633353ef0152c393b6a135ce3b344c1ad727d5ee7609f47a0bc045f58c  cvt3293.tgz" | sha256sum -c - || fail "tgz checksum"
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php 7f44ef6786873ad19e616b5286dcd2723fe9b0978d0146c535552754995a82e5
cvtara_app/app/Http/Controllers/OutreachAdminController.php 12d7f7f798899834bdcd3ba3af5fe33230670b819f95db70a8606cb5240c377f
cvtara_app/app/Http/Controllers/ReportController.php 72ed0e660bdbcc9180d2685a0624e7fe751d9fb64f9dc492fb5a0c236d26e2ff
cvtara_app/app/Services/Growth.php 3b6488d6aef8bc723cbc6b219929a617fb261f7e4cf5a2fc56d09b684d4573c7
cvtara_app/app/Services/Promos.php 574654122131281e6f2f5fc05eed6a7094c78106513eeb2aeaaa015aaeeefb84
cvtara_app/app/Services/Settings.php 5f5b9b253b38febc4595f75a7fa0748a2109fc471333ce307e52f8ccd33172d7
cvtara_app/resources/console/ops.js 4aa6d856c85834ecc9806cdcc5b811afc5eea341ca4b325884dd2a707bb38ad7 a947cd1f8ba03dbe9ef0dc64839e3abd9f7ee6df21b4cd0abb9f474c520ad040 5ac34af27086e59cfab1a2380c2192f5cbc34bf9bb1b1a0345955ba3d9235149
LIST
grep -q '"version": *"3.29.2"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.29.2"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || fail "live files differ from the expected base; nothing changed"
B=$W/backup_3293_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz cvtara_app/app/Http/Controllers/AdminController.php cvtara_app/app/Http/Controllers/OutreachAdminController.php cvtara_app/app/Http/Controllers/ReportController.php cvtara_app/app/Services/Growth.php cvtara_app/app/Services/Promos.php cvtara_app/app/Services/Settings.php cvtara_app/resources/console/ops.js $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && echo "files backup $(tar -tzf $B/files.tgz | wc -l) entries" || fail "files backup"
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || fail "database backup"
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3293 && tar -xzf $W/cvt3293.tgz -C $W/x3293 || fail extract
cp -a $W/x3293/cvtara_app/. cvtara_app/ || echo "COPY FAILED, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3293/BUILD_INFO.json $b; done
echo "copied $(find $W/x3293 -type f | wc -l) files"
cd cvtara_app; for f in app/Http/Controllers/AdminController.php app/Http/Controllers/OutreachAdminController.php app/Http/Controllers/ReportController.php app/Services/Growth.php app/Services/Promos.php app/Services/Settings.php; do php -l $f | head -1; done
php artisan optimize:clear | tail -2
cd $H
php $W/fresh3293.php
for u in / /login /sitemap.xml /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "console/ops.js anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/ops.js)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3293; touch $W/d3293b.done; rm -f $W/d3293b.lock

#!/bin/bash
# CVTara 3.29.5 overlay deploy on 3.29.4 (Growth page downloads: GrowthAdminController + growth.js). No migration. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3295.log; mkdir -p $W
if [ -f $W/d3295.done ] || [ -f $W/d3295.lock ]; then cat $L; exit 0; fi
touch $W/d3295.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-29-5
cd $W && curl -fsSL "$SRC/cvt3295.tgz" -o cvt3295.tgz || { echo "ABORT download"; touch $W/d3295.done; exit 1; }
echo "0e322ac2062c53e9a504b85f4239daa938a1ca06ef4015a5d95527da03c70b1e  cvt3295.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3295.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/GrowthAdminController.php 8781371ea673070b1fb627054c1ece8cda45ca76ca9bf9c6e188948aeb483ceb
cvtara_app/resources/console/growth.js 45b1c67360a52bb5289e9bf41d2463f53e8b38877358a9dadb938e8a6843cae4 d497164828d65934648d51424019a8c0da4cfec77463e1c188c57c4480dfb2a9
LIST
grep -q '"version": *"3.29.4"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.29.4"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3295.done; rm -f $W/d3295.lock; exit 1; }
B=$W/backup_3295_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3295.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3295.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3295.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3295 && tar -xzf $W/cvt3295.tgz -C $W/x3295 && cp -a $W/x3295/cvtara_app/. cvtara_app/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3295/BUILD_INFO.json $b; done
echo "copied $(find $W/x3295 -type f | wc -l) files"
php -l cvtara_app/app/Http/Controllers/GrowthAdminController.php | head -1
cd cvtara_app && php artisan optimize:clear; cd $H
for u in / /login /sitemap.xml /llms.txt /resume-builder/ /pricing; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/growth/seo/tracked?export=csv)"
cd cvtara_app && php artisan route:list --path=admin 2>/dev/null | grep -c growth; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3295; touch $W/d3295.done; rm -f $W/d3295.lock

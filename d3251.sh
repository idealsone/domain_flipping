#!/bin/bash
# CVTara 3.25.1 overlay deploy (PR #31: plan dialog shows current packages; owner security code lasts 1 hour, none for plain text saves); approved by the owner 2026-10-30 07:19 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3251.log; mkdir -p $W
if [ -f $W/d3251.done ] || [ -f $W/d3251.lock ]; then cat $L; exit 0; fi
touch $W/d3251.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-25-1
cd $W && curl -fsSL "$SRC/cvt3251.tgz" -o cvt3251.tgz || { echo "ABORT download"; touch $W/d3251.done; exit 1; }
echo "65a2f29c0f0d9815d4c77e354f0ffc805b8f3974e774e4d5090bf29ad5c03454  cvt3251.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3251.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php c48ff974606c2008886657f38df7a91b260d15b29bb98bf6741f825ae03e8d17
cvtara_app/app/Http/Controllers/AdminOpsController.php baea73a0cb11d87729a42fb140adf2df196e5afa878121e0790beaa97e32cf5c
cvtara_app/app/Services/AccountSecurity.php 6c9d1aa26245be11c83fc2ef7ca829b445dc2bd192e45871534e087b92ddcc92
cvtara_app/resources/console/people.js 3d4e58e9e10abc774249d9044c737ca60245a4cface26dc3d1078c50e4f2b870
cvtara_app/resources/console/extras.js e6bb99f32e863c09f759259f76db1497dbcff49b1584262fbde4c9dcf85742a7
LIST
grep -q '"version": *"3.25.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.25.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3251.done; rm -f $W/d3251.lock; exit 1; }
B=$W/backup_3251_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3251.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3251.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3251.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3251 && tar -xzf $W/cvt3251.tgz -C $W/x3251 && cp -a $W/x3251/cvtara_app/. cvtara_app/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3251/BUILD_INFO.json $b; done
echo "copied $(find $W/x3251 -type f | wc -l) files"
for f in $(tar -tzf $W/cvt3251.tgz | grep "\.php$"); do php -l $f | head -1; done
cd cvtara_app && php artisan optimize:clear; cd $H
for u in / /login /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-users-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
echo "console-people-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/people.js)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3251; touch $W/d3251.done; rm -f $W/d3251.lock

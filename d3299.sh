#!/bin/bash
# CVTara 3.29.9 overlay deploy (outreach: polished mails); approved by the owner 2026-11-02. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3299.log; mkdir -p $W
if [ -f $W/d3299.done ] || [ -f $W/d3299.lock ]; then cat $L; exit 0; fi
touch $W/d3299.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-29-9
cd $W && curl -fsSL "$SRC/cvt3299.tgz" -o cvt3299.tgz || { echo "ABORT download"; touch $W/d3299.done; exit 1; }
echo "543f70d48dbae0316fa5966ce3639176a96ef1f7cdb96a7dc2d3d01ab7bf2c39  cvt3299.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3299.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Services/Outreach.php b380d38c0691a074d1ac425d32920e44c30d8671dc8df3808a59bda2168c616f 66f7ac0b03edfc69abda95d5533d32236f663b2dfc6c16b26ecd6ffcc4a5e5d4
cvtara_app/resources/console/outreach.js 2dc8914532a2970d4b61a7d73769e006a16cae3751ead959fd1500e635789b04 2d425a87a40bf36564ca793e753005b302ae66ebac2f867a0260d18172aa12fd
LIST
grep -q '"version": *"3.29.8"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.29.8"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3299.done; rm -f $W/d3299.lock; exit 1; }
B=$W/backup_3299_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3299.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3299.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3299.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3299 && tar -xzf $W/cvt3299.tgz -C $W/x3299 && cp -a $W/x3299/cvtara_app/. cvtara_app/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3299/BUILD_INFO.json $b; done
echo "copied $(find $W/x3299 -type f | wc -l) files"
for f in $(tar -tzf $W/cvt3299.tgz | grep "\.php$"); do php -l $f | head -1; done
cd cvtara_app && php artisan optimize:clear; cd $H
for u in / /login /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-users-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
echo "console-outreach-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/outreach.js)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3299; touch $W/d3299.done; rm -f $W/d3299.lock

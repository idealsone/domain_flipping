#!/bin/bash
# CVTara 3.29.6 overlay deploy (outreach import: .xlsx upload, tab/semicolon files); approved by the owner 2026-11-02. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3297.log; mkdir -p $W
if [ -f $W/d3297.done ] || [ -f $W/d3297.lock ]; then cat $L; exit 0; fi
touch $W/d3297.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-29-7
cd $W && curl -fsSL "$SRC/cvt3297.tgz" -o cvt3297.tgz || { echo "ABORT download"; touch $W/d3297.done; exit 1; }
echo "d955b25766a91d6391a6c1cfad2b06197fa499dcc0f34c51a329d7aaf1c1fc91  cvt3297.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3297.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Services/Outreach.php abee0f61d8a277b3becd215c2b921a7ebdac712097465b01232df82e03963e3d 5439f7899ef27f2cd7c7d493048eb031a43f0d470db9d2dcff6b49741170711d
cvtara_app/app/Http/Controllers/OutreachAdminController.php 021c44b58f0406e522e361c855e09292435e84c94b8f6078c9e6a19f13a8d65f 480a957842a059d35cea60301acc6905a404e6e017ae95f1a785344cd0d30342
cvtara_app/resources/console/outreach.js 1a87de7b05a2184f7fce1825817da3dc65d7a20497b2fada4434e7acc3903bfa f9715d10fa2294e38305167bec3921a7d72ba338ce30c64a390db56644b43423
LIST
grep -q '"version": *"3.29.6"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.29.6"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3297.done; rm -f $W/d3297.lock; exit 1; }
B=$W/backup_3297_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3297.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3297.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3297.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3297 && tar -xzf $W/cvt3297.tgz -C $W/x3297 && cp -a $W/x3297/cvtara_app/. cvtara_app/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3297/BUILD_INFO.json $b; done
echo "copied $(find $W/x3297 -type f | wc -l) files"
for f in $(tar -tzf $W/cvt3297.tgz | grep "\.php$"); do php -l $f | head -1; done
cd cvtara_app && php artisan optimize:clear; cd $H
for u in / /login /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-users-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
echo "console-outreach-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/outreach.js)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3297; touch $W/d3297.done; rm -f $W/d3297.lock

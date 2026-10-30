#!/bin/bash
# CVTara 3.27.1 overlay deploy (PR #35: a reload reopens the Settings page you were on); approved by the owner 2026-10-30 11:29 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3271.log; mkdir -p $W
if [ -f $W/d3271.done ] || [ -f $W/d3271.lock ]; then cat $L; exit 0; fi
touch $W/d3271.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-27-1
cd $W && curl -fsSL "$SRC/cvt3271.tgz" -o cvt3271.tgz || { echo "ABORT download"; touch $W/d3271.done; exit 1; }
echo "485ab65c03b73febe3f433a55b63c945f1e081f8e305257c2543894e417e2946  cvt3271.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3271.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/resources/console/ops.js 618b87268a6c9bddcee67d0e41b351f3c2185b70b302e6e02a6c2812fafb36b3 740102f01cc9c2530693149de1e9237c8c931d54b415b2f2c5881014d256a1d1
LIST
grep -q '"version": *"3.27.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.27.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3271.done; rm -f $W/d3271.lock; exit 1; }
B=$W/backup_3271_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3271.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3271.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3271.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3271 && tar -xzf $W/cvt3271.tgz -C $W/x3271 && cp -a $W/x3271/cvtara_app/. cvtara_app/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3271/BUILD_INFO.json $b; done
echo "copied $(find $W/x3271 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear; cd $H
for u in / /login /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-users-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
echo "console-ops-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/ops.js)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3271; touch $W/d3271.done; rm -f $W/d3271.lock

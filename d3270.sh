#!/bin/bash
# CVTara 3.27.0 overlay deploy (PR #34: SEO agent, Search in reports, AI spending without a default limit); approved by the owner 2026-10-30 11:28 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3270.log; mkdir -p $W
if [ -f $W/d3270.done ] || [ -f $W/d3270.lock ]; then cat $L; exit 0; fi
touch $W/d3270.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-27-0
cd $W && curl -fsSL "$SRC/cvt3270.tgz" -o cvt3270.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3270.php || { echo "ABORT download"; touch $W/d3270.done; rm -f $W/d3270.lock; exit 1; }
echo "f85954b2d00723f2a3ab39647ce78b3a46ef6bee13bd27d4e9fed0bc2b31b2a2  cvt3270.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3270.done; rm -f $W/d3270.lock; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/GrowthAdminController.php 228ffa364207b47f0342a2b80e44cd5de76a65b20a1a9898972cd58d4d58ad22 82d73f7827a177d82c3f2b97a4a5588f8dd5b67ed784970a54f6408e985656c0
cvtara_app/app/Services/Growth.php ffe21612a86a526548d8d25f13f9728befb5f8fd5fc1f48da2ef14f246f50f18 30a58bbf7a975d5cd9d485e697a9fd3a9ec4001b31d6825ee7f7ff2aec37b80e
cvtara_app/routes/console.php 378a70183695a053a7eddd2cd7c99a1c69605fae6cb3f12eaf4df210964d3c87 a52e355ea9f7db56e8eb64655fb51fded9c500636c640e1c5d77151476d38d73
cvtara_app/app/Services/SeoPages.php da332dfa4571e7763faa6b5ea3d5b2a28cd0af0380c319d63549ef7dc04f5515 a4d1f7f14cd2d2184cc05d1849f23bcc054a67b5c286004b9d193d15cb0311c8
cvtara_app/resources/console/growth.js 922c24b05c14269f953c0809019725f2be35ce4e539749fd984e6ff92097a5fd d1d4d2204b457218ca26ccc2d4b0e6a7258902680f966a3b199c6cf5316f351d e28df4003a00d4759c4f794a19747607e16678813bc313be0e89d78a78edd150
cvtara_app/app/Services/SeoAgent.php ABSENT e4a6bb10dd1726a32969f88b8d53d45273b37dc798b47502e0d6262960fdcb0a
cvtara_app/database/migrations/2026_10_30_000003_seo_agent.php ABSENT b7a31cdc1aa5258fa99d9f7503adf808efa47ec93235caa56f128f9898166b72
LIST
grep -q '"version": *"3.26.1"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.26.1"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3270.done; rm -f $W/d3270.lock; exit 1; }
B=$W/backup_3270_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3270.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3270.done; rm -f $W/d3270.lock; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3270.done; rm -f $W/d3270.lock; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3270 && tar -xzf $W/cvt3270.tgz -C $W/x3270 && cp -a $W/x3270/cvtara_app/. cvtara_app/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3270/BUILD_INFO.json $b; done
echo "copied $(find $W/x3270 -type f | wc -l) files"
for f in app/Services/SeoAgent.php app/Services/SeoPages.php app/Services/Growth.php app/Http/Controllers/GrowthAdminController.php routes/console.php; do php -l cvtara_app/$f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep seo_agent; php artisan optimize:clear; cd $H
php $W/rebuild3270.php
for u in / /login /resume-examples/ /resume-examples/information-technology/data-analyst/ /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/admin.html) api-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/api/admin/growth/seo)"
cd cvtara_app && php artisan tinker --execute='$s=App\Services\SeoAgent::summary(); echo json_encode(["ready"=>$s["ready"],"pending"=>$s["pending"],"cap"=>App\Services\Growth::budget()["capPaise"]]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3270; touch $W/d3270.done; rm -f $W/d3270.lock

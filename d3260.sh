#!/bin/bash
# CVTara 3.26.0 overlay deploy (PR #34: Growth page, 2 PM / 7 PM reports, AI spending cap, campaign segments); approved by the owner 2026-10-30 10:12 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3260.log; mkdir -p $W
if [ -f $W/d3260.done ] || [ -f $W/d3260.lock ]; then cat $L; exit 0; fi
touch $W/d3260.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-26-0
cd $W && curl -fsSL "$SRC/cvt3260.tgz" -o cvt3260.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3260.php || { echo "ABORT download"; touch $W/d3260.done; rm -f $W/d3260.lock; exit 1; }
echo "9c21b9390638047cb14f040a0f7660e8d3392c5304b314d40527d812aa3b6184  cvt3260.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3260.done; rm -f $W/d3260.lock; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php e8c8cb623651518f4f90c223d6a44b80e7e69de5d8fa24f4336559308e6c91fe 00437ab8100fcc40e9e889b1c6e9881fa7471778a33364697995b2f77402088d
cvtara_app/resources/frontend/admin.html 3af427f507d301e3a0c8cd9752d3d9cf91384230496a6c41913e297e9d7d57b8 5cdc2291911aa046a3aad98c5e392c75c0a3c3ae20f63f02d0888cb549864cce
cvtara_app/app/Http/Controllers/CampaignAdminController.php d02e299b3c0cafaf411918de6aee954c521a0b4e0b68dfddd8ed3753b4f4a6c5 e8f2a43c7ea7c6dd414ef931db2dc7408a04021e5cfa54a42a8d6f4e62359bb2
cvtara_app/resources/console/campaigns.js eda09c05ba1f1d6e0dae2c8173a6705053443e1d78e2e4cc6119a9b7adbe210e 07948b522feba3c68c55dbc8cfcba95d65aad027d5618118eac1c7fbbcbfef99
cvtara_app/routes/console.php dd0ad05c78827438d4d31120882f439a9ca2138b53d87a4872edf4e0ada87508 378a70183695a053a7eddd2cd7c99a1c69605fae6cb3f12eaf4df210964d3c87
cvtara_app/app/Services/Campaigns.php c81c308151b031a1f32ad14463e1c5a294190bb6131f4b988eb975774c8cbef7 9252b3e8db34d642d193a771cbcb4ea42ae73720241816eab6dadb911dbbb0ac
cvtara_app/app/Services/TaraAI.php 164d84fb8ca22d8296e2390e06251989cf37587d927ef9cac9beb7dcd9754a9b a2ec806b08731f2dfc2d7656d1afec6ad1ecd3fd1644bea791c909a375b4d264
cvtara_app/resources/data/defaults.json 72d1f050b57bd57b5f08a8e1e87c60b8e08aa39191be09c33ea370c9b644985c a93a4f4fdf463f369aa402126fe04408690100a49b6d188e0906fe1fb51751bc
cvtara_app/routes/web.php 7cb15d4b76f0eeeb4a7353e301b5c08e41837fda4019781875cff177696dc971 b4a7aabdf9d01215f60e4ea388cad50686c3df88e7a4c5af4371aee6819a60b6
cvtara_app/app/Http/Controllers/GrowthAdminController.php ABSENT 228ffa364207b47f0342a2b80e44cd5de76a65b20a1a9898972cd58d4d58ad22
cvtara_app/app/Services/Growth.php ABSENT ffe21612a86a526548d8d25f13f9728befb5f8fd5fc1f48da2ef14f246f50f18
cvtara_app/database/migrations/2026_10_30_000002_growth_os.php ABSENT 2d8f8f97ea972fd1085f6d22add4c25326ec3cccbd5ae74e0d4112d452547bbc
cvtara_app/resources/console/growth.js ABSENT 922c24b05c14269f953c0809019725f2be35ce4e539749fd984e6ff92097a5fd
LIST
grep -q '"version": *"3.25.3"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.25.3"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3260.done; rm -f $W/d3260.lock; exit 1; }
B=$W/backup_3260_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3260.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3260.done; rm -f $W/d3260.lock; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3260.done; rm -f $W/d3260.lock; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3260 && tar -xzf $W/cvt3260.tgz -C $W/x3260 && cp -a $W/x3260/cvtara_app/. cvtara_app/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3260/BUILD_INFO.json $b; done
echo "copied $(find $W/x3260 -type f | wc -l) files"
for f in app/Services/Growth.php app/Http/Controllers/GrowthAdminController.php app/Http/Controllers/AdminController.php app/Services/TaraAI.php app/Services/Campaigns.php; do php -l cvtara_app/$f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep growth_os; php artisan optimize:clear; cd $H
php $W/rebuild3260.php
for u in / /login /privacy.html /templates.html /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "privacy-line $(curl -s https://cvtara.com/privacy.html | grep -c 'college, target role')"
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/admin.html) growth-js-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/growth.js) api-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/api/admin/growth)"
cd cvtara_app && php artisan tinker --execute='echo json_encode(["month"=>App\Services\Growth::month()["metrics"]["purchases"], "budget"=>App\Services\Growth::budget()["capPaise"]]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3260; touch $W/d3260.done; rm -f $W/d3260.lock

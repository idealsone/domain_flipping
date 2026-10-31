#!/bin/bash
# CVTara 3.29.0 overlay deploy on 3.28.3 (PR #34: weekly Sunday report, SEO page and keyword tracking, new landing pages, calculator schema, llms.txt). One new migration (seo_track). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3290.log; mkdir -p $W
if [ -f $W/d3290.done ] || [ -f $W/d3290.lock ]; then cat $L; exit 0; fi
touch $W/d3290.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-29-0
cd $W && curl -fsSL "$SRC/cvt3290.tgz" -o cvt3290.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3290.php || { echo "ABORT download"; touch $W/d3290.done; exit 1; }
echo "f1667095961da4dbef7e2678fa9e3f742b8da8ceaab82a4a54502489ac1f6a97  cvt3290.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3290.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/ContentController.php cd1433eb49bb77f75a22860466b83cce4b606fe1890f772d066130d966eb3608
cvtara_app/app/Http/Controllers/GrowthAdminController.php 82d73f7827a177d82c3f2b97a4a5588f8dd5b67ed784970a54f6408e985656c0
cvtara_app/app/Services/Growth.php ff8961d34922e5bc8130d04b434413c422689f87cd36595bd34d9c0d32d2f669
cvtara_app/app/Services/SeoAgent.php e4a6bb10dd1726a32969f88b8d53d45273b37dc798b47502e0d6262960fdcb0a
cvtara_app/app/Services/SeoPages.php a4d1f7f14cd2d2184cc05d1849f23bcc054a67b5c286004b9d193d15cb0311c8
cvtara_app/resources/frontend/blog.html 093107b32c7c97305c2bb79ee7bd219bf14d21dbdd131c80279e293273b7a2b2
cvtara_app/resources/frontend/cover-letters.html 94c2e3f018b0bf7c239d89969cb09cbe7999de1811796ec0ddcd94aa3a092953
cvtara_app/resources/frontend/guides.html 9a66b2935a1c7f37efa089b47db901949bc0d2f67c541cf4372b8d327fd5b9e4
cvtara_app/resources/frontend/templates.html b7cb4ba6f2d6f82419ee97f715d299ebe1da460a3d39f63db89510aadabaeaf2
cvtara_app/resources/console/growth.js e28df4003a00d4759c4f794a19747607e16678813bc313be0e89d78a78edd150 ac535c482320419693d7e1bf04549430b9618762b9904b5b850035d229e2c30e
cvtara_app/database/migrations/2026_10_31_000001_seo_tracking.php ABSENT
LIST
grep -q '"version": *"3.28.3"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.28.3"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3290.done; rm -f $W/d3290.lock; exit 1; }
B=$W/backup_3290_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3290.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3290.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3290.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3290 && tar -xzf $W/cvt3290.tgz -C $W/x3290 && cp -a $W/x3290/cvtara_app/. cvtara_app/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3290/BUILD_INFO.json $b; done
echo "copied $(find $W/x3290 -type f | wc -l) files"
for f in app/Services/SeoAgent.php app/Services/SeoPages.php app/Services/Growth.php app/Http/Controllers/GrowthAdminController.php app/Http/Controllers/ContentController.php; do php -l cvtara_app/$f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep seo_track; php artisan optimize:clear; cd $H
php $W/rebuild3290.php
for u in / /login /sitemap.xml /llms.txt /robots.txt /resume-builder/ /cover-letter-generator/ /cover-letter-examples/how-to-write/ /ats-checker/ /templates.html /blog; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "llms-ai-words $(curl -s https://cvtara.com/llms.txt | grep -ciE '\bai\b|claude|anthropic|artificial')"
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/growth/seo/tracked)"
cd cvtara_app && php artisan tinker --execute='$g=App\Services\Growth::settings(); echo json_encode(["reportEvery"=>$g["reportEvery"],"reports"=>$g["reports"],"track"=>Illuminate\Support\Facades\DB::table("seo_track")->count()]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3290; touch $W/d3290.done; rm -f $W/d3290.lock

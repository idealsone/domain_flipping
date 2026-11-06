#!/bin/bash
# CVTara 3.31.0 overlay deploy on 3.30.0 (testing-sheet plan items, PR #45). Owner typed "deploy 3.31.0" on 2026-11-06. One migration (adds feedback.company/designation). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3310.log; mkdir -p $W
if [ -f $W/d3310.done ] || [ -f $W/d3310.lock ]; then cat $L; exit 0; fi
touch $W/d3310.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-31-0
cd $W && curl -fsSL "$SRC/cvt3310.tgz" -o cvt3310.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3310.php || { echo "ABORT download"; touch $W/d3310.done; exit 1; }
echo "a793713b29ca147dc6967b9a20e05ef3adf930763be2dd7c756e28bd827ff97b  cvt3310.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3310.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php 948181572393401dac7be979ed279e2d347b648f17e6d3a4ba85e1a2ee788119 
cvtara_app/app/Http/Controllers/AdminOpsController.php e02469e3cae8301e23cce3c4e45e04b58e92ae943c58947a636295cd038298a9 
cvtara_app/app/Http/Controllers/ApiController.php 6f6030445c9c8163cac04fb20706385b12bf5c87db6ec65e3376b20f5f89b400 
cvtara_app/app/Http/Controllers/ReportController.php e6a1cd790d38f16ed01fefeab2560af0d36849520a6475ae1863eb38a62f377f 
cvtara_app/app/Services/CoverLetterWriter.php fb513bb6ef2d1275121b8e4b0e07be3e3b4808dd84733eb393b6ae06d68bfb94 
cvtara_app/app/Services/ExpertReview.php 1c910a6ea165002d769879dc001137d7075a7ade4f1c3462ed4708493f476ab4 
cvtara_app/app/Services/Growth.php 2a2b36478be8316d9d3a9c48ee5dcf7e3ae9d60b8403c89012cfa17b02ca91c3 
cvtara_app/app/Services/ResumeTailor.php daf1e3a6a559bf91d420a4755aec128c63a034b820937da36985b33033003902 
cvtara_app/app/Services/Reviews.php ABSENT
cvtara_app/app/Services/TaraAI.php a2ec806b08731f2dfc2d7656d1afec6ad1ecd3fd1644bea791c909a375b4d264 
cvtara_app/database/migrations/2026_11_06_000002_review_company_designation.php ABSENT
cvtara_app/resources/console/growth.js 16efbc497c9e8fc69998e5e72a2e2a77e04296fb46521907ab98010fd7ddabdc 03de563a2abba552711cfdf8c87f6096b71ac2a3489c946588842557ac2d9f77
cvtara_app/resources/console/ops.js 077e4aa331f077d84e3c1a43ecfa01aac7b885509a85ee675aa4cd0e76ff5e26 195b9680a2ee593eb694f1c191e1aaedfcce00856e7cfd62ee7d19faccaf9083
cvtara_app/resources/console/owner.js 0ab642aaaae19c2f1f9268a1b042ab507f9e671701e75fc0692b89590d6d927d 17ece45a0bd53c6bfb6c657f948b3f25f829120c2794cd92fbce3cacd116e685
cvtara_app/resources/console/people.js 0fdb916c7b00dc9017eeb960d9c3e5cbd1b65d9d5b7b17a7fbf6bf239876b885 93cf0851206ddfaec521e822e61704b6e2577cac55d38e71d6dc596eff588a7c
cvtara_app/resources/console/reports.js 881d892a139c5bfdcd72a8ee41dee506142495f0a49b75a36d0627adde0733ad cd31d0323aaff07affb31aba0adb0dc4bf9cf9b618bbe4390706095bc9b6c7e9
public_html/home-ui.js cc4db8485e135e24781b5f51735b96185753268bb73ee98b863966a085a50943 6eed3ea398edf4621c6785f27702ec540579b98f9206d24d863b4d175dc9f2b3
public_html/owner-public.js a8e473a0e18acb5bfad18a4c62bd3bf99e5c1411ff9b5e1672da5a94d2a844f6 5058449d31abf5a6046185378ab5e315c6114d1788f493fed74d938d3dc569b6
public_html/preview.js 7cd8ffe004e9c1b8df9db307f5ea7d8095e25061e5203b4f1a551443e5d0328b 509cb0f89c9fb952223e6bc005c6788845848933120b8b7fd054a0ff0b65e887
public_html/site-polish.css 0dcb57414121bf17bec9c5e6d0a408bcf523ea731f9f2845bfdbadcab27117da 
public_html/workspace-ui.js 9a2a3681a05de146d3922a39a74f4db3c5bd6ad8ddaad46bf5f27a3fe2a33f01 9217bd801ef727a1d0e3795d435a421e3f778a30ef0a1ab0893b6b2e889051b0
LIST
grep -q '"version": *"3.30.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.30.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3310.done; rm -f $W/d3310.lock; exit 1; }
B=$W/backup_3310_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3310.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3310.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3310.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3310 && tar -xzf $W/cvt3310.tgz -C $W/x3310 && cp -a $W/x3310/cvtara_app/. cvtara_app/ && cp -a $W/x3310/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3310/BUILD_INFO.json $b; done
echo "copied $(find $W/x3310 -type f | wc -l) files"
for f in $(cd $W/x3310 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep review_company; php artisan optimize:clear; cd $H
php $W/rebuild3310.php
for u in / /login /contact.html /sitemap.xml /preview.js /home-ui.js /site-polish.css; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' 'https://cvtara.com/api/admin/report?dataset=ai_spend')"
echo "public-content $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/public-content)"
cd cvtara_app && php artisan tinker --execute='echo json_encode([Illuminate\Support\Facades\Schema::hasColumns("feedback",["company","designation"]), count(App\Services\Reviews::published())]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3310; touch $W/d3310.done; rm -f $W/d3310.lock

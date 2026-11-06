#!/bin/bash
# CVTara 3.30.0 overlay deploy on 3.29.8 (testing-sheet fixes, PR #45; includes 3.29.9 outreach mail look). Owner typed "deploy 3.30.0" on 2026-11-06. One settings-only migration. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3300.log; mkdir -p $W
if [ -f $W/d3300.done ] || [ -f $W/d3300.lock ]; then cat $L; exit 0; fi
touch $W/d3300.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-30-0
cd $W && curl -fsSL "$SRC/cvt3300.tgz" -o cvt3300.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3300.php || { echo "ABORT download"; touch $W/d3300.done; exit 1; }
echo "3528682a2b03ab5d26a8fc6bf2b0e3e3379d10c387089e0df8c6f4583a77956a  cvt3300.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3300.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminOpsController.php 4c0a0825bbca797e346beddf64a2e0ac882fc6d40dd99f3b821a51e4dc291a23
cvtara_app/app/Http/Controllers/ApiController.php 6571b76474ede6adc96dbcc209a1ebe8bee94f42ebf4ca89f4ed74cfcc696946
cvtara_app/app/Http/Controllers/OutreachAdminController.php 480a957842a059d35cea60301acc6905a404e6e017ae95f1a785344cd0d30342
cvtara_app/app/Services/AtsEngine.php ff1b7c84e76b31af4c52777890b73fda54ada95cb1d0415208b07b73848f0ed1
cvtara_app/app/Services/CoverLetterWriter.php 64fcffe53b1d044b57494eafaaf577d59c7b8f7733ce6ebf8a8529ca9caef221
cvtara_app/app/Services/EmailRenderer.php 8b231f81afbb54f807fa64c9cceef63e1f8b5df36e9cc428f3158e6b14b4476f
cvtara_app/app/Services/Outreach.php b380d38c0691a074d1ac425d32920e44c30d8671dc8df3808a59bda2168c616f
cvtara_app/app/Services/ResumeImport.php 3ba0a2117720567469cfc529d02206850733071d5daec4abf71e76eaaacefc8d
cvtara_app/app/Services/Settings.php f408452b9b9c13aaf7cdaf75ea4afae7099a18950debf728d8c1f47e6f0fb718
cvtara_app/app/Services/TextClean.php ABSENT
cvtara_app/database/migrations/2026_11_06_000001_contact_details_from_testing_sheet.php ABSENT
cvtara_app/resources/console/outreach.js 2dc8914532a2970d4b61a7d73769e006a16cae3751ead959fd1500e635789b04
cvtara_app/resources/console/owner.js d6267a680466f43c2d53a7bedfa2495ef68ae3d70f8de80e9dad7cf84ece1086
cvtara_app/resources/data/defaults.json 747d0d16d09a97a4424a1167d67bd54498b476df6d6ee3390663625b82682e54
cvtara_app/resources/frontend/admin.html e6c6226557e1396f20d515c7e9688f482e3e8bca262ed51c53149941eacfc163
cvtara_app/resources/frontend/contact.html f235ddabcd6580dbb444c22a81222d98c2b697484ccf2fbaa39cc8c8a056ef18
cvtara_app/resources/frontend/index.html 942755b71158a90fd2768cd4436ca0d866cb7a38875223e2d8f05e1dc7c44360
public_html/business-ui.js 246abae19bcc501953d2fe6c180bb4f66fa18b52de9a9e16e55f25dac2437a1e
public_html/cover-letter-studio.js 835a0f1c2cf6e392d3ed63f50c72f80c4af5ff42807cddbabfb6286bf9062689
public_html/crash-guard.js 26bec723c0f93ac9cc6076a85713c31db02305881836ef596b24a998483b5a36
public_html/fomo-popup.js b21bf3f9d8cc948539c46d919c36017368bbe1e24668e11ac74839b762ed50b7
public_html/owner-public.js b99dc2b0f3b8f0c63bdd14d151b3e8994ce40f05905743e63d241ca298aae64b
public_html/plans-ui.js 5eed7e124c0a262c85cdfece80a14f1bffa852333e767eaee1724d038b30b337
public_html/preview.js 251acbe50a59e6d67af86065550fb344ca6ef4d7a3bead7ac687a77b7cdfebf2
public_html/resume-studio.js 9eda66391e5e4c67bb9c603ee510b520a33ba6169d25d7adcb309db4994040c6
public_html/site-polish.css 24343d8dfc90afdb37c80df45dda5f844e65a9e3066470bbb6ceb7fdc9887b98
LIST
grep -q '"version": *"3.29.8"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.29.8"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3300.done; rm -f $W/d3300.lock; exit 1; }
B=$W/backup_3300_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3300.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3300.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3300.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3300 && tar -xzf $W/cvt3300.tgz -C $W/x3300 && cp -a $W/x3300/cvtara_app/. cvtara_app/ && cp -a $W/x3300/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3300/BUILD_INFO.json $b; done
echo "copied $(find $W/x3300 -type f | wc -l) files"
for f in $(cd $W/x3300 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep contact_details; php artisan optimize:clear; cd $H
php $W/rebuild3300.php
for u in / /login /contact.html /sitemap.xml /templates.html /preview.js /site-polish.css /OneSignalSDKUpdaterWorker.js; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
echo "contact-indiqube $(curl -s https://cvtara.com/contact.html | grep -c 'IndiQube')"
cd cvtara_app && php artisan tinker --execute='$l=App\Services\Settings::get("legal"); echo json_encode(["address"=>$l["address"],"hours"=>$l["hours"],"phone"=>$l["phone"],"x"=>$l["x"]??null]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3300; touch $W/d3300.done; rm -f $W/d3300.lock

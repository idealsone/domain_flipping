#!/bin/bash
# CVTara 3.35.1 overlay deploy on 3.35.0 (PR #47). Owner approved in the thread on 2026-11-07. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3351.log; mkdir -p $W
if [ -f $W/d3351.done ] || [ -f $W/d3351.lock ]; then cat $L; exit 0; fi
touch $W/d3351.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/b2b60c9091311fe2430dfa1f50caa8550777059d/deploy-3351
cd $W && curl -fsSL "$SRC/cvt3351.tgz" -o cvt3351.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3351.php || { echo "ABORT download"; touch $W/d3351.done; exit 1; }
echo "b191edfba7fc0e0ea9278d45d92ff88fc9e6cabd4167d98e297442c22517440d  cvt3351.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3351.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/ApiController.php 18e0fd0a12002ea98bbd72819767b2c528645789aaeb9734f9ad58166380efaa 18e0fd0a12002ea98bbd72819767b2c528645789aaeb9734f9ad58166380efaa
cvtara_app/app/Services/Credits.php c5facd4306d114a26d15caf28ecbf87c2b67637f248e5dd3bf3a79e763286131
cvtara_app/app/Services/Settings.php e37a0bc013c2c592b2189855e3a3258c25d096e2a05f5e5f132719547d250104
cvtara_app/app/Services/TaraBusiness.php 09e4d5730a3fc234355a8e403df69b8f252bfd578c37d35bc7c8b009213cc57c
cvtara_app/database/migrations/2026_11_07_000003_privacy_first_party_analytics.php ABSENT
cvtara_app/resources/data/defaults.json b675a8663579abfc7e85e9b7f13821ccfef0fceb7f27557cdf79f4ad9a733afa b675a8663579abfc7e85e9b7f13821ccfef0fceb7f27557cdf79f4ad9a733afa
cvtara_app/resources/frontend/privacy.html 7588a1fa33bb82928654a1bb12fa22fb59b5b8be12956ee2ea6163312ac554b0
cvtara_app/resources/console/owner.js 8f89e8a6183503023375bc40ffedf9aa922effdb7b909bfee892b539f62b6220 e5855a75c7021a0416ec67d79b980fa87ab4daaabeadab3de74677e303bb0145
public_html/owner-public.js e76eb551ac128a4fa53e1c0cc8d651e0b2a737ec0c302e7dea78aa8c7719a449 7f800ebcc3b8aeb674e9b8bd825bd4f480f7764ca6d88f33b3f63eda64fefe91 7f800ebcc3b8aeb674e9b8bd825bd4f480f7764ca6d88f33b3f63eda64fefe91
LIST
grep -q '"version": *"3.35.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.35.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3351.done; rm -f $W/d3351.lock; exit 1; }
B=$W/backup_3351_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3351.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3351.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3351.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3351 && tar -xzf $W/cvt3351.tgz -C $W/x3351 && cp -a $W/x3351/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
[ -d $W/x3351/public_html ] && { cp -a $W/x3351/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3351/BUILD_INFO.json $b; done
echo "copied $(find $W/x3351 -type f | wc -l) files"
for f in $(cd $W/x3351 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep -E 'privacy_first_party'; php artisan optimize:clear; cd $H
php $W/rebuild3351.php
for u in / /login /privacy.html /pricing.html /owner-public.js /workspace.html; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "privacy GA mentions: $(curl -s https://cvtara.com/privacy.html | grep -c 'Google Analytics')"
echo "consent box in script: $(curl -s https://cvtara.com/owner-public.js | grep -c 'Allow analytics')"
cd cvtara_app && php artisan tinker --execute='$a=App\Services\Settings::access(3); echo json_encode(["label"=>App\Services\Settings::planLabel($a), "credits"=>App\Services\Credits::status(3)["resumes"]]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3351; touch $W/d3351.done; rm -f $W/d3351.lock

#!/bin/bash
# CVTara 3.35.2 overlay deploy on 3.35.1 (Job Hunt 10 + 10, PR #47). Owner approved in the thread on 2026-11-07. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3352.log; mkdir -p $W
if [ -f $W/d3352.done ] || [ -f $W/d3352.lock ]; then cat $L; exit 0; fi
touch $W/d3352.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/97e11baa3993cf733ada977228340b22a7c7a0ff/deploy-3352
cd $W && curl -fsSL "$SRC/cvt3352.tgz" -o cvt3352.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3352.php || { echo "ABORT download"; touch $W/d3352.done; exit 1; }
echo "846f7e543b80250bca6358ea1391ec4d7c440aee8949621999cb1619927d7fca  cvt3352.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3352.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/ApiController.php 491fb6e5f5d78e828546ddf563f18929e6c199ccdbbd3feb109ee1d967816507
cvtara_app/app/Services/Credits.php a8f3a96ebec3f13b3e121a913559ad8292269fc6207b88dca617664a0a1cb378
cvtara_app/app/Services/Settings.php 63e5b821d382ca4a16489796cab3ed2ea78239dee45120017702dc273815f6a3
cvtara_app/app/Services/MailAutomation.php 4a16398e4467fec487c70588baf2c748ccc333e3941a3a0c2ab0b24a6f9bde35
cvtara_app/database/migrations/2026_11_07_000004_job_hunt_ten.php ABSENT
LIST
grep -q '"version": *"3.35.1"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.35.1"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3352.done; rm -f $W/d3352.lock; exit 1; }
B=$W/backup_3352_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3352.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3352.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3352.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3352 && tar -xzf $W/cvt3352.tgz -C $W/x3352 && cp -a $W/x3352/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
[ -d $W/x3352/public_html ] && { cp -a $W/x3352/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3352/BUILD_INFO.json $b; done
echo "copied $(find $W/x3352 -type f | wc -l) files"
for f in $(cd $W/x3352 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep -E 'job_hunt_ten'; php artisan optimize:clear; cd $H
php $W/rebuild3352.php
for u in / /login /pricing.html /workspace.html; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
cd cvtara_app && php artisan tinker --execute='$p=App\Services\Settings::plans()["job-hunt"]; echo json_encode(["plan"=>[$p["savedResumes"],$p["savedLetters"],$p["atsScans"],$p["perks"][0]], "orders"=>DB::table("purchases")->where("plan","job-hunt")->where("access_until",">",now())->pluck("limits_json")->map(fn($j)=>[json_decode($j,true)["savedResumes"]??null,json_decode($j,true)["savedLetters"]??null])]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3352; touch $W/d3352.done; rm -f $W/d3352.lock

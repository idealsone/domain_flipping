#!/bin/bash
# CVTara 3.31.1 overlay deploy on 3.31.0 (clearer Free/Quick settings in Packages, PR #45). Owner typed "deploy 3.31.1" on 2026-11-06. No migration. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3311.log; mkdir -p $W
if [ -f $W/d3311.done ] || [ -f $W/d3311.lock ]; then cat $L; exit 0; fi
touch $W/d3311.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-31-1
cd $W && curl -fsSL "$SRC/cvt3311.tgz" -o cvt3311.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3311.php || { echo "ABORT download"; touch $W/d3311.done; exit 1; }
echo "be149f1838496b2f50a12000af2c16846b122ac3947fa594ee17224917fe8aa6  cvt3311.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3311.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/resources/console/extras.js e95fdacbad2c1bd8bfeb5779ab912d4cea81118914a4e188d196b5290c39e656 9263cf87409e3e62431781e72665c30530e8693d2b4eb7b48d9ac4b1c6bfc209
cvtara_app/app/Services/Settings.php c6e2e7ff5492f260c2fff38a67c3b7560cc1cb8c8a935769fbb1af89a8204fb7
LIST
grep -q '"version": *"3.31.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.31.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3311.done; rm -f $W/d3311.lock; exit 1; }
B=$W/backup_3311_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3311.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3311.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3311.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3311 && tar -xzf $W/cvt3311.tgz -C $W/x3311 && cp -a $W/x3311/cvtara_app/. cvtara_app/ && cp -a $W/x3311/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3311/BUILD_INFO.json $b; done
echo "copied $(find $W/x3311 -type f | wc -l) files"
for f in $(cd $W/x3311 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan optimize:clear; cd $H
php $W/rebuild3311.php
for u in / /login /contact.html /sitemap.xml /preview.js /home-ui.js /site-polish.css; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' 'https://cvtara.com/api/admin/report?dataset=ai_spend')"
echo "public-content $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/public-content)"
cd cvtara_app && php artisan tinker --execute='$p=App\Services\Settings::plans()["free"]; echo json_encode([$p["savedResumes"],$p["savedLetters"]]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3311; touch $W/d3311.done; rm -f $W/d3311.lock

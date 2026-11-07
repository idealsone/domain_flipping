#!/bin/bash
# CVTara V2 overlay deploy: 3.35.1/3.35.2 -> 3.46.0 (PR #48). Owner approved in the V2 thread on 2026-11-07. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d346.log; mkdir -p $W
if [ -f $W/d346.done ] || [ -f $W/d346.lock ]; then tail -c 3500 $L; exit 0; fi
touch $W/d346.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/2f69e4f6afa5c9d34a20caee97bcb78ce694e359/deploy-346
cd $W && curl -fsSL "$SRC/cvt346.tgz" -o cvt346.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild346.php && curl -fsSL "$SRC/checks.txt" -o checks346.txt || { echo "ABORT download"; touch $W/d346.done; exit 1; }
echo "75accbca4c50731476068a167e9d5611ea56521a2d0265d25c336ff4ced3d54d  cvt346.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d346.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then if [ "$k" = S ]; then echo "MISMATCH $p $cur"; bad=1; else echo "note: $p differs from expected ($cur), backed up and replaced"; fi; fi; done < $W/checks346.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -qE '"3\.35\.[12]"' || { echo "MISMATCH live version"; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d346.done; rm -f $W/d346.lock; exit 1; }
B=$W/backup_346_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt346.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d346.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d346.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x346 && tar -xzf $W/cvt346.tgz -C $W/x346 || { echo "ABORT extract"; touch $W/d346.done; exit 1; }
bad=0; for f in $(cd $W/x346 && find cvtara_app -name '*.php'); do php -l $W/x346/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d346.done; exit 1; }
cp -a $W/x346/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
[ -d $W/x346/public_html ] && { cp -a $W/x346/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x346/BUILD_INFO.json $b; done
echo "copied $(find $W/x346 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; php artisan migrate --force; php artisan migrate:status | grep -E '2026_11_(07_000004|08|09|10|11|12|13|14|15)'; cd $H
php $W/rebuild346.php
for u in / /login /pricing.html /workspace.html /admin.html /customer-guide.html /context-help.js /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
cd cvtara_app && php artisan tinker --execute='echo json_encode(["tables"=>collect(["seo_content_versions","seo_page_audits","audit_logs","ai_tasks","approval_events","support_sessions","service_requests","payment_reconciliation_records","user_roles"])->mapWithKeys(fn($t)=>[$t=>Illuminate\Support\Facades\Schema::hasTable($t)]),"jobhunt"=>App\Services\Settings::plans()["job-hunt"]["savedLetters"]??null]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 600 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x346; touch $W/d346.done; rm -f $W/d346.lock

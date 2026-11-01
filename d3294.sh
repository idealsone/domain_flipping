#!/bin/bash
# CVTara 3.29.4 overlay deploy on live 3.29.3 (PR #42: What's new notes for customers). No migration. Owner approved ("Yes deploy") 2026-11-01 17:58 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3294.log; mkdir -p $W
if [ -f $W/d3294.done ] || [ -f $W/d3294.lock ]; then cat $L; exit 0; fi
touch $W/d3294.lock; exec >>$L 2>&1; echo "== start $(date -u)"
fail(){ echo "ABORT $1"; touch $W/d3294.done; rm -f $W/d3294.lock; exit 1; }
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-29-4
cd $W && curl -fsSL "$SRC/cvt3294.tgz" -o cvt3294.tgz || fail download
echo "6d60b71b3623d6a2f7807b0874ad80c56ca5b342acf277f4612e3640e6e1d568  cvt3294.tgz" | sha256sum -c - || fail "tgz checksum"
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php ab8c324f2ee77605142b3d17c67b61e6857cc9e1d1b98faa1461825cc558aa14
cvtara_app/app/Http/Controllers/ApiController.php 425bc4ffad40db4faf7d22f0d9e1805f25c61e56e09b9f3d374e8a1572980b94
cvtara_app/app/Http/Controllers/WhatsNewAdminController.php ABSENT
cvtara_app/app/Services/Settings.php f0782e2cf0a5030ecf77805af7a7057eb86f693c046c9dee3d349455b5469b7b
cvtara_app/app/Services/WhatsNew.php ABSENT
cvtara_app/resources/frontend/admin.html 9177ff124bdf255a63a23aa63068a16c9b3635d6f6526d66a592faee831f8ca2
cvtara_app/resources/frontend/workspace.html 78ebb9e2b043487095a4822ec4cfbe165edae6313ddbc818096a34dec08cd381
cvtara_app/routes/web.php cc6fb5271c7d02c2c185c3f0731966c5fd6fdd5703a83bbe44a352071a6297de
cvtara_app/resources/console/whatsnew.js ABSENT
public_html/nav-icons.js 8e3e812f039a809260c44feffc2d7a2662284601a9f87e5b21e046580f893b0d
public_html/whats-new.js ABSENT
public_html/whats-new.css ABSENT
LIST
grep -q '"version": *"3.29.3"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.29.3"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || fail "live files differ from the expected base; nothing changed"
B=$W/backup_3294_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(for f in cvtara_app/app/Http/Controllers/AdminController.php cvtara_app/app/Http/Controllers/ApiController.php cvtara_app/app/Http/Controllers/WhatsNewAdminController.php cvtara_app/app/Services/Settings.php cvtara_app/app/Services/WhatsNew.php cvtara_app/resources/frontend/admin.html cvtara_app/resources/frontend/workspace.html cvtara_app/routes/web.php cvtara_app/resources/console/whatsnew.js public_html/nav-icons.js public_html/whats-new.js public_html/whats-new.css ; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && echo "files backup $(tar -tzf $B/files.tgz | wc -l) entries" || fail "files backup"
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || fail "database backup"
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3294 && tar -xzf $W/cvt3294.tgz -C $W/x3294 || fail extract
cp -a $W/x3294/cvtara_app/. cvtara_app/ && cp -a $W/x3294/public_html/. public_html/ || echo "COPY FAILED, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3294/BUILD_INFO.json $b; done
echo "copied $(find $W/x3294 -type f | wc -l) files"
cd cvtara_app; for f in app/Http/Controllers/AdminController.php app/Http/Controllers/ApiController.php app/Http/Controllers/WhatsNewAdminController.php app/Services/Settings.php app/Services/WhatsNew.php routes/web.php; do php -l $f | head -1; done
php artisan optimize:clear | tail -2
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo "notes shown: ".count(App\Services\WhatsNew::published()).PHP_EOL;'
cd $H
for u in / /login /workspace.html /sitemap.xml /api/health /whats-new.js /whats-new.css; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "api/whats-new anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/api/whats-new)"
echo "console/whatsnew.js anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/whatsnew.js)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3294; touch $W/d3294.done; rm -f $W/d3294.lock

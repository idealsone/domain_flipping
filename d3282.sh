#!/bin/bash
# CVTara 3.28.2 overlay deploy (PR #40: owner sign-in email code, flood caps, symfony/mime CVE fix, security.txt) on top of live 3.28.0; approved by the owner 2026-10-31 23:45 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3282.log; mkdir -p $W
if [ -f $W/d3282.done ] || [ -f $W/d3282.lock ]; then cat $L; exit 0; fi
touch $W/d3282.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-28-2
cd $W && curl -fsSL "$SRC/cvt3282.tgz" -o cvt3282.tgz || { echo "ABORT download"; touch $W/d3282.done; rm -f $W/d3282.lock; exit 1; }
echo "d2e081f0379d20cc493db2fe250722a5f2a01d249f52485ec51a8ef04ecc82fe  cvt3282.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3282.done; rm -f $W/d3282.lock; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/ApiController.php 9864d55733bb2b9c731d67d845297f5d5d34d43de91a8e560e4fd6204fa4002d
cvtara_app/app/Http/Controllers/AuthController.php 0ee255fee82c9827240e5fecd733968148f9b6c38245ed66693a3af631101518
cvtara_app/config/app.php a4b47313db4daae66c3f65887093422a45ee0b52b94941ffe4c7c88c8f8f20ca
cvtara_app/resources/views/auth.blade.php d17e4a506fff598fa18b6d467484975b09000921830f8afe3bc5e24d8c461790
cvtara_app/routes/web.php b4a7aabdf9d01215f60e4ea388cad50686c3df88e7a4c5af4371aee6819a60b6
public_html/.well-known/security.txt ABSENT
LIST
[ -d cvtara_app/vendor/symfony/mime ] || { echo "MISMATCH vendor/symfony/mime missing"; bad=1; }
echo "live mime: $(grep -A2 '"name": "symfony/mime"' cvtara_app/vendor/composer/installed.json | grep '"version"')"
grep -q '"version": *"3.28.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.28.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3282.done; rm -f $W/d3282.lock; exit 1; }
B=$W/backup_3282_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz cvtara_app/app/Http/Controllers/ApiController.php cvtara_app/app/Http/Controllers/AuthController.php cvtara_app/config/app.php cvtara_app/resources/views/auth.blade.php cvtara_app/routes/web.php cvtara_app/vendor/symfony/mime $(ls cvtara_app/composer.lock BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && echo "files backup $(tar -tzf $B/files.tgz | wc -l) entries" || { echo "ABORT files backup"; touch $W/d3282.done; rm -f $W/d3282.lock; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3282.done; rm -f $W/d3282.lock; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3282 && tar -xzf $W/cvt3282.tgz -C $W/x3282 || { echo "ABORT extract"; touch $W/d3282.done; rm -f $W/d3282.lock; exit 1; }
rm -rf cvtara_app/vendor/symfony/mime.new && cp -a $W/x3282/cvtara_app/vendor/symfony/mime cvtara_app/vendor/symfony/mime.new && mv cvtara_app/vendor/symfony/mime cvtara_app/vendor/symfony/mime.old && mv cvtara_app/vendor/symfony/mime.new cvtara_app/vendor/symfony/mime && rm -rf cvtara_app/vendor/symfony/mime.old
rm -rf $W/x3282/cvtara_app/vendor
cp -a $W/x3282/cvtara_app/. cvtara_app/ && cp -a $W/x3282/public_html/. public_html/ || echo "COPY FAILED, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3282/BUILD_INFO.json $b; done
echo "copied"
cd cvtara_app && php -l app/Http/Controllers/AuthController.php && php -l app/Http/Controllers/ApiController.php && php artisan optimize:clear
php artisan tinker --execute='echo "owner code required: ".json_encode(App\Http\Controllers\AuthController::ownerCodeRequired())." mime ".(class_exists("Symfony\\Component\\Mime\\Email")?"ok":"MISSING");' 2>&1 | tail -1
php artisan tinker --execute='echo "session IPs last 24h: ".DB::table("sessions")->where("last_activity",">",time()-86400)->distinct()->count("ip_address")." distinct of ".DB::table("sessions")->where("last_activity",">",time()-86400)->count()." sessions; top: ".json_encode(DB::table("sessions")->where("last_activity",">",time()-86400)->selectRaw("left(ip_address,7) p, count(*) n")->groupBy("p")->orderByDesc("n")->limit(3)->get());' 2>&1 | tail -1
cd $H
for u in / /login /sanaulla /sitemap.xml /.well-known/security.txt /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-users-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3282; touch $W/d3282.done; rm -f $W/d3282.lock

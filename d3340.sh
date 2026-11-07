#!/bin/bash
# CVTara 3.34.0 overlay deploy on 3.33.0. Owner chose deploying 3.33.0, 3.34.0 and 3.35.0 one by one (2026-11-07). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3340.log; mkdir -p $W
if [ -f $W/d3340.done ] || [ -f $W/d3340.lock ]; then cat $L; exit 0; fi
touch $W/d3340.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-34-0
cd $W && curl -fsSL "$SRC/cvt3340.tgz" -o cvt3340.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3340.php || { echo "ABORT download"; touch $W/d3340.done; exit 1; }
echo "66c8150a98a0e2000c4eb89bddd079f3ec352916d5a47a4d6165d62e374de51e  cvt3340.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3340.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/ApiController.php 9007d92b03e17e571d11a301766dfdb375cdd81afe22651a51ff022e9790c4e1 ef824518e83437b667cddeb37fd001d5ee9b7576b832751f786f4feb3320639a f07845094ec401d794e939e9ed5c34e00e1be9a6de96ce02959abf60a2b0beb5
cvtara_app/app/Http/Controllers/AuthController.php 0ee255fee82c9827240e5fecd733968148f9b6c38245ed66693a3af631101518 b3f21bc7e58059dfa748f015a5d72c0b7080b2b43a8bfcb52017372b6711864c
cvtara_app/app/Http/Controllers/GrowthAdminController.php 8781371ea673070b1fb627054c1ece8cda45ca76ca9bf9c6e188948aeb483ceb eba27ba4af2ea3099fec1d404e4da56f793e1b81e3cafcaf94255daf3279a4b6
cvtara_app/app/Services/AgentLog.php ABSENT
cvtara_app/app/Services/Attribution.php ABSENT
cvtara_app/app/Services/Growth.php 0b2a6fa78e86f13a9285a1f54881957f4968ac047c755ba5b7614f50abf1c75a 6ab658530da15783aefbbd260ca6a26a8c4083296a8efe6b0aeddb56e36e922f
cvtara_app/app/Services/GrowthOps.php ABSENT
cvtara_app/app/Services/SeoAgent.php c29eb16514bd7929177d9281ebd1d021cbeb5c6af68ef2465b8a12839d209b94 e4a6bb10dd1726a32969f88b8d53d45273b37dc798b47502e0d6262960fdcb0a
cvtara_app/app/Services/SeoOps.php ABSENT
cvtara_app/database/migrations/2026_11_07_000001_agent_operations.php ABSENT
cvtara_app/resources/console/agents.js ABSENT
cvtara_app/resources/console/growth.js 03de563a2abba552711cfdf8c87f6096b71ac2a3489c946588842557ac2d9f77 a51222316d4ccf2275444e475b0d7e1dcde074bd522e8d37044b0078d1eb8c07 cc35c989ca1de888aec1ef233229ac988ac654ff4d8ad04812d39e9689acb28a
cvtara_app/resources/frontend/admin.html 1d3be0dd8202f3b112f413e8ac6ae5fe065a4ccafeabe10cd4b1127349c22a8b dd935a7a8fd9531bddcc5c094182e6dec9f9fd3dd24bcc07dbe7e92d7c9caecc
cvtara_app/routes/console.php 7e66d5ca59596a2b205d741710c831dfc32706c4bc7a05a5162f06e54a4521f1 b143cf23fa244fd96daac913bd72b3637590c4b7e99ff032722d55f78f1b0cc6
cvtara_app/routes/web.php 0dbf056094afd9b116f34e78ec4c32323299f23ec7a6ae4f12b2a680a37c8a34 59e22d8e208016befdd6b4ecb630a01bf9e6920f96c240f86fff12189dd4f1b2
public_html/owner-public.js 0b3a97cc468d99f8178912b6b14da667dabdb0ed5c3bc4f9bace6118d82c36a9 5058449d31abf5a6046185378ab5e315c6114d1788f493fed74d938d3dc569b6 d3452377adc66243c204872b8eb928f3d3eb2d960c65edb99cf6520402bfaf42
LIST
grep -q '"version": *"3.33.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.33.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3340.done; rm -f $W/d3340.lock; exit 1; }
B=$W/backup_3340_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3340.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3340.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3340.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3340 && tar -xzf $W/cvt3340.tgz -C $W/x3340 && cp -a $W/x3340/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
[ -d $W/x3340/public_html ] && { cp -a $W/x3340/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3340/BUILD_INFO.json $b; done
echo "copied $(find $W/x3340 -type f | wc -l) files"
for f in $(cd $W/x3340 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep -E 'agent_operations'; php artisan optimize:clear; cd $H
php $W/rebuild3340.php
for u in / /login /contact.html /sitemap.xml /robots.txt /owner-public.js /tara-chat.js /support-widget.js; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' 'https://cvtara.com/api/admin/growth/agents')"
echo "public-content $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/public-content)"
cd cvtara_app && php artisan tinker --execute='echo json_encode([Illuminate\Support\Facades\Schema::hasTable("agent_runs"), Illuminate\Support\Facades\Schema::hasColumn("users","signup_source")]);' 2>&1 | tail -2; php artisan schedule:list 2>&1 | tail -6; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3340; touch $W/d3340.done; rm -f $W/d3340.lock

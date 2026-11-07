#!/bin/bash
# CVTara 3.33.0 overlay deploy on 3.32.0. Owner chose deploying 3.33.0, 3.34.0 and 3.35.0 one by one (2026-11-07). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3330.log; mkdir -p $W
if [ -f $W/d3330.done ] || [ -f $W/d3330.lock ]; then cat $L; exit 0; fi
touch $W/d3330.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-33-0
cd $W && curl -fsSL "$SRC/cvt3330.tgz" -o cvt3330.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3330.php || { echo "ABORT download"; touch $W/d3330.done; exit 1; }
echo "c87d205c4a19969754386912983bb27a14fa54f0b713553dc1e97eb49ceb86f7  cvt3330.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3330.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/ApiController.php ef824518e83437b667cddeb37fd001d5ee9b7576b832751f786f4feb3320639a f07845094ec401d794e939e9ed5c34e00e1be9a6de96ce02959abf60a2b0beb5
cvtara_app/app/Http/Controllers/TaraAdminController.php 73134f8cec70c16fc5ab1457e12b3be06326b9aba10608ec6194b8cc7a25e5f5
cvtara_app/app/Services/AccountEraser.php b97400c722716a402eabf0f031a7aea2c6dbaffbe00696dd0ceb847c613717ab
cvtara_app/app/Services/Retention.php f0855a4714a93fd110125a44fa26cf945060fe67f6656dab793a154fcdd6d5a0
cvtara_app/app/Services/TaraBusiness.php 2671192bdddbc35110077d134005f37809bcc8ba2a0b6f471314830ee766dba2
cvtara_app/app/Services/TaraChat.php 842240edfae52320df5aee6adc4e50873e4cf4dc476a44ed9b3a15baf46a78ff
cvtara_app/app/Services/TaraGuard.php ABSENT
cvtara_app/app/Services/TaraOwner.php 270ad1829d9f1c94a55560545565ee2bed454851e8795fcab9c81cc317ef05b3
cvtara_app/database/migrations/2026_11_06_000004_tara_events.php ABSENT
cvtara_app/resources/console/assistant.js 22c8c1b9978db70868eb2294372d82ca668427de53867da4d25e822858005c6f cbbd3dda753bbf09b8dbc43b6be3a7c18ae86e80467761c826c214d8e418a1f2
cvtara_app/resources/console/ops.js 556aa38ce948a45601a6ed9b207ecc3c14bafe6fa259814d052902c4d40de362 5c7ae7b9932df2eeb97e0b4b59ecf0face7f0144cf1edf8de9cbea6f3445613e 618b87268a6c9bddcee67d0e41b351f3c2185b70b302e6e02a6c2812fafb36b3 6e68c58b227072c85d869ef80a1133e2e5fb7ea453bd7c689df99d64de2cca83 740102f01cc9c2530693149de1e9237c8c931d54b415b2f2c5881014d256a1d1 a87880b280d730116b73dff777ab18f79caed9f921fec27ee3de84e220c00d82 a947cd1f8ba03dbe9ef0dc64839e3abd9f7ee6df21b4cd0abb9f474c520ad040 d3d46a4b532ced0cfb12b450aa7ee422000bb5aff9ed3504306bd88796bf670f
cvtara_app/resources/console/tara.js 5b964d2c9ae571ee036a214296a16ae9687ee113decdcfbae4e91593bfec8304 e89b21c5bff264fa337643b642a6543d0a95fa4855c4d0fc292f61a6f4561949
public_html/support-widget.css cc0472ae1eeb69e1d56acf96488f8848045adfe06937a5508f30f6fdb074ffb5
public_html/support-widget.js 32865468bcac8d3549eb94c5c0dd355dfedf08463113a58c219dbc64b052d595 aa066c1e1645226554a1409b55a388dad0aa8ae80092c4e606759a7fe05d5362
public_html/tara-chat.css 2ff318d6e8f83bdcb3a11ce6c17e1d698e15b6c6b4bfd9a513dc1222dd912c39
public_html/tara-chat.js 69a9b1022d75976576ae0b2a5f6f64e56999d1681e0baf0a837e1cf95527fbe8 efa953c97c3f8d25402bb1dde08981f845e825cec3057d5bdd22659b209a2954
LIST
grep -q '"version": *"3.32.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.32.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3330.done; rm -f $W/d3330.lock; exit 1; }
B=$W/backup_3330_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3330.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3330.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3330.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3330 && tar -xzf $W/cvt3330.tgz -C $W/x3330 && cp -a $W/x3330/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
[ -d $W/x3330/public_html ] && { cp -a $W/x3330/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3330/BUILD_INFO.json $b; done
echo "copied $(find $W/x3330 -type f | wc -l) files"
for f in $(cd $W/x3330 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep -E 'tara_events'; php artisan optimize:clear; cd $H
php $W/rebuild3330.php
for u in / /login /contact.html /sitemap.xml /robots.txt /owner-public.js /tara-chat.js /support-widget.js; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' 'https://cvtara.com/api/admin/growth/agents')"
echo "public-content $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/public-content)"
cd cvtara_app && php artisan tinker --execute='echo json_encode([Illuminate\Support\Facades\Schema::hasTable("tara_events"), App\Services\TaraChat::enabled()]);' 2>&1 | tail -2; php artisan schedule:list 2>&1 | tail -6; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3330; touch $W/d3330.done; rm -f $W/d3330.lock

#!/bin/bash
# CVTara 3.35.0 overlay deploy on 3.34.0. Owner chose deploying 3.33.0, 3.34.0 and 3.35.0 one by one (2026-11-07). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3350.log; mkdir -p $W
if [ -f $W/d3350.done ] || [ -f $W/d3350.lock ]; then cat $L; exit 0; fi
touch $W/d3350.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-35-0
cd $W && curl -fsSL "$SRC/cvt3350.tgz" -o cvt3350.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3350.php || { echo "ABORT download"; touch $W/d3350.done; exit 1; }
echo "8cbd488dc776f91eb228620b696aa9584a5224af7cde30985600dd191c4e2a1f  cvt3350.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3350.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/ApiController.php 02798d79a165d401a3b4359549282400c947a0cd05f78f2d1e557677afec0151 ef824518e83437b667cddeb37fd001d5ee9b7576b832751f786f4feb3320639a f07845094ec401d794e939e9ed5c34e00e1be9a6de96ce02959abf60a2b0beb5
cvtara_app/app/Http/Controllers/ContentController.php 591a605a4d0bfb9fec005d24d50dbcac5f218c774d238396fc3284e0ba849c3f 5f099a3c4594ef922844c1b9506d7c1e3240d1c34caeb128987099972d611b76
cvtara_app/app/Http/Controllers/GrowthAdminController.php 8781371ea673070b1fb627054c1ece8cda45ca76ca9bf9c6e188948aeb483ceb 8fbbec2fd236e842cafc9378d20f14e86a4c3941d5702ed282507d4bb02226a5 eba27ba4af2ea3099fec1d404e4da56f793e1b81e3cafcaf94255daf3279a4b6
cvtara_app/app/Services/AiVisibility.php ABSENT
cvtara_app/app/Services/Attribution.php 425632d49cda3c60fb8a4b53ca2d2f5743d91acebf8234c1bc07ca9045892b3b
cvtara_app/app/Services/Campaigns.php 9252b3e8db34d642d193a771cbcb4ea42ae73720241816eab6dadb911dbbb0ac
cvtara_app/app/Services/CustomerEvents.php ABSENT
cvtara_app/app/Services/EmailRenderer.php 703d03893a800d0f509e7a3ec4da085ab137c4556dd6b361acc43f123eae6b59 8b231f81afbb54f807fa64c9cceef63e1f8b5df36e9cc428f3158e6b14b4476f
cvtara_app/app/Services/Growth.php 0b2a6fa78e86f13a9285a1f54881957f4968ac047c755ba5b7614f50abf1c75a 6ab658530da15783aefbbd260ca6a26a8c4083296a8efe6b0aeddb56e36e922f ffda0c8deec199c5ab12eda60d82788cb64a8755ff52b4aa80eee87a5714a8b5
cvtara_app/app/Services/GrowthOps.php 9e956a97558e2de63341204b151af732688642456f2917221469e1ad4b66512a
cvtara_app/app/Services/Lifecycle.php ABSENT
cvtara_app/app/Services/LinkCrm.php ABSENT
cvtara_app/app/Services/MailAutomation.php 56b2d04cb90686f5b9134d2bcd4599ff4dd8297c0f91f287de9b40fb59eccad8 6a58deca2b2ce32693b4fefd3caf432d713d636f55006046ca7173a7600585e9
cvtara_app/app/Services/Payments.php 6cc779ca2e12b6156e20027c1ae4b2204109062c63237e9b2e0240d6bb1cb637
cvtara_app/app/Services/Scorecard.php ABSENT
cvtara_app/app/Services/SeoAgent.php 528ce4cf97b747bcd86eea713e8a66e9107ed3fd750697bbbcb94b3aade6b710 c29eb16514bd7929177d9281ebd1d021cbeb5c6af68ef2465b8a12839d209b94 e4a6bb10dd1726a32969f88b8d53d45273b37dc798b47502e0d6262960fdcb0a
cvtara_app/database/migrations/2026_11_07_000002_growth_engine.php ABSENT
cvtara_app/resources/console/growth.js 03de563a2abba552711cfdf8c87f6096b71ac2a3489c946588842557ac2d9f77 47b403d0de2f530a212e3e1f5d8733abc74fe7917d82299e6ecb81ac57d5031f a3f270d25e7601e81f9b05f6a53ec594b3bfac94d6f0d9d9f9610eb83674ab95 a51222316d4ccf2275444e475b0d7e1dcde074bd522e8d37044b0078d1eb8c07
cvtara_app/resources/console/operators.js ABSENT
cvtara_app/resources/data/defaults.json 747d0d16d09a97a4424a1167d67bd54498b476df6d6ee3390663625b82682e54 f279dff5fabe8f4e2baba9020da2ab04affd7fd75ff5953998c150f3680ab916
cvtara_app/resources/frontend/admin.html 1d3be0dd8202f3b112f413e8ac6ae5fe065a4ccafeabe10cd4b1127349c22a8b 39d72f6350020899f7fbfefff5742f72106c37006c58fc1ae53f4646b5f7d921 dd935a7a8fd9531bddcc5c094182e6dec9f9fd3dd24bcc07dbe7e92d7c9caecc
cvtara_app/routes/console.php 7e66d5ca59596a2b205d741710c831dfc32706c4bc7a05a5162f06e54a4521f1 86bfa2973e086b843a0f129662b633bb3fa5cd7870c7041c8eff86428ef0ee8f b143cf23fa244fd96daac913bd72b3637590c4b7e99ff032722d55f78f1b0cc6
cvtara_app/routes/web.php 0d1abe6b4e4de8abef29970371e839c369986eb8d7bbe374a5586feb8c2637ee 0dbf056094afd9b116f34e78ec4c32323299f23ec7a6ae4f12b2a680a37c8a34 59e22d8e208016befdd6b4ecb630a01bf9e6920f96c240f86fff12189dd4f1b2
public_html/owner-public.js 0b3a97cc468d99f8178912b6b14da667dabdb0ed5c3bc4f9bace6118d82c36a9 0f57742ed3192c000994ad0faf915dda0d87318ab177fc4aa40a0b880b7ee455 5058449d31abf5a6046185378ab5e315c6114d1788f493fed74d938d3dc569b6 6f181eaea8e1d17cebd7ee3539da18b258d3dda695f6177eb7d5c264c6d20573
LIST
grep -q '"version": *"3.34.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.34.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3350.done; rm -f $W/d3350.lock; exit 1; }
B=$W/backup_3350_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3350.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3350.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3350.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3350 && tar -xzf $W/cvt3350.tgz -C $W/x3350 && cp -a $W/x3350/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
[ -d $W/x3350/public_html ] && { cp -a $W/x3350/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3350/BUILD_INFO.json $b; done
echo "copied $(find $W/x3350 -type f | wc -l) files"
for f in $(cd $W/x3350 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep -E 'growth_engine'; php artisan optimize:clear; cd $H
php $W/rebuild3350.php
for u in / /login /contact.html /sitemap.xml /robots.txt /owner-public.js /tara-chat.js /support-widget.js; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' 'https://cvtara.com/api/admin/growth/agents')"
echo "public-content $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/public-content)"
cd cvtara_app && php artisan tinker --execute='echo json_encode([Illuminate\Support\Facades\Schema::hasTable("customer_events"), Illuminate\Support\Facades\Schema::hasTable("link_prospects"), Illuminate\Support\Facades\Schema::hasColumn("purchases","touch_source"), App\Services\Lifecycle::level("growth")]);' 2>&1 | tail -2; php artisan schedule:list 2>&1 | tail -6; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3350; touch $W/d3350.done; rm -f $W/d3350.lock

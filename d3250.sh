#!/bin/bash
# CVTara 3.24.0 overlay deploy (PR #27: fast indexing, clearer Search Console errors); approved by the owner 2026-10-26 11:11 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3250.log; mkdir -p $W
if [ -f $W/d3250.done ] || [ -f $W/d3250.lock ]; then cat $L; exit 0; fi
touch $W/d3250.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-25-0
cd $W && curl -fsSL "$SRC/cvt3250.tgz" -o cvt3250.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3250.php && curl -fsSL "$SRC/errors.php" -o errors3250.php || { echo "ABORT download"; touch $W/d3250.done; exit 1; }
echo "71b73159eac410988535c6289a7cf6b56240c82ff7c617f198b204b3a14d977d  cvt3250.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3250.done; exit 1; }
echo "-- unresolved errors before"; php $W/errors3250.php
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php eb0b39ff03efc150e93fa3954a9d85233bfd75a1839e370028db5777804bb37b
cvtara_app/app/Http/Controllers/AdminOpsController.php e0cd68d5970cf2d84930263995070b632679b520f405e4699aab554aa00ed46b
cvtara_app/app/Http/Controllers/AffiliateAdminController.php fc976bea28968df2c6fab6870cdca94014a5b227a10e7ed34b007a1e1726c347
cvtara_app/app/Http/Controllers/CampaignAdminController.php 4e166996f98c779ef47ab812dac5ba79de001dac1f10276555ef10a5607dea3d
cvtara_app/app/Http/Controllers/OutreachAdminController.php ae47817f70ab2fab1b108c69d04428c36f64d0af1def5bd3f6abffb9c8f14743
cvtara_app/app/Services/AdminTable.php ABSENT
cvtara_app/database/migrations/2026_10_27_000001_console_list_indexes.php ABSENT
cvtara_app/resources/console/advanced.js c655ee5b3d41a0086016c6739c8c3fb9b49289e327d1f014fb7311bb485e7c6a
cvtara_app/resources/console/campaigns.js 55ff4c2328578fa663a5178013c4a37cc5b068a6a6dad2ce29fb5cff3342390c
cvtara_app/resources/console/content.js d945d3f5a7c17dfdfa65a5e4108655bc44190fedee8deb756fba777c16dfa2b6
cvtara_app/resources/console/extras.js 9cfa3101c72507d0412d1a1a7e30b66921a41f4204a67eb461ae2cc3693b1e28
cvtara_app/resources/console/feedback.js 698244b4a2041c62ddc8f758e71cd33c99e8e4aedcd4a06338b02c6aef714349
cvtara_app/resources/console/ops.js d1440b4c81bdd93a312b3e838973faf70a1a1891fe8202df91ccca1238229c4b
cvtara_app/resources/console/outreach.js 212f0bbe848d13db3ba02c3383a9bd7beb9fc5618aa90f8c9994dab0d1471edc
cvtara_app/resources/console/owner.js 176cae33128de94e55d75f55c1b5bc83b4d04353f003a4834f7eae6030cc6e8c
cvtara_app/resources/console/people.js 6064849aba42e98274d091c5bebe8529695dc375ab24ab1273bf97c1457128f3
cvtara_app/resources/console/publish.js 86aac4f4c90307e56649f726ca29e7a150cf5fa8d14bdca6abe9fc557029d5c8
cvtara_app/resources/console/referral.js 837008296280c91a26f883edb3136844e8f804f0e3821211233218c4128d104f
cvtara_app/resources/console/reports.js b934c697566d41b9e97108d66306a1cdf6beec83d18d47944351d1dbe86be0f2
public_html/connected-ui.js 4fdf57784d250c2838ff8c81bcdd537beb10199ea88287ac1ce76c68adb2408f
public_html/cvtara-ds.css 4af7cad714948f372245930c7efb63f52b2c8283916d5591bb6c8fd43a624373
public_html/workspace-activity.js 8d868c9541b7eea6a6381edc90089075708516e69515bec375f6f7e832104f6b
LIST
grep -q '"version": *"3.24.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.24.0"; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3250.done; rm -f $W/d3250.lock; exit 1; }
B=$W/backup_3250_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3250.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3250.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3250.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3250 && tar -xzf $W/cvt3250.tgz -C $W/x3250 && cp -a $W/x3250/cvtara_app/. cvtara_app/ && cp -a $W/x3250/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3250/BUILD_INFO.json $b; done
echo "copied $(find $W/x3250 -type f | wc -l) files"
for f in $(tar -tzf $W/cvt3250.tgz | grep "\.php$"); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force --no-interaction && php artisan optimize:clear; cd $H
php $W/rebuild3250.php
for u in / /login /contact.html /templates.html /sitemap.xml /robots.txt; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-users-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
echo "console-ops-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/ops.js)"
(cd cvtara_app && php artisan migrate:status 2>/dev/null | grep console_list_indexes)
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "-- unresolved errors after"; php $W/errors3250.php | head -5
echo "== done $(date -u)"; rm -rf $W/x3250; touch $W/d3250.done; rm -f $W/d3250.lock

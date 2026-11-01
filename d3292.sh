#!/bin/bash
# CVTara 3.29.2 overlay deploy on live 3.29.1 (PR #36: fairer ATS Match for Indian job posts, empty resume scores 0, no AI wording in public files, ATS checker CTA). One migration (no_ai_keyword). Approved by the owner 2026-10-31 23:50 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3292.log; mkdir -p $W
if [ -f $W/d3292.done ] || [ -f $W/d3292.lock ]; then cat $L; exit 0; fi
touch $W/d3292.lock; exec >>$L 2>&1; echo "== start $(date -u)"
fail(){ echo "ABORT $1"; touch $W/d3292.done; rm -f $W/d3292.lock; exit 1; }
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-29-2
cd $W && curl -fsSL "$SRC/cvt3292.tgz" -o cvt3292.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3292.php || fail download
echo "aecdf54327bd920bf0f6b341de192cff8fbd743db8cf25db6bf2eeb2fed84427  cvt3292.tgz" | sha256sum -c - || fail "tgz checksum"
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Services/AtsEngine.php 7868db1823546c4778ad7e36751eca5e56ee11b95354a5ab4fa8cb28f1be03c5
cvtara_app/app/Services/ResumeQuality.php 5f7ce4c892baf825ffde375a459510118bf92b5f5a90fb06a92a6e02735a62da
cvtara_app/app/Services/SeoPages.php 8f8694d809659c7b178c3e07a5a72c735b2ce7442e7b66ed317ebe0a06ad0422
cvtara_app/resources/data/ats-concepts.json 7960519436ffc581be447df59c76dea724df0af55cd4c4c93403733a48dfb52b
cvtara_app/resources/data/defaults.json 5e0441a145a042af49a7517e16633eaa5e3c0a5e7b67a6591fa4924eafcc4847
cvtara_app/resources/frontend/admin.html 6bbbd0c29918aa31a2dd710275a3553525634367f95de535fd9a4c7121da9f18
cvtara_app/routes/console.php 7e66d5ca59596a2b205d741710c831dfc32706c4bc7a05a5162f06e54a4521f1
cvtara_app/routes/web.php 413ca4d6389a6681a18f7077af03496c78242e98d051a491ae5c1eb0eb04fde3
cvtara_app/database/migrations/2026_11_01_000001_no_ai_keyword.php ABSENT
cvtara_app/resources/console/refunds.js ABSENT
public_html/refunds-admin.js f207b8ebfb0e012d8171bfed00aacbaa9996614cc7603bd9c0ecd1d8eab20cda a0d1d3c6516d2e81f4c91c3c4970f99287bc20d8e4724f58dcb75d51157ec747
LIST
grep -q '"version": *"3.29.1"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.29.1"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || fail "live files differ from the expected base; nothing changed"
B=$W/backup_3292_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(for f in cvtara_app/app/Services/AtsEngine.php cvtara_app/app/Services/ResumeQuality.php cvtara_app/app/Services/SeoPages.php cvtara_app/resources/data/ats-concepts.json cvtara_app/resources/data/defaults.json cvtara_app/resources/frontend/admin.html cvtara_app/routes/console.php cvtara_app/routes/web.php cvtara_app/database/migrations/2026_11_01_000001_no_ai_keyword.php cvtara_app/resources/console/refunds.js public_html/refunds-admin.js ; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && echo "files backup $(tar -tzf $B/files.tgz | wc -l) entries" || fail "files backup"
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || fail "database backup"
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3292 && tar -xzf $W/cvt3292.tgz -C $W/x3292 || fail extract
cp -a $W/x3292/cvtara_app/. cvtara_app/ || echo "COPY FAILED, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3292/BUILD_INFO.json $b; done
rm -f public_html/refunds-admin.js && echo "removed public_html/refunds-admin.js"
echo "copied $(find $W/x3292 -type f | wc -l) files"
cd cvtara_app; for f in app/Services/AtsEngine.php app/Services/ResumeQuality.php app/Services/SeoPages.php routes/web.php routes/console.php database/migrations/2026_11_01_000001_no_ai_keyword.php; do php -l $f | head -1; done
php artisan migrate --force 2>&1 | tail -3; php artisan migrate:status 2>&1 | grep no_ai_keyword
php artisan optimize:clear | tail -2
php artisan tinker --execute='$k=json_decode(Illuminate\Support\Facades\DB::table("site_settings")->where("key","seo")->value("value_json"),true)["pages"]["/"]["keywords"]??"(default)"; echo "home keywords: ".$k.PHP_EOL; $r=App\Services\AtsEngine::report(["personal"=>["name"=>"R","email"=>"r@x.com"],"summary"=>"Fresher B.Com graduate with Tally Prime, GST and Excel.","skills"=>"Tally Prime, GST, Excel","education"=>[["degree"=>"B.Com","school"=>"Pune University"]]],"Accounts Executive\nLocation: Mumbai\nExperience: 0 to 2 years\nResponsibilities:\n- Maintain books in Tally Prime and file GST returns.\nQualification: B.Com / M.Com"); echo "ats ".$r["score"]." missing ".implode(",",$r["keywords"]["missing"]).PHP_EOL;' 2>&1 | tail -3
cd $H
php $W/rebuild3292.php
for u in / /login /sitemap.xml /ats-checker/ /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "refunds-admin.js $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/refunds-admin.js)  console/refunds.js anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/refunds.js)"
echo "home ai keyword: $(curl -s https://cvtara.com/ | grep -ci 'ai resume builder')"
echo "ats-checker template button: $(curl -s https://cvtara.com/ats-checker/ | grep -c 'Use this template')"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3292; touch $W/d3292.done; rm -f $W/d3292.lock

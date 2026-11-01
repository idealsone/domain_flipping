#!/bin/bash
# CVTara 3.29.1 overlay deploy on live 3.29.0 (PR #38: download every owner-console list and report as CSV, Excel or PDF with dates and filters). No migration. Approved by the owner 2026-10-31 23:50 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3291.log; mkdir -p $W
if [ -f $W/d3291.done ] || [ -f $W/d3291.lock ]; then cat $L; exit 0; fi
touch $W/d3291.lock; exec >>$L 2>&1; echo "== start $(date -u)"
fail(){ echo "ABORT $1"; touch $W/d3291.done; rm -f $W/d3291.lock; exit 1; }
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-29-1
cd $W && curl -fsSL "$SRC/cvt3291.tgz" -o cvt3291.tgz || fail download
echo "b6311c5213feea373146f4ad5d5dddb9b7b9e06f944d27ae9976552c2a2c3cd2  cvt3291.tgz" | sha256sum -c - || fail "tgz checksum"
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php 73f6654b0399451815a1f5baa629eaee15b202a51ed56969a4a752a8fcff7267
cvtara_app/app/Http/Controllers/AdminOpsController.php e817951925ac8d662dd6968968af833a01ac3e4ea5c3a2c785b8feb0464561d5
cvtara_app/app/Http/Controllers/AffiliateAdminController.php 67b0ebda71a99407bd106ee24b539d24ea3519ec74ed56d2029986b748038cbd
cvtara_app/app/Http/Controllers/CampaignAdminController.php e8f2a43c7ea7c6dd414ef931db2dc7408a04021e5cfa54a42a8d6f4e62359bb2
cvtara_app/app/Http/Controllers/OutreachAdminController.php 054af416e28968f4f9e558b7feafa48b0d2b0edd08de3a89f235b3c6c42411a0
cvtara_app/app/Http/Controllers/ReportController.php 8244b0d1b81a4316224a7da81b3695070a242dd305cc0c7aef98a234f621f911
cvtara_app/app/Http/Controllers/SupportAdminController.php 9d87994314e9ff254a006e83b59ec2389b8958683dc042d13f847a0ae6748fc2
cvtara_app/app/Services/AdminTable.php 4b83d85236daa693007129510a55cac80b297ea8315d21943693d1f8f2eefdfc
cvtara_app/app/Services/Export.php ABSENT
cvtara_app/bootstrap/app.php d45bf367ee04c232066a5185770238e6f91440555aa350ee04c3d54bb315a63f
cvtara_app/resources/console/campaigns.js 4b8e973daa18c533933e12491fc14eed630219439846eedc0ebda8d2ad9ed1e2 07948b522feba3c68c55dbc8cfcba95d65aad027d5618118eac1c7fbbcbfef99
cvtara_app/resources/console/feedback.js 339367e5939acfcc3375642e2a7e91406bf54594b1d6c0520d80e6bc7dc1bba9 f807f01802548d99230ccdc60e10afd1868059e3c042b58659549b71ccf06378
cvtara_app/resources/console/ops.js 92a3a73300d08e44beacf61cc71bc6ae224712295c7c6fff2cc6cf99a1b733b9 740102f01cc9c2530693149de1e9237c8c931d54b415b2f2c5881014d256a1d1
cvtara_app/resources/console/outreach.js c3ee6a7118401cde7ff2b7cd49a78a47accf6564ff96307e2e386caf2cb62d64 5fa3152f2c80ebab9bc6c3746642a8bc1f0a43ed52e462360e2f53bba87c6e76
cvtara_app/resources/console/people.js 6b4cde1afefb203929e71125a8129ff1c4d26e5a22b5e9fb142d6905fe5980f7 3cb46d5b217243be9ab73d70b9f1abefacdea0927bcf1c10c0c4f6b0b22868ab
cvtara_app/resources/console/referral.js 3b7de10376aae9957675ad75d72b5c0e8e4fd3d5e23130869c50c9ebdfc82fd7 89ba914c816c489be401515cd7a8e292c7858f954865df20514f9693ddad88f0
cvtara_app/resources/console/reports.js 90a41dbbc4c7f4f1d83307f2b0446345a927d953805f31d5a2127bbb95f0f80d 3124bee2d2d88b59e61860db522b84dee05eda46b4a8ae717f043cdc6216146f
cvtara_app/resources/frontend/admin-guide.html 82273eb6419d4aedba68f5e3e52634bb606cd5079a0d2ca1533f3c49fc4077e0
LIST
grep -q '"version": *"3.29.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.29.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || fail "live files differ from the expected base; nothing changed"
php -r 'echo "zip ".(class_exists("ZipArchive")?"yes":"NO")."\n";'
B=$W/backup_3291_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(for f in cvtara_app/app/Http/Controllers/AdminController.php cvtara_app/app/Http/Controllers/AdminOpsController.php cvtara_app/app/Http/Controllers/AffiliateAdminController.php cvtara_app/app/Http/Controllers/CampaignAdminController.php cvtara_app/app/Http/Controllers/OutreachAdminController.php cvtara_app/app/Http/Controllers/ReportController.php cvtara_app/app/Http/Controllers/SupportAdminController.php cvtara_app/app/Services/AdminTable.php cvtara_app/app/Services/Export.php cvtara_app/bootstrap/app.php cvtara_app/resources/console/campaigns.js cvtara_app/resources/console/feedback.js cvtara_app/resources/console/ops.js cvtara_app/resources/console/outreach.js cvtara_app/resources/console/people.js cvtara_app/resources/console/referral.js cvtara_app/resources/console/reports.js cvtara_app/resources/frontend/admin-guide.html ; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && echo "files backup $(tar -tzf $B/files.tgz | wc -l) entries" || fail "files backup"
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || fail "database backup"
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3291 && tar -xzf $W/cvt3291.tgz -C $W/x3291 || fail extract
cp -a $W/x3291/cvtara_app/. cvtara_app/ || echo "COPY FAILED, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3291/BUILD_INFO.json $b; done
echo "copied $(find $W/x3291 -type f | wc -l) files"
cd cvtara_app; for f in app/Services/Export.php app/Services/AdminTable.php app/Http/Controllers/ReportController.php app/Http/Controllers/AdminController.php bootstrap/app.php; do php -l $f | head -1; done
php artisan optimize:clear | tail -2
php artisan tinker --execute='$o=App\Services\Export::open("xlsx","t","Check",["a"=>"A"]); $o->add([["a"=>1]]); $r=$o->finish(); echo "xlsx ok ".get_class($r)."; pdf ".(class_exists("Dompdf\\Dompdf")?"ok":"MISSING");' 2>&1 | tail -1
cd $H
for u in / /login /sitemap.xml /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "export-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' 'https://cvtara.com/api/admin/users?export=csv')"
echo "report-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' 'https://cvtara.com/api/admin/report?dataset=daily&format=pdf')"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3291; touch $W/d3291.done; rm -f $W/d3291.lock

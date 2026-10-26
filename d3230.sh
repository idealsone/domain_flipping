#!/bin/bash
# CVTara 3.23.0 overlay deploy (PR #26: PhonePe gateway, contact phone); approved by the owner 2026-10-26 10:03 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3230.log; mkdir -p $W
if [ -f $W/d3230.done ] || [ -f $W/d3230.lock ]; then cat $L; exit 0; fi
touch $W/d3230.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-23-0
cd $W && curl -fsSL "$SRC/cvt3230.tgz" -o cvt3230.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3230.php && curl -fsSL "$SRC/errors.php" -o errors3230.php || { echo "ABORT download"; touch $W/d3230.done; exit 1; }
echo "fc0925a3d4099aef9b1d9b88ebbb4b1396138629d2ed1643440a0f9935db90d5  cvt3230.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3230.done; exit 1; }
echo "-- unresolved errors before"; php $W/errors3230.php
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php b3e2a5a84303e37762a049ccade1d7cc22dac79cf64226188a6ea45b5852cfcb 
cvtara_app/app/Http/Controllers/ApiController.php e75e552c126889cdbb2351825ad50463f5aecaf42915d42813f429d851a27197 
cvtara_app/app/Http/Controllers/SetupController.php 230a65cae9628831159315ff96c533836e0e29808adfba98a681f6b535a50a5b 
cvtara_app/app/Services/Health.php d0db33d552c0be246df98a667922c23c75961dd3a997103bb7e7aaf2b1871376 
cvtara_app/app/Services/MailAutomation.php 86846207473a0db1d4f2f7d2b9115feff4100c79d6e68796838fdcf30e1e939a 
cvtara_app/app/Services/Payments.php d8784a7b1d9c80479f2cc3c00b8848de1a6285baa0aa9ce9306c56c5f7aad6a0 
cvtara_app/app/Services/PhonePe.php ABSENT 
cvtara_app/app/Services/Referrals.php c9eaa45d1d0a1689a223069b37c7ad82904fdb5b57f6d6912d6402a4625e7f00 
cvtara_app/app/Services/Refunds.php aa86c9b3cf9e9673b831ada904337d3d058a719fdc3bee336ac8e29202901b43 
cvtara_app/app/Services/Wallet.php 6f73b72d324d44d40cd09dc883392a70d435a50976bffbbfedb2db0772ecb0f8 
cvtara_app/bootstrap/app.php b3d0fd209ee00fe9db7ee75231f0f0146e8159b1e4599eefa469b7281e6731d7 
cvtara_app/resources/console/advanced.js ed1640ce6105990971781a4ecb14b4834eb63d03a59db0c2d0028e681124671b 
cvtara_app/resources/console/extras.js 31abae596909ac150884b01b4d1bd5b4dedb595da9c1ba579e7953ccc3531e89 
cvtara_app/resources/console/owner.js 6343313c9e8ee1baf6b74202645bc270dcf3d4f1cd80f8f2866d57be8e68d000 
cvtara_app/resources/console/people.js 047e30e84eaabfba57dabe43e868329f6c92a3910e624f272dd5ae9a594fc012 
cvtara_app/resources/console/reports.js 0d3c0d91a2a37732baae0dbf0efb32c92209f3f7fcbbe24c9a40c37c3d672c17 
cvtara_app/resources/data/defaults.json de102a42e691f7e5d0a683dabcb7b8b86b7b5c3e45ff2f26e439e2f736ade4a1 
cvtara_app/resources/frontend/checkout.html 9a4ea2c86587046b43b2fa4da7283a92780442dcd2ce60390a77611a7b829a8c 
cvtara_app/resources/frontend/contact.html 6b114bb153db5f658f5f942b82e5a0b6167450c54325bb2cbf96d45a6df86128 
cvtara_app/resources/frontend/customer-guide.html 544ea24c3d862380afa161f503e0e59a1b86112a520c9e28ce602005d68b3eeb 
cvtara_app/resources/frontend/index.html 496ec92af936a7ba7aed7abd49c99ff21a8962f162434b3b3c71585f42d2c956 
cvtara_app/routes/console.php 0fedf3d35b4e0c2911674f64e8c222f1cd4efc1fd7ffd7956e891f72ab55a2bd 
cvtara_app/routes/web.php 4cd21764a83ea100ce439c7a15b372d25a86ab8bab9201c867273e144a3e74ac 
public_html/checkout-options.js e89ca356688bcbd083b2d98ded51b88dcb9835fc885e6a74d3f55801496125ee 
public_html/owner-public.js 3fc70b9496f6ec39895bc44cae3d10de3ff35bf0d5d88400eda04074dcda7eb5 
LIST
grep -q '"version": *"3.22.2"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.22.2"; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3230.done; rm -f $W/d3230.lock; exit 1; }
B=$W/backup_3230_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3230.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3230.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3230.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3230 && tar -xzf $W/cvt3230.tgz -C $W/x3230 && cp -a $W/x3230/cvtara_app/. cvtara_app/ && cp -a $W/x3230/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3230/BUILD_INFO.json $b; done
echo "copied $(find $W/x3230 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear; cd $H
php $W/rebuild3230.php
for u in / /login /contact.html /checkout.html /templates.html; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "webhook-phonepe-unauthorised $(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{}' https://cvtara.com/webhooks/phonepe)"
curl -s https://cvtara.com/contact.html | grep -o 'tel:+918217823192' | head -1
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "-- unresolved errors after"; php $W/errors3230.php | head -5
echo "== done $(date -u)"; rm -rf $W/x3230; touch $W/d3230.done; rm -f $W/d3230.lock

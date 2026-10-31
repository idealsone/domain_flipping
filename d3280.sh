#!/bin/bash
# CVTara 3.28.0 overlay deploy (PR #37: honest follow-ups, unfinished payment reminders, logo in mail, revenue follow-ups in reports); approved by the owner 2026-10-31 10:15 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3280.log; mkdir -p $W
if [ -f $W/d3280.done ] || [ -f $W/d3280.lock ]; then cat $L; exit 0; fi
touch $W/d3280.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-28-0
cd $W && curl -fsSL "$SRC/cvt3280.tgz" -o cvt3280.tgz || { echo "ABORT download"; touch $W/d3280.done; exit 1; }
echo "a83ffd363912a4ea73c241133df96ac29420b86e570a38ffd993690d457f5a30  cvt3280.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3280.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Services/Outreach.php ceadaee35bb8989f54bfc039db156551d9d9681b8e740387d72cb72914996285
cvtara_app/app/Services/MailAutomation.php 56b2d04cb90686f5b9134d2bcd4599ff4dd8297c0f91f287de9b40fb59eccad8
cvtara_app/app/Services/Growth.php 30a58bbf7a975d5cd9d485e697a9fd3a9ec4001b31d6825ee7f7ff2aec37b80e
cvtara_app/app/Services/EmailRenderer.php 2b3eb3497d335243e99920bb6d5dc464b0ad238810c22f6af52b77d401b1656c
cvtara_app/app/Services/MailLayout.php 1b95e7b7b57decc73bb898491fd40eb109c1aad4f43c0772ecdc4aa1d5caf7e1
cvtara_app/app/Http/Controllers/AdminController.php d1baa7cc76ab1d432fc4955509c313963668126dd7cbb1b9dc95a3b847fab16e
cvtara_app/resources/data/defaults.json 59f36664ec614af710d0a49495b2600cc5461d27c853dbce7091b468b45af755
cvtara_app/resources/console/content.js 4cbe356302a93f52e82e99a71c6bc1c437df216f0e82286d9a36cd41b93643f0 c5c6ae5fa5330e228166f20fd3f31206bb2a694b0fb0f8c6183ff4c240ad2751
public_html/assets/brand/cvtara-mail-mark.png ABSENT
LIST
grep -q '"version": *"3.27.1"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.27.1"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3280.done; rm -f $W/d3280.lock; exit 1; }
B=$W/backup_3280_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3280.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3280.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3280.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3280 && tar -xzf $W/cvt3280.tgz -C $W/x3280 && cp -a $W/x3280/cvtara_app/. cvtara_app/ && cp -a $W/x3280/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3280/BUILD_INFO.json $b; done
echo "copied $(find $W/x3280 -type f | wc -l) files"
cd cvtara_app && php -l app/Services/MailAutomation.php && php artisan optimize:clear; cd $H
for u in / /login /sitemap.xml /assets/brand/cvtara-mail-mark.png; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-users-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
cd cvtara_app && php artisan tinker --execute='echo json_encode(App\Services\Growth::followUps());' 2>&1 | tail -1; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3280; touch $W/d3280.done; rm -f $W/d3280.lock

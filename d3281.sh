#!/bin/bash
# CVTara 3.28.1 overlay deploy (PR #41: busy-database retries, rate limits in files, Error Log fixes), then save and clear the old Error Log and laravel.log as the owner asked. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3281.log; mkdir -p $W
if [ -f $W/d3281.done ] || [ -f $W/d3281.lock ]; then cat $L; exit 0; fi
touch $W/d3281.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-28-1
cd $W && curl -fsSL "$SRC/cvt3281.tgz" -o cvt3281.tgz || { echo "ABORT download"; touch $W/d3281.done; exit 1; }
echo "52109cc6c24474b9874244c7fdfd084c4685a92bd80c8bc2bc667ee7ca7e27dc  cvt3281.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3281.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Database/RetryingMySqlConnector.php ABSENT
cvtara_app/app/Providers/AppServiceProvider.php ee0c862a9390e0cce3836f4e6d7a676478a9fe40beddc3f8c465d5cd7979de10
cvtara_app/app/Services/ErrorLog.php 1b6c082a2818c5e4fa83216bab6ce9357d540046c119092a941df22f6a3f87be
cvtara_app/app/Services/Health.php 6d005890a218ba4c03d3c33fa6fab10ea36be95677d16b52117db5d96576b9a8
cvtara_app/bootstrap/app.php 289320b939fcbd2e9933527ffbabb1c439abc9df4f7132a8e01b3395503b0377
cvtara_app/config/cache.php ee4ad2bba1edfcff52e9599f37371f2370d0b167cc226ce72d46cdf6edf2dc55
cvtara_app/config/database.php 02cd62f589b43d33f9ceac740705dac1dec9740eb2bb9e6e202d4b66a15244cf
cvtara_app/routes/console.php a52e355ea9f7db56e8eb64655fb51fded9c500636c640e1c5d77151476d38d73
public_html/error-report.js f9eea33d6b28d0efdc990f58a7f93e74eb4b85cb90a910a8c210be5e01a4bef9
LIST
grep -q '"version": *"3.28.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.28.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3281.done; rm -f $W/d3281.lock; exit 1; }
B=$W/backup_3281_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3281.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3281.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3281.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3281 && tar -xzf $W/cvt3281.tgz -C $W/x3281 && cp -a $W/x3281/cvtara_app/. cvtara_app/ && cp -a $W/x3281/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3281/BUILD_INFO.json $b; done
echo "copied $(find $W/x3281 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear; cd $H
for u in / /login /sitemap.xml /pricing; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "login-with-cookie $(curl -s -o /dev/null -w '%{http_code}' -b 'cvtara_session=x' https://cvtara.com/login)"
echo "admin-users-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/admin/users)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
# Owner asked: save the old Error Log and laravel.log, then clear both.
LG=cvtara_app/storage/logs/laravel.log
[ -f $LG ] && gzip -c $LG > $B/laravel.log.gz && echo "saved laravel.log $(stat -c%s $LG) bytes -> $B/laravel.log.gz" && : > $LG
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" error_logs | gzip > $B/error_logs.sql.gz && echo "saved error_logs $(stat -c%s $B/error_logs.sql.gz) bytes" && MYSQL_PWD="$(ev DB_PASSWORD)" mysql -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" -e "DELETE FROM error_logs; SELECT COUNT(*) AS error_logs_left FROM error_logs;"
sleep 5; for u in / /login /pricing; do curl -s -o /dev/null -b 'cvtara_session=y' https://cvtara.com$u; done
echo "laravel.log after smoke: $(wc -c < $LG) bytes"; tail -c 600 $LG
echo "== done $(date -u)"; rm -rf $W/x3281; touch $W/d3281.done; rm -f $W/d3281.lock

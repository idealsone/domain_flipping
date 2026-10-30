#!/bin/bash
# CVTara 3.25.3 overlay deploy (PR #33: pages no longer load as a blank white screen); approved by the owner 2026-10-30 07:21 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3253.log; mkdir -p $W
if [ -f $W/d3253.done ] || [ -f $W/d3253.lock ]; then cat $L; exit 0; fi
touch $W/d3253.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-25-3
cd $W && curl -fsSL "$SRC/cvt3253.tgz" -o cvt3253.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3253.php || { echo "ABORT download"; touch $W/d3253.done; rm -f $W/d3253.lock; exit 1; }
echo "a0d359690f095d4f210d0b87100c4e1072b2214da3cc2767048b884de732b16a  cvt3253.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3253.done; rm -f $W/d3253.lock; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/resources/frontend/admin.html d67e8bf4e79ab1f2950381fe63c64a8e7802f88bd101b16897c61a619e3516cd
cvtara_app/app/Http/Controllers/ContentController.php 591144c1fd5d01e13a3860937d45372af29f860ad70aed61fd9587eeb63cb1f4
public_html/seo-pages.css 1e28c4c37971127c54b509e21c99b3a6a77a40b45e39efdc0b37f8998551fb2e
cvtara_app/resources/console/extras.js 192bef1a8ccc8684f01f9038af3fe6d1fe0b9c98032c459f6ea3017b5ab945f6
cvtara_app/resources/console/ops.js d3d46a4b532ced0cfb12b450aa7ee422000bb5aff9ed3504306bd88796bf670f
LIST
grep -q '"version": *"3.25.2"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.25.2"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3253.done; rm -f $W/d3253.lock; exit 1; }
B=$W/backup_3253_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3253.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3253.done; rm -f $W/d3253.lock; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3253.done; rm -f $W/d3253.lock; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3253 && tar -xzf $W/cvt3253.tgz -C $W/x3253 && cp -a $W/x3253/cvtara_app/. cvtara_app/ && cp -a $W/x3253/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3253/BUILD_INFO.json $b; done
echo "copied $(find $W/x3253 -type f | wc -l) files"
php -l cvtara_app/app/Http/Controllers/ContentController.php | head -1
cd cvtara_app && php artisan optimize:clear; cd $H
php $W/rebuild3253.php
for u in / /login /about.html /templates.html /guides.html /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "font-link-nonblocking $(curl -s https://cvtara.com/about.html | grep -c 'media=\"print\" onload')"
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/admin.html)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3253; touch $W/d3253.done; rm -f $W/d3253.lock

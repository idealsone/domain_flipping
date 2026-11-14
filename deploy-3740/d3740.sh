#!/bin/bash
# CVTara 3.74.0 overlay on 3.73.0 (brand result, design pictures, image sitemap; PR #60). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3740.log; mkdir -p $W
if [ -f $W/d3740.done ] || [ -f $W/d3740.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d3740.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/a48cd80c1f2d25c80c31164316968109c47cd091/deploy-3740
cd $W && curl -fsSL "$SRC/cvt3740.tgz" -o cvt3740.tgz && curl -fsSL "$SRC/checks.txt" -o checks3740.txt && curl -fsSL "$SRC/rebuild.php" -o rebuild3740.php || { echo "ABORT download"; touch $W/d3740.done; exit 1; }
echo "d27edc8105f7054ffc3d092c9072d42fcdca8ec5256305a7e86104f89449dd3a  cvt3740.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3740.done; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then if [ "$k" = W ]; then echo "NOTE minified differs $p $cur (backed up)"; else echo "MISMATCH $p $cur"; bad=1; fi; fi; done < $W/checks3740.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.73.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d3740 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3740.done; rm -f $W/d3740.lock; exit 1; }
B=$W/backup_3740_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(cd $H && for f in $(tar -tzf $W/cvt3740.tgz | grep -v '/$' | sed 's#^\./##' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3740.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3740.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3740 && tar -xzf $W/cvt3740.tgz -C $W/x3740 || { echo "ABORT extract"; touch $W/d3740.done; exit 1; }
bad=0; for f in $(cd $W/x3740 && find cvtara_app -name '*.php'); do php -l $W/x3740/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d3740.done; exit 1; }
cp -a $W/x3740/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x3740/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3740/BUILD_INFO.json $b; done
echo "copied $(find $W/x3740 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild3740.php
for u in / /about.html /resume-templates/ /cover-letter-examples/ /pricing/ /sitemap.xml /assets/templates/academic-cv-resume-template.webp /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code} %{content_type}' https://cvtara.com$u)"; done
echo "menu in home html: $(curl -s https://cvtara.com/ | grep -c 'class="main-nav"')"
echo "pictures on hub: $(curl -s https://cvtara.com/resume-templates/ | grep -o 'resume-template.webp' | wc -l)"
echo "faq on about: $(curl -s https://cvtara.com/about.html | grep -o 'FAQPage' | wc -l)"
echo "sitemap images: $(curl -s https://cvtara.com/sitemap.xml | grep -o '<image:loc>' | wc -l)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3740; touch $W/d3740.done; rm -f $W/d3740.lock

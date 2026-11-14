#!/bin/bash
# CVTara 3.79.0 overlay on 3.78.0 (SEO page pictures, senior role pages). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3790.log; mkdir -p $W
if [ -f $W/d3790.done ] || [ -f $W/d3790.lock ]; then tail -c 6000 $L; exit 0; fi
touch $W/d3790.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/86921ef7444f1efd2f0aa10a2378791efa932535/deploy-3790
cd $W && curl -fsSL "$SRC/cvt3790.tgz" -o cvt3790.tgz && curl -fsSL "$SRC/checks.txt" -o checks3790.txt && curl -fsSL "$SRC/rebuild.php" -o rebuild3790.php || { echo "ABORT download"; touch $W/d3790.done; rm -f $W/d3790.lock; exit 1; }
echo "a51bca278e903eb60c10dca09bcdf03084beeee9f39f7bd7a49084ca4d2c339c  cvt3790.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3790.done; rm -f $W/d3790.lock; exit 1; }
cd $H; bad=0
while read k p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done
  if [ $ok = 0 ]; then echo "MISMATCH $p $cur"; bad=1; fi; done < $W/checks3790.txt
V=$(grep -ho '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json 2>/dev/null | head -1); echo "live $V"
echo "$V" | grep -q '"3.78.0"' || { echo "MISMATCH live version"; bad=1; }
crontab -l 2>/dev/null | grep -v private-cron | grep -v d3790 | grep cvtara_deploy && echo "NOTE another deploy cron exists"
echo "disk: $(du -sh $H/public_html 2>/dev/null | cut -f1) public_html; $(df -h $H | tail -1)"
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3790.done; rm -f $W/d3790.lock; exit 1; }
B=$W/backup_3790_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(for f in $(tar -tzf $W/cvt3790.tgz | grep -v '/$' | sed 's#^\./##' | grep -v '^BUILD_INFO.json$'); do [ -f $f ] && echo $f; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3790.done; rm -f $W/d3790.lock; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3790.done; rm -f $W/d3790.lock; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3790 && tar -xzf $W/cvt3790.tgz -C $W/x3790 || { echo "ABORT extract"; touch $W/d3790.done; rm -f $W/d3790.lock; exit 1; }
bad=0; for f in $(cd $W/x3790 && find cvtara_app -name '*.php'); do php -l $W/x3790/$f >/dev/null || { echo "SYNTAX $f"; bad=1; }; done
[ $bad = 0 ] || { echo "ABORT syntax; nothing changed"; touch $W/d3790.done; rm -f $W/d3790.lock; exit 1; }
cp -a $W/x3790/cvtara_app/. cvtara_app/ || echo "COPY FAILED app, restore from $B/files.tgz"
cp -a $W/x3790/public_html/. public_html/ || echo "COPY FAILED public, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3790/BUILD_INFO.json $b; done
echo "copied $(find $W/x3790 -type f | wc -l) files; pictures $(ls public_html/assets/seo/pages | wc -l)"
cd cvtara_app && php artisan optimize:clear >/dev/null; cd $H
php $W/rebuild3790.php
for u in / /resume-builder/bangalore/ /interview-questions/hr-business-partner/ /resume-examples/sales/area-sales-manager/ /assets/seo/pages/resume-builder-bangalore.webp /api/health; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "page picture in html: $(curl -s https://cvtara.com/resume-builder/bangalore/ | grep -c 'assets/seo/pages/resume-builder-bangalore.webp')  sitemap pictures: $(curl -s https://cvtara.com/sitemap.xml | grep -o 'assets/seo/pages/' | wc -l)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 900 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3790 $W/cvt3790.tgz; touch $W/d3790.done; rm -f $W/d3790.lock

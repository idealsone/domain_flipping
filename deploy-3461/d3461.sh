#!/bin/bash
# CVTara 3.46.1 overlay on 3.46.0 (SEO trust line). Owner typed "deploy 3.46.1" on 2026-11-08. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3461.log; mkdir -p $W
if [ -f $W/d3461.done ] || [ -f $W/d3461.lock ]; then tail -c 3000 $L; exit 0; fi
touch $W/d3461.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/5c7e93e815dd7631ef364caca66906f26c7f4f84/deploy-3461
cd $W && curl -fsSL "$SRC/cvt3461.tgz" -o cvt3461.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3461.php || { echo "ABORT download"; touch $W/d3461.done; exit 1; }
echo "5fbd9f5fabee7ffc6be8bde94e8e20f110c1b7a69afad44ea9f92609c1cf08bb  cvt3461.tgz" | sha256sum -c - || { echo "ABORT checksum"; touch $W/d3461.done; exit 1; }
cd $H; F=cvtara_app/resources/views/seo/page.blade.php
cur=$(sha256sum $F | cut -c1-64); [ "$cur" = "235b5e07c4df3fb98a14ca875bf8404995b87f2e5375b1513312982f291463c9" ] || { echo "ABORT $F is $cur, expected 3.46.0"; touch $W/d3461.done; rm -f $W/d3461.lock; exit 1; }
grep -q '"version": *"3.46.0"' cvtara_app/BUILD_INFO.json || { echo "ABORT live version"; touch $W/d3461.done; exit 1; }
B=$W/backup_3461_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $F $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json 2>/dev/null) && echo "backup ok $B" || { echo "ABORT backup"; touch $W/d3461.done; exit 1; }
mkdir -p $W/x3461 && tar -xzf $W/cvt3461.tgz -C $W/x3461 && cp -a $W/x3461/cvtara_app/. cvtara_app/ || echo "COPY FAILED, restore from $B/files.tgz"
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3461/BUILD_INFO.json $b; done
cd cvtara_app && php artisan view:clear; cd $H
php $W/rebuild3461.php
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json
echo "== done $(date -u)"; rm -rf $W/x3461; touch $W/d3461.done; rm -f $W/d3461.lock

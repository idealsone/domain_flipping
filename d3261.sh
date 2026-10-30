#!/bin/bash
# CVTara 3.26.1 overlay deploy (PR #32: calmer, more human design across the site); approved by the owner 2026-10-30 10:13 UTC ("make it live"). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3261.log; mkdir -p $W
if [ -f $W/d3261.done ] || [ -f $W/d3261.lock ]; then cat $L; exit 0; fi
touch $W/d3261.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-26-1
cd $W && curl -fsSL "$SRC/cvt3261.tgz" -o cvt3261.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3261.php || { echo "ABORT download"; touch $W/d3261.done; rm -f $W/d3261.lock; exit 1; }
echo "b12417758fc2707758d78fadd0d9f25fe97a3ac3f30d115aa61b1f1197f35e97  cvt3261.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3261.done; rm -f $W/d3261.lock; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php 00437ab8100fcc40e9e889b1c6e9881fa7471778a33364697995b2f77402088d a3f6ce526852a9a174031bda75b6b6b38e6a32f01261f2a7c9d2be70c219f518 d1baa7cc76ab1d432fc4955509c313963668126dd7cbb1b9dc95a3b847fab16e e8c8cb623651518f4f90c223d6a44b80e7e69de5d8fa24f4336559308e6c91fe
cvtara_app/app/Http/Controllers/ContentController.php c89259dca4849bdd8502ab32c1332e9d7f62ce7fbc7a971067290dd48b6ddfcd cd1433eb49bb77f75a22860466b83cce4b606fe1890f772d066130d966eb3608
cvtara_app/database/migrations/2026_10_30_000001_human_homepage_copy.php 67b6b0e599adcdfca5b0bedfd4ed8db2c50969e22b7a004c956b9f074fb3e138 ABSENT
cvtara_app/resources/console/content.js 274bb1e33d4cdcd5774c4fafcd05d9163ea1dc3520c7f62059bb1e71d19aec84 9a941763e1f90d147d141be4f279ae12e4dcd4086484b31f397b7b87017c4730 b3fc7a0176245f8b6f4282c24e8a34a85024dbbc61780d039fd88579927bc444
cvtara_app/resources/console/speed.js 8071c4044a4dcad4e9fc663a2f1f5312ab73fa3777bb4c14e52633eb81395137 960e2d1cb5ef07de7b93a539b1f4709f269aed3a8210bb0c894ae8d41ab52b97 a8a469ba2bb2c9bca9d5a6e246cef1603d96f1d3ef52c6d109db1cfe15d89678
cvtara_app/resources/data/defaults.json 59f36664ec614af710d0a49495b2600cc5461d27c853dbce7091b468b45af755 a93a4f4fdf463f369aa402126fe04408690100a49b6d188e0906fe1fb51751bc
cvtara_app/resources/frontend/admin.html 3af427f507d301e3a0c8cd9752d3d9cf91384230496a6c41913e297e9d7d57b8 5cdc2291911aa046a3aad98c5e392c75c0a3c3ae20f63f02d0888cb549864cce 6bbbd0c29918aa31a2dd710275a3553525634367f95de535fd9a4c7121da9f18 d67e8bf4e79ab1f2950381fe63c64a8e7802f88bd101b16897c61a619e3516cd
cvtara_app/resources/frontend/index.html 942755b71158a90fd2768cd4436ca0d866cb7a38875223e2d8f05e1dc7c44360 a2fe9e481d09d50eadbaefeca0119ccc1362e2b30cf9a8643d1d95cf7634b64e
cvtara_app/resources/frontend/workspace.html 03bc73464c4c0638803631fa0f7d6cdaa82742da2b6ede605300f6a70599e9ea 78ebb9e2b043487095a4822ec4cfbe165edae6313ddbc818096a34dec08cd381
cvtara_app/resources/views/auth.blade.php 042add2a82a8de4115b0d5948c1f68d33945a86df053c62ae937b6b3e397a462 d17e4a506fff598fa18b6d467484975b09000921830f8afe3bc5e24d8c461790
public_html/home-ui.js 709d09b883f194decbb45dd190de733dece0987f30d9900eb911e78e58d2d8f6 be01146f539541a37440611f2332efaa37c8b4943fce6c52bc2bfd56ebee136b f327b15c99e98be75d7b55116b7117f0b5d078bc661ca7a738938f1e233ea497
public_html/home-v3.css 24b45c9f7fd5623b6faa7d279f2d24f6ea9ee084f96a794085f251343667ef8c e56e7f7c8d79a93d757682bc32d5fbdfb155499a31db316dcf080008dd1d0554
public_html/nav-icons.js 2503b79c2fae74b033e274b90a48d920234f9d9b128fbc21325fe41ad3214b7e ABSENT
public_html/site-polish.css bb51fe06e7c7343adaab7266725f92a905748b1f31a51c91d36c3afd06b9f94c be7ca5c7282d283a958540d928ef5a77f522efa8f2ebe78a7c14a5cc99f0191c
public_html/workspace-ui.js 412757eff28f81cf0074dbb1004f556c08bcd0cbed7860646f314a9534ea2f1d 6077a03f2f9e5227969520911e6b39eb1816e37911a09e230391b400fc68cca5 848586a9423c5509453a20eb109ca5aae9fbc432aa54138454588acb46de37b8
LIST
grep -q '"version": *"3.26.0"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.26.0"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3261.done; rm -f $W/d3261.lock; exit 1; }
B=$W/backup_3261_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3261.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3261.done; rm -f $W/d3261.lock; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3261.done; rm -f $W/d3261.lock; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3261 && tar -xzf $W/cvt3261.tgz -C $W/x3261 && cp -a $W/x3261/cvtara_app/. cvtara_app/ && cp -a $W/x3261/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3261/BUILD_INFO.json $b; done
echo "copied $(find $W/x3261 -type f | wc -l) files"
php -l cvtara_app/app/Http/Controllers/ContentController.php | head -1
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep human_homepage; php artisan optimize:clear; cd $H
php $W/rebuild3261.php
for u in / /login /about.html /templates.html /guides.html /resume/bangalore/software-engineer/ /nav-icons.js /sitemap.xml; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "home-founder $(curl -s https://cvtara.com/ | grep -c 'lp-founder')  fraunces $(curl -s https://cvtara.com/about.html | grep -c 'Fraunces')"
echo "hero-title $(cd cvtara_app && php artisan tinker --execute='echo json_encode(App\Services\Settings::get("site")["heroTitle"] ?? null);' 2>&1 | tail -1)"
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/admin.html)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3261; touch $W/d3261.done; rm -f $W/d3261.lock

#!/bin/bash
# CVTara 3.32.0 overlay deploy on 3.31.1 (Tara chat for customers + owner Business assistant, PR #45). Owner typed "deploy 3.32.0" on 2026-11-06. One migration (tara tables, ai_usage.owner_id). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3320.log; mkdir -p $W
if [ -f $W/d3320.done ] || [ -f $W/d3320.lock ]; then cat $L; exit 0; fi
touch $W/d3320.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-32-0
cd $W && curl -fsSL "$SRC/cvt3320.tgz" -o cvt3320.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3320.php || { echo "ABORT download"; touch $W/d3320.done; exit 1; }
echo "4e67a127894e78856bf790d1895de13c76c0e585cccc0d74183a039da9812d5f  cvt3320.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3320.done; exit 1; }
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php 70b7e232c9673422dd3b07f936d479b4f2024cc1e73cbddce54fcd1166b39469 
cvtara_app/app/Http/Controllers/ApiController.php ef824518e83437b667cddeb37fd001d5ee9b7576b832751f786f4feb3320639a 
cvtara_app/app/Http/Controllers/ContentController.php 591a605a4d0bfb9fec005d24d50dbcac5f218c774d238396fc3284e0ba849c3f 
cvtara_app/app/Http/Controllers/ReportController.php 79522a76503d6c3d58c579e70617883dbd191439e0c4205fe55788e465802a5a 
cvtara_app/app/Http/Controllers/SupportController.php 68d908e34f5d63d814fafa3f22aee9030d8465d657af475d80569dbf759c807f 
cvtara_app/app/Http/Controllers/TaraAdminController.php ABSENT
cvtara_app/app/Services/AccountEraser.php 9fcc33c0b88157f9ee70c8d592d5eb8dccd08685ac1795a0d0a0c9754ba115f4 
cvtara_app/app/Services/CoverLetterWriter.php 3c1d89f34ca86283bb75ca7c3cc8f06b6044a6550f597f593fbd26216667a5c3 
cvtara_app/app/Services/ExpertReview.php 0f75afed8c1fc117d8c19d20eaaabbe606ab6a3e4b997da778f21c5d56644f58 
cvtara_app/app/Services/Growth.php 6ab658530da15783aefbbd260ca6a26a8c4083296a8efe6b0aeddb56e36e922f 
cvtara_app/app/Services/ResumeTailor.php 5129b3fbbf750974103963cef04d00146d15bf86d52ecd2e3bc58cdca7097583 
cvtara_app/app/Services/Retention.php 24dfe7207b42c3d4c1196edd95f3c28e1a30156ba87a1cc67c8f6bdd9e3cef04 
cvtara_app/app/Services/Settings.php c9e881e6b8857b6a5f9da098d5c6e30552eb6312fd41e66ee55415691ddf6563 
cvtara_app/app/Services/TaraAI.php 27a46c1dfa48c1b6bdc7c0b20b3d32ab822b91acd8c4a23e0bdcb67a3b517482 
cvtara_app/app/Services/TaraBusiness.php ABSENT
cvtara_app/app/Services/TaraChat.php ABSENT
cvtara_app/app/Services/TaraOwner.php ABSENT
cvtara_app/database/migrations/2026_11_06_000003_tara_chat.php ABSENT
cvtara_app/resources/console/assistant.js ABSENT
cvtara_app/resources/console/tara.js ABSENT
cvtara_app/resources/frontend/admin.html dd935a7a8fd9531bddcc5c094182e6dec9f9fd3dd24bcc07dbe7e92d7c9caecc 
cvtara_app/routes/web.php 59e22d8e208016befdd6b4ecb630a01bf9e6920f96c240f86fff12189dd4f1b2 
public_html/nav-icons.js 05555d1e4c44130366d4bb48849c2805c070f389c601bd0cf40192147fdce614 4d7d3d299065eb75ba00042e9674737932c9b52452f5fcc0ef65c880471b63ee
public_html/tara-chat.css ABSENT
public_html/tara-chat.js ABSENT
LIST
grep -q '"version": *"3.31.1"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.31.1"; grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3320.done; rm -f $W/d3320.lock; exit 1; }
B=$W/backup_3320_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3320.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3320.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3320.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3320 && tar -xzf $W/cvt3320.tgz -C $W/x3320 && cp -a $W/x3320/cvtara_app/. cvtara_app/ && cp -a $W/x3320/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json public_html/BUILD_INFO.json; do [ -f $b ] && cp $W/x3320/BUILD_INFO.json $b; done
echo "copied $(find $W/x3320 -type f | wc -l) files"
for f in $(cd $W/x3320 && find cvtara_app -name '*.php'); do php -l $f | head -1; done
cd cvtara_app && php artisan migrate --force && php artisan migrate:status | grep tara_chat; php artisan optimize:clear; cd $H
php $W/rebuild3320.php
for u in / /login /contact.html /sitemap.xml /nav-icons.js /tara-chat.js /tara-chat.css; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "admin-anon $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' 'https://cvtara.com/api/admin/tara/owner')"
echo "public-content $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://cvtara.com/api/public-content)"
cd cvtara_app && php artisan tinker --execute='echo json_encode([Illuminate\Support\Facades\Schema::hasTable("tara_admin_actions"), Illuminate\Support\Facades\Schema::hasColumn("ai_usage","owner_id"), Illuminate\Support\Facades\Schema::hasColumn("tara_messages","mode"), App\Services\TaraChat::enabled(), App\Services\TaraBusiness::strip()["at"]]);' 2>&1 | tail -2; cd $H
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "laravel.log tail:"; tail -c 400 cvtara_app/storage/logs/laravel.log
echo "== done $(date -u)"; rm -rf $W/x3320; touch $W/d3320.done; rm -f $W/d3320.lock

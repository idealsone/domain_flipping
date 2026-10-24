#!/bin/bash
# CVTara 3.22.1 overlay deploy (approved by the owner 2026-10-24 16:03 UTC). Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3221.log; mkdir -p $W
if [ -f $W/d3221.done ] || [ -f $W/d3221.lock ]; then cat $L; exit 0; fi
touch $W/d3221.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-22-1
cd $W && curl -fsSL "$SRC/cvt3221.tgz" -o cvt3221.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3221.php || { echo "ABORT download"; touch $W/d3221.done; exit 1; }
echo "28fa673d97a2d19d383c395815212133099775820714924344a30b3458e50b98  cvt3221.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3221.done; exit 1; }
cd $H; bad=0
while read p new rest; do cur=$(sha256sum "$p" 2>/dev/null | cut -c1-64); ok=0; for x in $new $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php b3e2a5a84303e37762a049ccade1d7cc22dac79cf64226188a6ea45b5852cfcb b3e2a5a84303e37762a049ccade1d7cc22dac79cf64226188a6ea45b5852cfcb baeb05308d4d887ef3f589b9903de1c50b7b93affa416ee0b1cae42fbb4ca9e7 
cvtara_app/app/Http/Controllers/ApiController.php e75e552c126889cdbb2351825ad50463f5aecaf42915d42813f429d851a27197 bb6790f8da2c08a334e5400e665d6915c9daeac8145b30ed7dbe74fd18618ca8 e75e552c126889cdbb2351825ad50463f5aecaf42915d42813f429d851a27197 e77a3a0f681a080e0e3c6e2a8f32cb3f1845afda1fe7516535bafc83ccd34ecf 
cvtara_app/app/Http/Controllers/AuthController.php 0ee255fee82c9827240e5fecd733968148f9b6c38245ed66693a3af631101518 0ee255fee82c9827240e5fecd733968148f9b6c38245ed66693a3af631101518 d26a492e763a099fe9972d6d8a1a961efa321b7a837e08f130ed7cd2b9272fe5 
cvtara_app/app/Services/BusinessControls.php 3a31ace971d27e132c703b76cb415bacc07779b1dc51c95df5dc8183d4cf0398 0c169aebf005c40efdbdac24c5ba2e188e593234a111962b3e32960f379fa828 3a31ace971d27e132c703b76cb415bacc07779b1dc51c95df5dc8183d4cf0398 
cvtara_app/app/Services/MailAutomation.php 86846207473a0db1d4f2f7d2b9115feff4100c79d6e68796838fdcf30e1e939a 13982c2aba84baa09f7ed50c1e7349106d43a53f6dcd809719eb36fa229da25b 4d94faa6b8f6929a823e5a3c08a3e07cf68ae1b64e058cccfe73fd268c61fb12 86846207473a0db1d4f2f7d2b9115feff4100c79d6e68796838fdcf30e1e939a 
cvtara_app/app/Services/Wallet.php 6f73b72d324d44d40cd09dc883392a70d435a50976bffbbfedb2db0772ecb0f8 6f73b72d324d44d40cd09dc883392a70d435a50976bffbbfedb2db0772ecb0f8 e60d159f5847d55717d49d8a29d991b243e73c805caac9fe368648282a6a5963 
cvtara_app/resources/console/content.js d945d3f5a7c17dfdfa65a5e4108655bc44190fedee8deb756fba777c16dfa2b6 45b9edea570d043b32ea18ef227d6d4fd705c1a9096c6d1d21b698476b2d47a2 965c933baead28cf7201c9f4fdc2a9e55644ed925b86fb861728247ca8904515 abc1833e47f60ee834efbe350bdc8eeb8a1e0db62b93234a960f90b67510a285 d945d3f5a7c17dfdfa65a5e4108655bc44190fedee8deb756fba777c16dfa2b6 
cvtara_app/resources/frontend/index.html e39aa071ed05c627c7927dfb0f405c0c45817c625b72054e323b6cf7443aefe2 3f8504fb823e56ac6c76840f3b6c9a489515be7686d467e950c4d20b0273ed91 e39aa071ed05c627c7927dfb0f405c0c45817c625b72054e323b6cf7443aefe2 
cvtara_app/resources/views/auth.blade.php 042add2a82a8de4115b0d5948c1f68d33945a86df053c62ae937b6b3e397a462 042add2a82a8de4115b0d5948c1f68d33945a86df053c62ae937b6b3e397a462 f0c17ad483e3e567d340ac9c06a61fc22659f45452fca47bf8400cb1d8fb1b4a 
public_html/connected-ui.js 4fdf57784d250c2838ff8c81bcdd537beb10199ea88287ac1ce76c68adb2408f 4fdf57784d250c2838ff8c81bcdd537beb10199ea88287ac1ce76c68adb2408f 978ccad97c441e582d5c3c7814a3cc4850696837479b52122782003edc765a8e a39b7228f31f2b10e94fe85a009468286b24d8f09fbc4db6201065929817fc9a a604c03368eb2b7f9eeca7923b767a5dc26ff772c5eed9872a5bc8f14d97942d cac94135a02ba53f0cc96700dd522735a4cc975b1520d102bd011b9fdce602a7 d688d07c08ca1bb46c0b5e38a9e0a2e8a36137089a2d7b399238f6207bf97f0f 
public_html/home-ui.js e23a83b3a44800f1e0c612f914d78c45b5f8d8939003ec57018992012825ccf2 9e6819797d8787bd9db04e19caf8bd8433a31a50b6be2a1c57ec4f3e78aa622d c22801965d44d33d1643ddc950c4a0d6ee8f315c9e3a519549d26cedcd484168 e23a83b3a44800f1e0c612f914d78c45b5f8d8939003ec57018992012825ccf2 f327b15c99e98be75d7b55116b7117f0b5d078bc661ca7a738938f1e233ea497 
public_html/home-v3.css 24b45c9f7fd5623b6faa7d279f2d24f6ea9ee084f96a794085f251343667ef8c 24b45c9f7fd5623b6faa7d279f2d24f6ea9ee084f96a794085f251343667ef8c 887511702398bee8d1854a5a6c27617c656271595b27fdafac94ad6ee37eb819 
public_html/launch-ui.js f2e079576aaea92a4ef1d8818a18735845a2abade9a17b4023c2a570c589fd5d 49502d7cbfeb852054baad6e952d497cac4f1c262e97601ec9afee2855c14220 8120fbb20804d3b220a5dbe16d7ca3f5f42c5665cf84030fe72100de94cd0f8d bc089700197e028728f0fe09a3e16631225bea64ddd44db5daba7dd54018caa3 cf0adec95aa155c0daa8ac884fb10e5be6188a7f7f7c86e027a832f5e009ed22 
public_html/onesignal-push.js 36faaea877431ed894a3674dfb77fb13f30c345926f6ebdab63450007e562119 19d63f266bb76d23f5521f67bb6198d71a49eecad65d22eec156d3a42ed9d193 36faaea877431ed894a3674dfb77fb13f30c345926f6ebdab63450007e562119 89130bb14289dbf20d9e9aa4debf73d7d2e985a8b1aa2cb76bc36f5c8d904500 a48031b207a783b37d3deb7c3e3487bd36588e7411e8290a42add0cf38a04d03 
public_html/reward-paywall.js 8edff067e3cd9724278dddbcac681afa14168d05602428d41d13ddfa0c4d8c58 0d0c09a6b78b8122c5ef9da76e355807f0a41b453bcea73531d314ae47a0a259 8edff067e3cd9724278dddbcac681afa14168d05602428d41d13ddfa0c4d8c58 960f1a74da6d8533f1bb5450ca09c6a6cc66fce88ffb174f722413d4bbe4f413 b4d7a3b3c45b94fd86247e6255c21aea527dc1898a096efb2b8338344e0977a2 dbb49e25de1a4efdbb89bc87227a47e20f6f85d2cf0c0b95819288d19a15be1c fa1921e848a7e6a76146fac6f53b4490cb39fae5ad1c88cb86a9cc0300c1e0be 
public_html/workspace-ui.js 9e135cfdae3b3c6621a270e6d462326f0ec41597080b435015b40cfecc8a387e 37d27a6d03c006c95583d1389a79b4a2a5fda213239861ef5c5c5d36a046b6bf 6077a03f2f9e5227969520911e6b39eb1816e37911a09e230391b400fc68cca5 9e135cfdae3b3c6621a270e6d462326f0ec41597080b435015b40cfecc8a387e 9f8090318dde12172e0b4f39363e498a9c85d943417a372be58bdc66116ca777 
LIST
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3221.done; exit 1; }
B=$W/backup_3221_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3221.tgz | grep -v '^BUILD_INFO.json$') $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3221.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3221.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3221 && tar -xzf $W/cvt3221.tgz -C $W/x3221 && cp -a $W/x3221/cvtara_app/. cvtara_app/ && cp -a $W/x3221/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3221/BUILD_INFO.json $b; done
echo "copied $(find $W/x3221 -type f | wc -l) files"
cd cvtara_app && php artisan optimize:clear && php artisan migrate --force; cd $H
php $W/rebuild3221.php
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3221; touch $W/d3221.done; rm -f $W/d3221.lock

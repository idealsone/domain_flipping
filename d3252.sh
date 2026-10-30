#!/bin/bash
# CVTara 3.25.2 overlay deploy (PR #30: OneSignal push notifications removed), on top of 3.25.1 (PR #31); approved by the owner 2026-10-30 07:20 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3252.log; mkdir -p $W
if [ -f $W/d3252.done ] || [ -f $W/d3252.lock ]; then cat $L; exit 0; fi
touch $W/d3252.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-25-2
cd $W && curl -fsSL "$SRC/cvt3252.tgz" -o cvt3252.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3252.php && curl -fsSL "$SRC/errors.php" -o errors3252.php || { echo "ABORT download"; touch $W/d3252.done; rm -f $W/d3252.lock; exit 1; }
echo "43273bad5bee8a4bdfd966afee085f5230c8bbf541ef4b3b9fdd7a17ecb3dbe3  cvt3252.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3252.done; rm -f $W/d3252.lock; exit 1; }
echo "-- unresolved errors before"; php $W/errors3252.php | head -8
cd $H; bad=0
while read p rest; do if [ -f "$p" ]; then cur=$(sha256sum "$p" | cut -c1-64); else cur=ABSENT; fi; ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/AdminController.php a3f6ce526852a9a174031bda75b6b6b38e6a32f01261f2a7c9d2be70c219f518
cvtara_app/app/Http/Controllers/ApiController.php dc966545cab72c49bedb47259470814e72d92efec1792da5d70830ca1102beb7
cvtara_app/app/Http/Middleware/SecurityHeaders.php 5d25aa9b686fea098fd0675774650c8c0fdb4650390483f3fe5c049068023246
cvtara_app/resources/console/advanced.js 13d970464c9662127325935ce0c0d23a6c9d1e780911b2f765034fedb5730fe2
cvtara_app/resources/console/ops.js a87880b280d730116b73dff777ab18f79caed9f921fec27ee3de84e220c00d82
cvtara_app/resources/console/owner.js 099b4436e3712934e2e82a4ec3879cb76912867e1be5fe62c54bba4cfc8e049e
cvtara_app/resources/frontend/about.html 3667e38a61a4a0517278610131cdc507bbf0ae1adbd7d65493a983736a9c1145
cvtara_app/resources/frontend/admin.html 45a948d475971fdc9b0afac1b4b45035a2945972f5cf090914a0bac82ef33c86
cvtara_app/resources/frontend/article.html ab07f0d8f76eb02065275a0861027314cb5d29c81e1d818f117b3006c170ca61
cvtara_app/resources/frontend/blog.html 4b8d70a91f3090865b1681f51ac61a934ab8042ee1675fcbd2991a3165a7d3e3
cvtara_app/resources/frontend/calculators/average-salary-job-title/index.html acd7bf82de14b34225a4ed09f15fc791153b89d48ea4da4f7d2b60aa417e513b
cvtara_app/resources/frontend/calculators/bonus-net-payout-estimator/index.html 48f06b7f64b8ae37ccbf9c937f76276897607c2ad63a7111f661bc485dc4e3a0
cvtara_app/resources/frontend/calculators/compensation-package-builder/index.html efb4bb723614cfe05a36999f38677485c5e93f727006c454a981146b1b069046
cvtara_app/resources/frontend/calculators/contractor-vs-full-time-salary-calculator/index.html d92c7556d1d7de96096a63457400b5e3057a2ec7df49d77a1ebd8f3daff976a5
cvtara_app/resources/frontend/calculators/cost-of-living-calculator-by-city/index.html 94c761aba2c97575ed479d578a066a11264b9d2f04259c529568e9923e794bee
cvtara_app/resources/frontend/calculators/ctc-to-in-hand-salary-calculator/index.html 278573cbcbe336ddab8ebe1968195ee67b95df78287d77fa07682e5f999b894e
cvtara_app/resources/frontend/calculators/current-salary-in-resume/index.html 1d369d00105ea515ca9a3e201602362fb7df3e78aae55943a96d54eb4ecab3b0
cvtara_app/resources/frontend/calculators/debt-to-income-career-planner/index.html 8200d3c9babe36f2a2fcf638988fa6818d021ad7f71c6a2310f66b2656e66089
cvtara_app/resources/frontend/calculators/dual-employment-moonlighting-tax-estimator/index.html 3be08c2ddfdfe2f87994fb4e21caee00a9aa521fded63f86b3eb3b1305dad6d2
cvtara_app/resources/frontend/calculators/education-loan-calculator-roi/index.html de8c6f2ccdc3e74a32d0b391e69686546167ca17eb4082bc6d5f047111802d6f
cvtara_app/resources/frontend/calculators/expat-net-salary-tax-calculator/index.html 7f6acd798b738bdd584502ba993430287e9f006377ca87fd2498403c04986f98
cvtara_app/resources/frontend/calculators/expected-salary-interview-calculator/index.html 592b3b793fc3e18936e872ba12b1f788f2ee55897a60071d56e6336c126dfed8
cvtara_app/resources/frontend/calculators/fire-career-milestone-tracker/index.html 094422701dcfcedf0df7b24374dc612de295946678b3a2daa0ff5ae5118a8b6e
cvtara_app/resources/frontend/calculators/gratuity-calculation-formula/index.html 269cee023a22bb17876514060d6a87f44d6639fcb171f3085f1686a05ef4d1e6
cvtara_app/resources/frontend/calculators/gratuity-calculator-online/index.html cde13477b3b9ff6a7e56f39f3cdb5b9670e104b5f62ec9d55454da9c7439b7e5
cvtara_app/resources/frontend/calculators/hourly-to-salary-finder/index.html c3a3272d2d31583abb5f7f59b5bd6cc0ae42bfa2a1857d888472420032e48033
cvtara_app/resources/frontend/calculators/how-to-calculate-gratuity-amount/index.html 31c0359c89cbdc949a21ea3565b6d8c4f5b2688e3154d2db0065bc246bad0428
cvtara_app/resources/frontend/calculators/leave-encashment-taxable-formula/index.html b568967f7514513fd77043e97e98bec38c3cbb2c69041f21a8d7c057dbec546f
cvtara_app/resources/frontend/calculators/maternity-paternity-leave-pay-calculator/index.html 7f212dba01afedf57a73bead8a9d8ff7351c12d185efc44905b7c2206cc4dd25
cvtara_app/resources/frontend/calculators/mutual-fund-return-calculator/index.html 8992824ea7e40aed8e393c7202a2abfa1e332eb286a21ba08b6cc07c916ef3e9
cvtara_app/resources/frontend/calculators/notice-period-buyout-calculator/index.html d2746675c590ee3d075b23653c3c5b8d1640a06509b4b7e8b1b580e8a2c410bb
cvtara_app/resources/frontend/calculators/overtime-pay-calculator/index.html dc93e7299b7cc80a560e735bd90c912450b3fe50a31b9bd208d59a699de6ccbb
cvtara_app/resources/frontend/calculators/paycheck-tax-deduction-calculator/index.html 6bd1b4bb0d81ff571928b119201698bda08188e1d297991043ebc1333733b75d
cvtara_app/resources/frontend/calculators/remote-work-savings-calculator/index.html 976301045cdbc464c39d40c2fdc5648cd918421761a058800fabd4eef400bf50
cvtara_app/resources/frontend/calculators/resignation-paycheck-estimator/index.html 109bd32cca1a3191b5f5b42707de19446a9ae444d5630627f9ca647eadb72f78
cvtara_app/resources/frontend/calculators/salary-breakup-calculator/index.html 530791087b8b9245fac8e9b11002e3244ab1e328f0776d4c4539180e2a5b5b12
cvtara_app/resources/frontend/calculators/salary-increment-percentage-calculator/index.html ab2d1b52fd2fa61e74f175854c075d37031342489b9523be709035b626ac0c60
cvtara_app/resources/frontend/calculators/salary-slip-format-excel/index.html 130cccc8c6056d2047ef6d84c933e8c59f2aa2bf1c5a851b514aa95335b91812
cvtara_app/resources/frontend/calculators/side-hustle-tax-estimator/index.html d9df76d3e6edd3a5e8c16667aaf01731aea773c3d3e20b2fe88ea0357b81257b
cvtara_app/resources/frontend/calculators/sip-calculator-monthly-income/index.html 00085d098e92890856683542cc54e6b6708e98ff57079b9338616af582cd62ea
cvtara_app/resources/frontend/calculators/step-up-sip-calculator/index.html 7ace8dd9ecb3d485efd8dec7efda2694b5ff96cba7f0a37b1d647b328fafb12b
cvtara_app/resources/frontend/calculators/take-home-salary-calculator/index.html 5c73bc8ac49233722da2cfc6c2d1c2a112726b86c6c621e50258f2bdb24132af
cvtara_app/resources/frontend/checkout.html 616f7265bfe83163ec4f3d7c784f69dc870b8681e2b7539f178d5c9aabfb396a
cvtara_app/resources/frontend/contact.html b2c11faa525a3e6bcf8b09e6c9919aed52999163a63abb4b8fa2b09feb06cba8
cvtara_app/resources/frontend/cover-letters.html 6f20125da514c8c2a730dff52ba418f461705f2349e37b061659fa50c4a3b424
cvtara_app/resources/frontend/guides.html 502791c7257c29ec36f4c597a72aeb7ffba3fc056af92f277804448f234094aa
cvtara_app/resources/frontend/guides/cover-letter/short-email-format/index.html 6315e75e285f7f99642548725e1167c982c16d1f1aa504984c168064db0aa742
cvtara_app/resources/frontend/guides/interview/questions-to-ask-them/index.html 83a2590380c15cc6cb3f46aea269622a08d9d2204b6b4695c3d8cb88dbbccfde
cvtara_app/resources/frontend/guides/interview/salary-expectations-answer/index.html 48665b2bb8a134388ee6567d83131b8c19c4525dc6992cc7d242328aaa1f2b54
cvtara_app/resources/frontend/guides/interview/star-method-examples/index.html 93ac75702404c48d8843068aee00709ef5f6884dc1f71e6fb5bcb7446ce1feb0
cvtara_app/resources/frontend/guides/interview/tell-me-about-yourself/index.html b13356f4ba5cf0896fa5bea73f65883277f0c13de4e120f09d667061301982fa
cvtara_app/resources/frontend/guides/interview/thank-you-email-template/index.html ada86518e4b4332223a52a8c828a5b65519c3421afb60748ae44acb5e907b3ec
cvtara_app/resources/frontend/guides/jobs/application-tracker-method/index.html cfe686f67a9038f34c6c5aa6f0bc3dba514c369cf54360f8b943d2525899d4d5
cvtara_app/resources/frontend/guides/jobs/best-time-to-apply/index.html 259d820aca15beb1fe1ce1efc0561e14d274b9e6fbd256cbfac107c461fc288b
cvtara_app/resources/frontend/guides/jobs/follow-up-email-template/index.html 312dc0232d06a9aafeed27ab0f5fad31222e82e2f58966d75e8ef7a6638e8cb7
cvtara_app/resources/frontend/guides/jobs/how-many-jobs-to-apply/index.html f66230ddd8eda32a4a6eaeec079a9250c431e28a89a79f5e2ab2fe37fa844bff
cvtara_app/resources/frontend/guides/jobs/how-to-reject-job-offer/index.html 850b8a85b51b4c6dc828122bb85805289d2543bf251452fd7c2998616d3ab685
cvtara_app/resources/frontend/guides/jobs/how-to-withdraw-application/index.html 12afe4df8c3333c6a9efca21aa5c01a43c7465e0587d1a57612e326a7581ce0e
cvtara_app/resources/frontend/guides/jobs/job-application-status-meaning/index.html dca24a5661e0fd60fb4cd65ee52fa7324521c36826f33ee171927eeabe5c17f8
cvtara_app/resources/frontend/guides/jobs/linkedin-open-to-work-setting/index.html bb9b25b2bd6b1703248b4d4de7f608a235cc3e2094475b83dbb5b3fda6ec2365
cvtara_app/resources/frontend/guides/jobs/remote-job-scams-signs/index.html c92e171d0de4bb899a6fc248caba4b3598abdf29064423f9e46d2c7d09296fd3
cvtara_app/resources/frontend/guides/jobs/signs-you-will-get-the-job/index.html f3150a8f1a5100cd09b337746f7a9d7c958eb6cbfea0940fd44923e2d5dacaad
cvtara_app/resources/frontend/guides/resume/achievements-bullet-points/index.html 158dbc6fee799f9b6c5042eab40454e5268b359e09904f994f5d377c670605e8
cvtara_app/resources/frontend/guides/resume/action-verbs-list/index.html 5bc713ab35f9b5bb8e7a0ca9d0d806524ba087f04e56e3004050f4171ad00f72
cvtara_app/resources/frontend/guides/resume/ats-friendly-format-india/index.html d9ce1b943a075c09e60c05ebb27bbbc49a0cc60c1fb3ac6249c47abde0f959cd
cvtara_app/resources/frontend/guides/resume/fresher-projects-guide/index.html 6aec61cb5064916ed06a36440b202f735d62424ac176f6a8df55f39eaa8686d1
cvtara_app/resources/frontend/guides/resume/gap-in-employment-explanation/index.html 21c1cb8d81b1d2a2c01251b8d7e8b41ea3e9856118da6286ff9085af2b1c1c3d
cvtara_app/resources/frontend/guides/resume/how-to-write-current-salary/index.html 9557c802ed4da634ace221277e50598ffbd8fb26b722329f7bab645850415e83
cvtara_app/resources/frontend/guides/resume/no-experience-format/index.html e7bd688202e28d5f91968e8e4741c700ca1c4856731bec32a8d821927903a15a
cvtara_app/resources/frontend/guides/resume/pdf-vs-word-ats/index.html 996ae496f3ba2a17167f01b74a54269e4c4416fda621f8caf97e9df5c3d083d5
cvtara_app/resources/frontend/guides/resume/professional-summary-guide/index.html c48ac7d3950474de15aabdee316e2dd981fbf9863bd286fc9824054d2422f958
cvtara_app/resources/frontend/guides/resume/references-available-upon-request/index.html 0836e8a453539864a81465f439a40b89c87bd00af5d69b88065ed78cb52c2c96
cvtara_app/resources/frontend/guides/resume/skills-section-keywords/index.html 2a0cf11a0f2d11ada21453d6d537d82f961f65b93f308c5a4bd2ec3f9bf7f961
cvtara_app/resources/frontend/guides/salary/asking-for-raise-script/index.html 5a83c69f4dee4a44e8339fce702d8d8a28701c5c64291ed07ea94855558d4460
cvtara_app/resources/frontend/guides/salary/benefits-package-value/index.html f422b3ad04afebf5eb491b9a3a76749ff6beec29151e76a20a2adb9f922953de
cvtara_app/resources/frontend/guides/salary/counter-offer-email/index.html f95e3767bf3ea0514c87c3a123e032098072c93565b744658115ed6e6df9f894
cvtara_app/resources/frontend/guides/salary/exempt-vs-nonexempt-pay/index.html 0db6dbd6bd6e6da9162fab40299e57d9d03b767d4ba6e3915fc78e9130ac1075
cvtara_app/resources/frontend/guides/salary/glassdoor-alternatives/index.html 3347340a0d84116d2a7e6d75f2bdcbf32f83729eef8dbfe61f9375d7054d8547
cvtara_app/resources/frontend/guides/salary/is-overtime-taxed-more/index.html 6769e68db5694cdb8a8c0ba58cd450526812eabf61c4a60bb2de61a2f7580a26
cvtara_app/resources/frontend/guides/salary/negotiation-counter-percentage/index.html 4f5814379dd21a4e9137151c58512f353b98971a838896a7713b5c60d14e90ae
cvtara_app/resources/frontend/guides/salary/pay-transparency-laws/index.html ead1cc30d0828e125357140f36475fdcad07fb01a3ff49f3c93d9de8af1706b3
cvtara_app/resources/frontend/guides/salary/what-is-good-starting-salary/index.html db731dd4b6d24123a7d2982a2252c14f60bcccc90acfd1815df4d121189f05a3
cvtara_app/resources/frontend/index.html 3706d83ca54b5e05d83ba7a607692bf533e91d64f776d29da0a4035160e164d0
cvtara_app/resources/frontend/privacy.html b15b4f39ffb721eef479275dd0659f613dd8d03b56e1940dd2484174ed4ed50b
cvtara_app/resources/frontend/refund.html e7795c4c8d94d1726bd8dd5d71eb637ef543e9adfcaef8a7857a4f068b97f089
cvtara_app/resources/frontend/setup.html 60b87153d5284a673f7ab79c6c9372f14df3c0a8357ab48815d68bee50a3d212
cvtara_app/resources/frontend/templates.html 0cdf4532e9d49785fb00b66d1c4323d1f8da5c1080d76b99aedc677588a61d76
cvtara_app/resources/frontend/terms.html e866ef10ea1768a76f948cc0dedeaf18fbe73efa9c47c836d98b85e6300cde80
cvtara_app/resources/frontend/workspace.html 88e98e97419582d96cc3f1945758da2c258b54c56a5abdbfe1f63690f7b16904
cvtara_app/resources/views/seo/page.blade.php 3849051342249d4d39e492dd83cc63ffe0f1f27f24eec6f22e35e903950679de
public_html/OneSignalSDKWorker.js 1dce382f0eb1d49cb8d2036e516bce26d7477e0c053bbd5477f1f6ec8b163847
public_html/connected-ui.css fc26ef698a96c87c2ed3cf831626c3cdcfef23aed13898df1e22c5b3d8492c8c
public_html/connected-ui.js 70605f6fa767c313cd9e525694c254788b2dfe947aa47283450f5c9e7afe9801
public_html/preview.js 35d3a4ff5f4abbdcffb24ce8dc812566ae69c1b659560f2416d17a4680f56d39
public_html/styles.css 732f6930c83437da39ddc9eee3d50ed752e7a7352d9b1e472963ec2625358641
public_html/onesignal-push.js 36faaea877431ed894a3674dfb77fb13f30c345926f6ebdab63450007e562119
LIST
grep -q '"version": *"3.25.1"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null || { echo "MISMATCH live version is not 3.25.1"; bad=1; }
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3252.done; rm -f $W/d3252.lock; exit 1; }
B=$W/backup_3252_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3252.tgz | grep -v '/$' | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) public_html/onesignal-push.js $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3252.done; rm -f $W/d3252.lock; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3252.done; rm -f $W/d3252.lock; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3252 && tar -xzf $W/cvt3252.tgz -C $W/x3252 && cp -a $W/x3252/cvtara_app/. cvtara_app/ && cp -a $W/x3252/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
rm -f public_html/onesignal-push.js
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3252/BUILD_INFO.json $b; done
echo "copied $(find $W/x3252 -type f | wc -l) files"
for f in $(tar -tzf $W/cvt3252.tgz | grep "\.php$"); do php -l $f | head -1; done
cd cvtara_app && php artisan optimize:clear; cd $H
php $W/rebuild3252.php
for u in / /login /contact.html /templates.html /workspace.html /sitemap.xml /OneSignalSDKWorker.js /onesignal-push.js; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "console-ops-anon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/console/ops.js)"
echo "onesignal-in-home $(curl -s https://cvtara.com/ | grep -ci onesignal)"
echo "csp $(curl -sI https://cvtara.com/ | grep -i content-security | grep -ci onesignal)"
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "-- unresolved errors after"; php $W/errors3252.php | head -5
echo "== done $(date -u)"; rm -rf $W/x3252; touch $W/d3252.done; rm -f $W/d3252.lock

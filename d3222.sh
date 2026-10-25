#!/bin/bash
# CVTara 3.22.2 overlay deploy (PR #25), second attempt after a checksum-list fix; approved by the owner 2026-10-25 11:08 UTC. Runs once; later runs print the log.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3222b.log; mkdir -p $W
if [ -f $W/d3222b.done ] || [ -f $W/d3222b.lock ]; then cat $L; exit 0; fi
touch $W/d3222b.lock; exec >>$L 2>&1; echo "== start $(date -u)"
SRC=https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-3-22-2
cd $W && curl -fsSL "$SRC/cvt3222.tgz" -o cvt3222.tgz && curl -fsSL "$SRC/rebuild.php" -o rebuild3222.php && curl -fsSL "$SRC/errors.php" -o errors3222.php || { echo "ABORT download"; touch $W/d3222b.done; exit 1; }
echo "c135797e26c1a5444b492b9dec9475e87921a88fe793337295f70ede3e8a11b1  cvt3222.tgz" | sha256sum -c - || { echo "ABORT tgz checksum"; touch $W/d3222b.done; exit 1; }
echo "-- unresolved errors before"; php $W/errors3222.php
cd $H; bad=0
while read p rest; do cur=$(sha256sum "$p" 2>/dev/null | cut -c1-64); ok=0; for x in $rest; do [ "$cur" = "$x" ] && ok=1; done; [ $ok = 1 ] || { echo "MISMATCH $p $cur"; bad=1; }; done <<'LIST'
cvtara_app/app/Http/Controllers/ContentController.php 67fafcc76105469696735be60015b1bf1f0bfa9631999c4258e869a20d72a2f0 af08891e7f41bab7d94a15a005d0638099b17cb123b75d73fc09ef2f86fd5ad8
cvtara_app/app/Providers/AppServiceProvider.php caf306ef6a25a547bbb7edd5508a39b51c365083eb6dd58378cf2242018e94c7
cvtara_app/bootstrap/app.php 6609142fe31ca4751f3d9ae93800d2873bb83d58e2055d298f74560fd121b9f4 d830087ae4dc2bb9b5e049d32293dd65f04ec63d0db035983c2abcd62b0081fa
cvtara_app/resources/frontend/about.html 21eeb0a3f4d8032c37451120474840ab240c52c459ffa490ca5e64e6f740dcad
cvtara_app/resources/frontend/admin.html d9ab7a5f0d458aaa8b0ed297cf47e736ebf314b26ac755abd136113c8877fc6e
cvtara_app/resources/frontend/article.html a39169d30585fe62c87402f1b956bc75a22eaf3395b0a0cd60239ba994eabef0
cvtara_app/resources/frontend/blog.html f7864f48b555f64e4a67b4d7cb16d8ced905fbcfd8bd2f6e2b00fa4210ac7dfa
cvtara_app/resources/frontend/calculators/average-salary-job-title/index.html ca6c688e330820b23d409ee30217255d8ca0e4da0683e936f807ff2689e2edb6
cvtara_app/resources/frontend/calculators/bonus-net-payout-estimator/index.html 2b581d0ca036d741d1ce46b52c9d1e062932b272f4ad3e236e61865d063ffb18
cvtara_app/resources/frontend/calculators/compensation-package-builder/index.html 631bf6878827948bf9bf4e9bc53916389b04be9305e790313208c8efb5dc9a0a
cvtara_app/resources/frontend/calculators/contractor-vs-full-time-salary-calculator/index.html 55de51263b64794f4e6eb18597bf494866c11cd708fae5fa78b5e55ea14fd274
cvtara_app/resources/frontend/calculators/cost-of-living-calculator-by-city/index.html 7796405e4d351274ccd729db766d8e5b5abae383b80ea0005197868cf5ea999e
cvtara_app/resources/frontend/calculators/ctc-to-in-hand-salary-calculator/index.html eeba950145dffb252842fbcbd320a024d9469d0ed42f8d2cda928217c4293c20
cvtara_app/resources/frontend/calculators/current-salary-in-resume/index.html 1b9c0130385333d70cff9e748d148f69da66a443c2b846cc88672ee8cfc019a6
cvtara_app/resources/frontend/calculators/debt-to-income-career-planner/index.html ef96db8a9a80658c5d4e0873b80a126794a42a4a4d935038d95f26b9afd1e0b0
cvtara_app/resources/frontend/calculators/dual-employment-moonlighting-tax-estimator/index.html 91ca189d2d2018ce6a088cae4acd9bc6570f8fa6b680969428f35f1fed981115
cvtara_app/resources/frontend/calculators/education-loan-calculator-roi/index.html c46cc363c80f83463df3bdf1fed05b076f1554d572428b4ba26eb329fbb2c45a
cvtara_app/resources/frontend/calculators/expat-net-salary-tax-calculator/index.html ed675d1e96bb420e1dc1bfb954115b0ee05dc7bd834a5e356b5f2fa635b506eb
cvtara_app/resources/frontend/calculators/expected-salary-interview-calculator/index.html 16835f09652b4ad4b17797c3cfd6d57df8bf0c40d2483461c8c67611de078b43
cvtara_app/resources/frontend/calculators/fire-career-milestone-tracker/index.html 864efdf59a76a922a3906c5f565bc22c10fa76162af04f498f04e46a8b6a6bdb
cvtara_app/resources/frontend/calculators/gratuity-calculation-formula/index.html ce68068a3412e2cda06c8c14bd2923e31e05555ac53f327147926d75104faa14
cvtara_app/resources/frontend/calculators/gratuity-calculator-online/index.html b4daac1c1815df2532f589ba5ff671dabe7f4f1528eb16f258bcab52c18953a8
cvtara_app/resources/frontend/calculators/hourly-to-salary-finder/index.html 0d23c8a6e9f9ddbb295048fe6357031ef0933075666a01b7510c71330968f8cd
cvtara_app/resources/frontend/calculators/how-to-calculate-gratuity-amount/index.html 9e98bbf1d1a8b7c269fd72a93ee31f7aade823cd361a46cb99a604389db7e843
cvtara_app/resources/frontend/calculators/leave-encashment-taxable-formula/index.html ac560b030696b92765afea87945ec3d55962924f40ad26f52a9c22c692b2ca2f
cvtara_app/resources/frontend/calculators/maternity-paternity-leave-pay-calculator/index.html 1ae37a8ffbc270c7688695fca0792250cb502f37fdfd03847fc2fe8b82016cb3
cvtara_app/resources/frontend/calculators/mutual-fund-return-calculator/index.html 23ccbab6a2140e0a3094c18e3dba0d53ce44bb7dcbc72b20e1edbb117eaf0f2c
cvtara_app/resources/frontend/calculators/notice-period-buyout-calculator/index.html 686957a09d331da8afd1e9477706521129d20299e13ef3943d6b820922d2892a
cvtara_app/resources/frontend/calculators/overtime-pay-calculator/index.html 1c1f79160837f234ae43089d5a0ec4082eff988a6304d2e7c7a33ac580d45d39
cvtara_app/resources/frontend/calculators/paycheck-tax-deduction-calculator/index.html f2d86e8b7477aab2d8e5dae6b964245b22dce6a0ba1cbb297d1ad9b4f338b356
cvtara_app/resources/frontend/calculators/remote-work-savings-calculator/index.html d800df0636ad2daee7b075e6f1416cd7579d3668bd7ea25966a1c5425013fcb9
cvtara_app/resources/frontend/calculators/resignation-paycheck-estimator/index.html 46c1e7e9390703f262e2395c15fdefee50876e26d4f7213afc159b316b58b4ef
cvtara_app/resources/frontend/calculators/salary-breakup-calculator/index.html ab14986ccb6880c6e45cb02ad6f6d700a0d4e01da4b7aee558b9904f15e9a5e3
cvtara_app/resources/frontend/calculators/salary-increment-percentage-calculator/index.html 6231c0851dcb2f6f0f5b66406fd42bdcdb0c6049712604525c6f3c66420a936c
cvtara_app/resources/frontend/calculators/salary-slip-format-excel/index.html 8451615cdc1d3292263c5afa478c99a5dc288f11c2819f9eb5af0e379246472f
cvtara_app/resources/frontend/calculators/side-hustle-tax-estimator/index.html 09218b2b182d952c5af6cb2fa2797dbe131f277868558778d4829319721685fa
cvtara_app/resources/frontend/calculators/sip-calculator-monthly-income/index.html e1135383b0cff80fe55cde8f346a8ff3c63d3074739457f9198df0cdcba7f237
cvtara_app/resources/frontend/calculators/step-up-sip-calculator/index.html 62832f4824c97c960e7d4fd33a7e1ef68dd9f57d08e4f7d16b9d1c63fdd37d6e
cvtara_app/resources/frontend/calculators/take-home-salary-calculator/index.html 6cfc2d4b40a534cfe3453f1134af382835cbacb1b35694ec8b8cc086eb617472
cvtara_app/resources/frontend/checkout.html 5e45f4a21c7e4aba014bce370cc4f6b836dc96ca2843c039f4c7dfb4d73460c7
cvtara_app/resources/frontend/contact.html 7a34c2e526d2a44bbeb927ab91af92987c286207ca3282d3abc60259ae104574
cvtara_app/resources/frontend/cover-letters.html f6e898b0db3513f996073d16cbdd4d4fb5cc02ecccd05fc9be001d546c11d11f
cvtara_app/resources/frontend/guides.html 14fb42858fa691d78ad80157006f027ed4c7eef8ec99e5edeaffa9eddd5dfba7
cvtara_app/resources/frontend/guides/cover-letter/short-email-format/index.html ac7a1f4593b3719c2384619df4e2342ebcd343a265e0e2a19e15aa8c40748b82
cvtara_app/resources/frontend/guides/interview/questions-to-ask-them/index.html 1114f9e25448f84d3779472a96fbd155b71e6969a1de6c77db32517243e0ac75
cvtara_app/resources/frontend/guides/interview/salary-expectations-answer/index.html c53020b3cca7d1b928eccc1d82f81db82a9737a2e7bee943dc0339a72c85460d
cvtara_app/resources/frontend/guides/interview/star-method-examples/index.html 1c655e22005e1175a5ef4785977ce676b614a1a1b5ac6637b4fd6d1fc29a3fcb
cvtara_app/resources/frontend/guides/interview/tell-me-about-yourself/index.html 892f7ab5d1bf2c9338ccea630ac745f821a24cb064b571cba7a042ca71092ed8
cvtara_app/resources/frontend/guides/interview/thank-you-email-template/index.html ef0d24966d9af7615d38f76eb23368851ead61407a4af02c9ab323f85f463859
cvtara_app/resources/frontend/guides/jobs/application-tracker-method/index.html fb40d43c67afaee966d44bb16958a6bc9fa7c983108cdba4b6d81bac2a51b139
cvtara_app/resources/frontend/guides/jobs/best-time-to-apply/index.html dfa0ffedec6dd52a0561fa91df024a050aa75beb182e66e9369a3da4f09b24b1
cvtara_app/resources/frontend/guides/jobs/follow-up-email-template/index.html c96dbe7869f6a7b06f963f7dba0bb0c2bba88796fa78539b9b54896e01f98c5d
cvtara_app/resources/frontend/guides/jobs/how-many-jobs-to-apply/index.html 353c97f55f28143bff8b63fbc6f4c599839866a8d069425436c2d97d99c7e694
cvtara_app/resources/frontend/guides/jobs/how-to-reject-job-offer/index.html b840a9d929232beb0c5fafe91a303c747091523a6a4396396ffedd23846a9f36
cvtara_app/resources/frontend/guides/jobs/how-to-withdraw-application/index.html a7756ccafbb8fec3726a6c9cea1171222f1139860ec3fc650cba7828b74204ae
cvtara_app/resources/frontend/guides/jobs/job-application-status-meaning/index.html 51bcd55d6ed98e77610f5549cd899d8026ef3e82b33938bf2ec2e28db56c2092
cvtara_app/resources/frontend/guides/jobs/linkedin-open-to-work-setting/index.html 635cc59c3c7c5c3ca4d52cf2a71d6146046057968005b743d51514057ce70492
cvtara_app/resources/frontend/guides/jobs/remote-job-scams-signs/index.html e5edf49a6501289cd729083128f8083e3edebf2a0b4940151258769822d9df4a
cvtara_app/resources/frontend/guides/jobs/signs-you-will-get-the-job/index.html ffb20e869f62c92317b541e5de7e1b752713c981ec92c82318ffb8ae6e9f9261
cvtara_app/resources/frontend/guides/resume/achievements-bullet-points/index.html 230fabd92f9384da613e470f5ca82ee313c6b957e8e057fd9fc0d54fa359600f
cvtara_app/resources/frontend/guides/resume/action-verbs-list/index.html e3c43c2be6a5147ade2a9e57fe213c44349a62c7df94aa7e5b14d7caff7997cf
cvtara_app/resources/frontend/guides/resume/ats-friendly-format-india/index.html e180ae9421b6a9b4e7767d979fc2822607e8996bc5d6bc2ecc588564b85f600c
cvtara_app/resources/frontend/guides/resume/fresher-projects-guide/index.html 41a2234eb00d0114291606e81c2e23bd1ebaad4f56c30ba05a1c21647857b05d
cvtara_app/resources/frontend/guides/resume/gap-in-employment-explanation/index.html a3d605204f237cd4ace937d26771462085ef8c6343a800780847bf6ed37e3989
cvtara_app/resources/frontend/guides/resume/how-to-write-current-salary/index.html 8de61a67318954985c4bb218928d6475f96a7d6c6417057a041214da22e35b41
cvtara_app/resources/frontend/guides/resume/no-experience-format/index.html ffe772071a7f06fa79767fc9c88df218eb3fce956d163edee0efb43294ae0b1d
cvtara_app/resources/frontend/guides/resume/pdf-vs-word-ats/index.html 7a7ced9765baca24d9f3c7af05c7732afc61f738b957260628bbce622c0c073d
cvtara_app/resources/frontend/guides/resume/professional-summary-guide/index.html 77b848babea0e5821b6ee5ec2cce224521052f5e5245b06bf5b3eb71450821b0
cvtara_app/resources/frontend/guides/resume/references-available-upon-request/index.html 4eae7a85fce0f6b7a603390bdd34033d2d9401285c5df950fad5f26de0aa9302
cvtara_app/resources/frontend/guides/resume/skills-section-keywords/index.html 924babb9b52c5d882f7c1297de7b13ce0b66648bb2b872261b4376b4f6da0a14
cvtara_app/resources/frontend/guides/salary/asking-for-raise-script/index.html 696cf34a39bcb3766dc6c9854363862e02507e0bb41441b77db4e87718c8698c
cvtara_app/resources/frontend/guides/salary/benefits-package-value/index.html 55056e268548f017f54e9dd11954cba5ff58523bdbeee8466f02a35398049544
cvtara_app/resources/frontend/guides/salary/counter-offer-email/index.html beb375343d5a63ee3a3ef1b958f007b9b7c6a5c3a02bc8d7bc2d578c0514fd26
cvtara_app/resources/frontend/guides/salary/exempt-vs-nonexempt-pay/index.html 63561027816a365f1a0154283ea39fe4c49cd733e785a5e74664956dbbb399a7
cvtara_app/resources/frontend/guides/salary/glassdoor-alternatives/index.html 586df04740c6230f3e7a94008d797ea2a3a855e2f887a2b280607e1828d79f96
cvtara_app/resources/frontend/guides/salary/is-overtime-taxed-more/index.html 8a22477a2f5a8dd7ee6a8afec104a22fd2d512e3aab45a16e2289c364463ee52
cvtara_app/resources/frontend/guides/salary/negotiation-counter-percentage/index.html 0e43baa17e77424243d32fed11799263cbcf6896ee8dd7b44ba06651dd35bb1d
cvtara_app/resources/frontend/guides/salary/pay-transparency-laws/index.html 9efe6ed88f6db28af8f9bd269a5bd339d0f5af3c715949e2a92d44ad0ea3fc12
cvtara_app/resources/frontend/guides/salary/what-is-good-starting-salary/index.html 27684a739670425a09c567bdf62701b43044aa60343983919b3d7502b80bfb7e
cvtara_app/resources/frontend/index.html 3f8504fb823e56ac6c76840f3b6c9a489515be7686d467e950c4d20b0273ed91 e39aa071ed05c627c7927dfb0f405c0c45817c625b72054e323b6cf7443aefe2
cvtara_app/resources/frontend/privacy.html 99087c38083191f1d27dbe878ce6e3422648978e55b1f8285b2c993391413024
cvtara_app/resources/frontend/refund.html 1919b923b46035dd780ed42226d9802e941932cfe49cf0527513fcbd7d6d733f c2347a6bd9ed45aefa88ba780e7beda608ab7ba41274d6aa24385049b6ad7164
cvtara_app/resources/frontend/setup.html 26d909bd9af328729245a86e6608d9d8d140c5a58035139b70121d12e9a126ad
cvtara_app/resources/frontend/templates.html 66a30e5b4acbbc3d03e9286d34bea70f9c2c066f799b7d96c57caab00655475c
cvtara_app/resources/frontend/terms.html 5dcaa61099f697890fc52fc127cb0243a4daaa1616e2f2423a7805ba56f630b4
cvtara_app/resources/frontend/workspace.html 30887e832ec29b024efb2d7f747ac0112fe738e5051c3c0be79bf40b68f9e046 9d9690da2c18fa5da741a20817680cd4184279b65077ae50dd89b8af924fe2af
cvtara_app/routes/web.php fdde165aa4ce742ee2ff9844a6cad8518f866d0c3ed3038b16057bbc325ec7cf
public_html/owner-advanced.js 33f4e6de2c6d28f6b095cbcfc2b97c78d4b43bd0da8df163078843fc9d61e064 35be7f6b7d7e591fa9c10190fe59ff77d800281528db27e1fbb8abef424d5ed4
public_html/owner-console.js 09665bb3c3b89d0d1eb6c6ffb2b219d09fc6dd50353c76dbe1a9798bc187aed4 3d5c4f87e73fe8641efa6faf5276743756f888d5b6cdfc48f3b69e33e171fae4
public_html/owner-public.js de2dfa78075adad6bab53bb32c598619d6a800b6b0270de341d68105eba06ad4 f2dbba455b847169adff7ce066319c98e054dc9f55d601293ea3f2130df7efff
LIST
[ $bad = 0 ] || { echo "ABORT live files differ from the expected base; nothing changed"; touch $W/d3222b.done; exit 1; }
B=$W/backup_3222_$(date -u +%Y%m%d_%H%M); mkdir -p $B
tar -czf $B/files.tgz $(tar -tzf $W/cvt3222.tgz | grep -v '^BUILD_INFO.json$' | while read f; do [ -f "$f" ] && echo "$f"; done) public_html/owner-advanced.js public_html/owner-console.js $(ls BUILD_INFO.json cvtara_app/BUILD_INFO.json 2>/dev/null) && tar -tzf $B/files.tgz | wc -l || { echo "ABORT files backup"; touch $W/d3222b.done; exit 1; }
ev(){ grep -E "^$1=" cvtara_app/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(ev DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces -h "$(ev DB_HOST)" -u "$(ev DB_USERNAME)" "$(ev DB_DATABASE)" | gzip > $B/database.sql.gz
[ "${PIPESTATUS[0]}" = 0 ] && [ $(stat -c%s $B/database.sql.gz) -gt 10000 ] || { echo "ABORT database backup"; touch $W/d3222b.done; exit 1; }
echo "backup ok $B $(stat -c%s $B/database.sql.gz) bytes"
mkdir -p $W/x3222 && tar -xzf $W/cvt3222.tgz -C $W/x3222 && cp -a $W/x3222/cvtara_app/. cvtara_app/ && cp -a $W/x3222/public_html/. public_html/ || { echo "COPY FAILED, restore from $B/files.tgz"; }
for b in BUILD_INFO.json cvtara_app/BUILD_INFO.json; do [ -f $b ] && cp $W/x3222/BUILD_INFO.json $b; done
rm -f public_html/owner-advanced.js public_html/owner-console.js 
echo "copied $(find $W/x3222 -type f | wc -l) files; removed public owner scripts"
cd cvtara_app && php artisan optimize:clear; cd $H
php $W/rebuild3222.php
for u in / /login /templates.html /owner-console.js /console/owner.js; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
grep -o '"version": *"[^"]*"' cvtara_app/BUILD_INFO.json BUILD_INFO.json 2>/dev/null
echo "== done $(date -u)"; rm -rf $W/x3222; touch $W/d3222b.done; rm -f $W/d3222b.lock

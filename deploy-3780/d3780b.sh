#!/bin/bash
# CVTara 3.78.0 follow-up: copy the public_html files of the 3.76.0 and 3.78.0 packages (the main scripts only copied cvtara_app). Runs once.
H=/home/u280039231/domains/cvtara.com; W=/home/u280039231/cvtara_deploy; L=$W/d3780b.log
if [ -f $W/d3780b.done ] || [ -f $W/d3780b.lock ]; then tail -c 4000 $L; exit 0; fi
touch $W/d3780b.lock; exec >>$L 2>&1; echo "== start $(date -u)"
cd $W
echo "d4387dff26b12915b283b974ea2d9f0898c3dfa118eddc6a819ddc2fe859d99d  cvt3760.tgz" | sha256sum -c - || { echo "ABORT 3760 tgz"; touch $W/d3780b.done; exit 1; }
echo "c1e55abc33e482de735fdf3b0d2d6e79139bd511cf1993b3cddd03583adc026a  cvt3780.tgz" | sha256sum -c - || { echo "ABORT 3780 tgz"; touch $W/d3780b.done; exit 1; }
grep -q '"3.78.0"' $H/cvtara_app/BUILD_INFO.json || { echo "ABORT live is not 3.78.0"; touch $W/d3780b.done; exit 1; }
ls -d backup_3760_* backup_3780_* || { echo "ABORT backups missing"; touch $W/d3780b.done; exit 1; }
rm -rf $W/pb && mkdir -p $W/pb && tar -xzf cvt3760.tgz -C $W/pb ./public_html && tar -xzf cvt3780.tgz -C $W/pb ./public_html || { echo "ABORT extract"; touch $W/d3780b.done; exit 1; }
find $W/pb/public_html -type f | sed "s#$W/pb/##"
cp -a $W/pb/public_html/. $H/public_html/ || echo "COPY FAILED public, restore from backup_3760_*/files.tgz and backup_3780_*/files.tgz"
cd $H && for f in upgrade-prompt.js checkout-options.js home-ui.js seo-pages.css dash-v4.css tara-chat.js tara-chat.css assets/brand/cvtara-icon.webp; do echo "$f $(sha256sum public_html/$f | cut -c1-16)"; done
php $W/rebuild3780.php
echo "dashboard rule: $(grep -c '#view-dashboard.active{display:flex' public_html/dash-v4.css) tara logo: $(grep -c cvtara-icon.webp public_html/tara-chat.js) icon $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com/assets/brand/cvtara-icon.webp)"
for u in / /pricing/ /checkout.html /workspace.html; do echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://cvtara.com$u)"; done
echo "== done $(date -u)"; rm -rf $W/pb; touch $W/d3780b.done; rm -f $W/d3780b.lock

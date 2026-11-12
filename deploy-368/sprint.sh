#!/bin/bash
# Owner said "You can start from here" (2026-11-12): start the 30-day SEO sprint once. Later runs print the log.
W=/home/u280039231/cvtara_deploy; L=$W/sprint.log
if [ -f $W/sprint.done ]; then cat $L; exit 0; fi
touch $W/sprint.done
cd /home/u280039231/domains/cvtara.com/cvtara_app && /usr/bin/php artisan tinker --execute='$s = App\Services\SeoSprint::active() ? App\Services\SeoSprint::status() : App\Services\SeoSprint::start(); echo json_encode(["active"=>$s["active"],"day"=>$s["day"],"until"=>$s["until"],"now"=>$s["limits"]["now"],"usual"=>$s["limits"]["usual"],"week"=>$s["week"]["title"] ?? null]);' > $L 2>&1
cat $L

#!/bin/bash
# Switch customer Tara chat on (owner asked for it to show, 6 Nov 2026). Only if the AI key is set up. Runs once; later runs print the result.
W=/home/u280039231/cvtara_deploy; L=$W/taraon.log
if [ -f $W/taraon.done ]; then tail -n 1 $L; exit 0; fi
touch $W/taraon.done
cd /home/u280039231/domains/cvtara.com/cvtara_app && php artisan tinker --execute='if (App\Services\TaraAI::enabled()) { App\Services\Settings::put("tara", array_merge(App\Services\TaraChat::settings(), ["enabled" => true])); App\Services\Settings::audit(null, "tara_settings_saved", "enabled via release 3.33.0 (owner request)"); } echo "RESULT ai=".json_encode(App\Services\TaraAI::enabled())." tara=".json_encode(App\Services\TaraChat::enabled())." greet=".json_encode(App\Services\TaraChat::settings()["greet"]);' > $L 2>&1
tail -n 1 $L

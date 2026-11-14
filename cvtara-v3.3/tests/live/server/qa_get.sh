#!/bin/bash
# Usage from the temporary cron: bash ~/q.sh <commit-sha> <setup|logs|cleanup> [since] [run-id]
S="$1"; A="$2"; D=$HOME/cvtara_deploy/qa; mkdir -p "$D"
B="https://raw.githubusercontent.com/idealsone/domain_flipping/$S/cvtara-v3.3/tests/live/server"
for f in qa_boot.php "qa_$A.php" qa_run.sh; do curl -fsSL -o "$D/$f" "$B/$f" || { echo "fetch failed $f"; exit 1; }; done
/bin/bash "$D/qa_run.sh" "$A" "$3" "$4"
